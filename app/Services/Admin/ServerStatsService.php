<?php

namespace App\Services\Admin;

class ServerStatsService
{
    /**
     * Snapshot of what the API PHP process / container can see.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'laravel_env' => (string) config('app.env'),
            'queue_connection' => (string) config('queue.default'),
            'memory' => $this->memory(),
            'load' => $this->loadAverage(),
            'cpu_percent' => $this->cpuPercentApproximate(),
            'disk' => $this->disk(),
            'uptime_seconds' => $this->uptimeSeconds(),
            'sampled_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Prefer explicit container memory limits; otherwise report host/server RAM.
     *
     * @return array<string, mixed>
     */
    private function memory(): array
    {
        $limit = ini_get('memory_limit');
        $cgroup = $this->readCgroupMemory();
        $proc = $this->readMeminfo();

        $total = null;
        $used = null;
        $available = null;
        $source = 'php';

        if ($cgroup['limit'] !== null) {
            // Docker/K8s memory limit set — report this container's budget.
            $total = $cgroup['limit'];
            $used = $cgroup['usage'];
            $source = 'cgroup';
            if ($total !== null && $used !== null) {
                $available = max(0, $total - $used);
            }
        } elseif (isset($proc['total'])) {
            // No container cap (bare metal, or Docker without mem_limit) — server RAM.
            $total = $proc['total'];
            if (isset($proc['available'])) {
                $available = $proc['available'];
                $used = max(0, $proc['total'] - $proc['available']);
            }
            $source = 'host';
        }

        $usedPercent = null;
        if ($total !== null && $total > 0 && $used !== null) {
            $usedPercent = round(($used / $total) * 100, 1);
        }

        return [
            'php_usage_bytes' => memory_get_usage(true),
            'php_peak_bytes' => memory_get_peak_usage(true),
            'php_limit' => is_string($limit) ? $limit : null,
            'source' => $source,
            'system_total_bytes' => $total,
            'system_used_bytes' => $used,
            'system_available_bytes' => $available,
            'system_used_percent' => $usedPercent,
        ];
    }

    /**
     * Prefer the root filesystem ("/") — on a VPS that is the server disk.
     * Fall back to app paths only if "/" is unreadable.
     *
     * @return array<string, mixed>
     */
    private function disk(): array
    {
        foreach (array_unique(['/', base_path(), storage_path()]) as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            if ((! is_int($total) && ! is_float($total)) || (! is_int($free) && ! is_float($free)) || $total <= 0) {
                continue;
            }

            // Skip tiny tmpfs/ramdisks when a real disk is available later in the list.
            if ($path === '/' && $total < 512 * 1024 * 1024) {
                continue;
            }

            return [
                'path' => $path,
                'total_bytes' => (int) $total,
                'free_bytes' => (int) $free,
                'used_bytes' => (int) max(0, $total - $free),
                'used_percent' => $this->usedPercent($total, $free),
            ];
        }

        return [
            'path' => base_path(),
            'total_bytes' => null,
            'free_bytes' => null,
            'used_bytes' => null,
            'used_percent' => null,
        ];
    }

    /**
     * @return array{limit: ?int, usage: ?int}
     */
    private function readCgroupMemory(): array
    {
        // cgroup v2
        $max = $this->readCgroupValue([
            '/sys/fs/cgroup/memory.max',
            '/sys/fs/cgroup/memory/memory.max',
        ]);
        $current = $this->readCgroupValue([
            '/sys/fs/cgroup/memory.current',
            '/sys/fs/cgroup/memory/memory.current',
        ]);

        // cgroup v1
        if ($max === null) {
            $max = $this->readCgroupValue([
                '/sys/fs/cgroup/memory/memory.limit_in_bytes',
            ]);
        }
        if ($current === null) {
            $current = $this->readCgroupValue([
                '/sys/fs/cgroup/memory/memory.usage_in_bytes',
            ]);
        }

        // "max" or absurdly large limits mean no container cap — treat as unset.
        if ($max !== null && ($max >= PHP_INT_MAX || $max > 1 << 60 || $max <= 0)) {
            $max = null;
        }

        return [
            'limit' => $max,
            'usage' => $current,
        ];
    }

    /**
     * @param  list<string>  $paths
     */
    private function readCgroupValue(array $paths): ?int
    {
        foreach ($paths as $path) {
            if (! is_readable($path)) {
                continue;
            }
            $raw = trim((string) @file_get_contents($path));
            if ($raw === '' || strtolower($raw) === 'max') {
                continue;
            }
            if (! ctype_digit($raw) && ! preg_match('/^-?\d+$/', $raw)) {
                continue;
            }
            $value = (int) $raw;
            if ($value < 0) {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * @return array{total?: int, available?: int}
     */
    private function readMeminfo(): array
    {
        if (! is_readable('/proc/meminfo')) {
            return [];
        }

        $raw = @file_get_contents('/proc/meminfo');
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $total = null;
        $available = null;
        if (preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $raw, $m)) {
            $total = (int) $m[1] * 1024;
        }
        if (preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $raw, $m)) {
            $available = (int) $m[1] * 1024;
        }

        return array_filter([
            'total' => $total,
            'available' => $available,
        ], fn ($v) => $v !== null);
    }

    /**
     * @return list<float>|null
     */
    private function loadAverage(): ?array
    {
        if (! function_exists('sys_getloadavg')) {
            return null;
        }

        $load = @sys_getloadavg();
        if (! is_array($load) || count($load) < 3) {
            return null;
        }

        return [
            round((float) $load[0], 2),
            round((float) $load[1], 2),
            round((float) $load[2], 2),
        ];
    }

    /**
     * Best-effort CPU busy % via a short /proc/stat sample. Returns null if unavailable.
     */
    private function cpuPercentApproximate(): ?float
    {
        if (! is_readable('/proc/stat')) {
            return null;
        }

        $a = $this->readProcStat();
        if ($a === null) {
            return null;
        }

        usleep(200_000);
        $b = $this->readProcStat();
        if ($b === null) {
            return null;
        }

        $idleDelta = $b['idle'] - $a['idle'];
        $totalDelta = $b['total'] - $a['total'];
        if ($totalDelta <= 0) {
            return null;
        }

        return round((1 - ($idleDelta / $totalDelta)) * 100, 1);
    }

    /**
     * @return array{idle: int, total: int}|null
     */
    private function readProcStat(): ?array
    {
        $line = @file('/proc/stat', FILE_IGNORE_NEW_LINES);
        if (! is_array($line) || $line === [] || ! str_starts_with((string) $line[0], 'cpu ')) {
            return null;
        }

        $parts = preg_split('/\s+/', trim((string) $line[0]));
        if (! is_array($parts) || count($parts) < 5) {
            return null;
        }

        $nums = array_map('intval', array_slice($parts, 1));
        $idle = ($nums[3] ?? 0) + ($nums[4] ?? 0);
        $total = array_sum($nums);

        return ['idle' => $idle, 'total' => $total];
    }

    private function uptimeSeconds(): ?int
    {
        if (! is_readable('/proc/uptime')) {
            return null;
        }

        $raw = @file_get_contents('/proc/uptime');
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $parts = explode(' ', trim($raw));

        return isset($parts[0]) ? (int) floor((float) $parts[0]) : null;
    }

    private function usedPercent(mixed $total, mixed $free): ?float
    {
        if ((! is_int($total) && ! is_float($total)) || (! is_int($free) && ! is_float($free))) {
            return null;
        }
        if ($total <= 0) {
            return null;
        }

        return round((($total - $free) / $total) * 100, 1);
    }
}
