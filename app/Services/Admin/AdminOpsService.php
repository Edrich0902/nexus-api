<?php

namespace App\Services\Admin;

use App\Integrations\OpenF1\OpenF1ProviderHealth;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminOpsService
{
    public function __construct(
        private readonly ServerStatsService $serverStats,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return [
            'queues' => $this->queueSummary(),
            'failed_job_count' => $this->failedJobCount(),
            'providers' => [
                'openf1' => OpenF1ProviderHealth::snapshot(),
            ],
            'server' => $this->serverStats->snapshot(),
            'telescope_url' => rtrim((string) config('app.url'), '/').'/'.trim((string) config('telescope.path', 'telescope'), '/'),
            'sampled_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<array{queue: string, pending: int, reserved: int, delayed: int}>
     */
    public function queueSummary(): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        $now = now()->getTimestamp();
        $rows = DB::table('jobs')
            ->select('queue')
            ->selectRaw('COUNT(*) as pending')
            ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) as reserved')
            ->selectRaw('SUM(CASE WHEN available_at > ? THEN 1 ELSE 0 END) as delayed_count', [$now])
            ->groupBy('queue')
            ->orderBy('queue')
            ->get();

        return $rows->map(fn ($row) => [
            'queue' => (string) $row->queue,
            'pending' => (int) $row->pending,
            'reserved' => (int) $row->reserved,
            'delayed' => (int) $row->delayed_count,
        ])->values()->all();
    }

    public function failedJobCount(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return (int) DB::table('failed_jobs')->count();
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function pendingJobs(int $perPage = 25, ?string $queue = null): LengthAwarePaginator
    {
        $query = DB::table('jobs')->orderByDesc('id');
        if ($queue !== null && $queue !== '') {
            $query->where('queue', $queue);
        }

        return $query->paginate($perPage)->through(fn ($row) => [
            'id' => (int) $row->id,
            'queue' => (string) $row->queue,
            'job_class' => $this->jobClassFromPayload((string) $row->payload),
            'attempts' => (int) $row->attempts,
            'reserved_at' => $row->reserved_at ? date('c', (int) $row->reserved_at) : null,
            'available_at' => date('c', (int) $row->available_at),
            'created_at' => date('c', (int) $row->created_at),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function failedJobs(int $perPage = 25): LengthAwarePaginator
    {
        return DB::table('failed_jobs')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn ($row) => [
                'id' => (int) $row->id,
                'uuid' => (string) $row->uuid,
                'connection' => (string) $row->connection,
                'queue' => (string) $row->queue,
                'job_class' => $this->jobClassFromPayload((string) $row->payload),
                'exception_summary' => Str::limit(trim(strtok((string) $row->exception, "\n") ?: (string) $row->exception), 240),
                'failed_at' => $row->failed_at,
            ]);
    }

    public function retryFailedJob(string $uuid): void
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
    }

    public function forgetFailedJob(string $uuid): void
    {
        Artisan::call('queue:forget', ['id' => $uuid]);
    }

    /**
     * Recent job entries from Telescope when available.
     *
     * @return list<array<string, mixed>>
     */
    public function recentJobs(int $limit = 40): array
    {
        if (! Schema::hasTable('telescope_entries')) {
            return [];
        }

        $rows = DB::table('telescope_entries')
            ->where('type', 'job')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['uuid', 'content', 'created_at']);

        return $rows->map(function ($row) {
            $content = json_decode((string) $row->content, true);
            if (! is_array($content)) {
                $content = [];
            }

            return [
                'uuid' => (string) $row->uuid,
                'job_class' => (string) ($content['name'] ?? $content['job'] ?? 'unknown'),
                'queue' => isset($content['queue']) ? (string) $content['queue'] : null,
                'status' => isset($content['status']) ? (string) $content['status'] : null,
                'created_at' => (string) $row->created_at,
            ];
        })->values()->all();
    }

    private function jobClassFromPayload(string $payload): string
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return 'unknown';
        }

        $display = $decoded['displayName'] ?? null;
        if (is_string($display) && $display !== '') {
            return $display;
        }

        $command = $decoded['data']['commandName'] ?? null;
        if (is_string($command) && $command !== '') {
            return $command;
        }

        $raw = $decoded['data']['command'] ?? null;
        if (is_string($raw) && $raw !== '') {
            if (preg_match('/O:\\d+:"([^"]+)"/', $raw, $m)) {
                return $m[1];
            }
        }

        return 'unknown';
    }
}
