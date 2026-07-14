<?php

namespace App\Services\F1;

/**
 * Downsamples high-Hz OpenF1 telemetry to a target samples-per-second rate.
 */
class F1Downsample
{
    /**
     * Keep roughly one sample per interval (1 / hz seconds) per series key.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function byHz(array $rows, float $hz, string $dateKey = 'date', ?string $groupKey = 'driver_number'): array
    {
        if ($hz <= 0 || $rows === []) {
            return [];
        }

        $minGapMs = (int) max(1, round(1000 / $hz));
        $lastKeptMs = [];
        $out = [];

        usort($rows, static function (array $a, array $b) use ($dateKey): int {
            return strcmp((string) ($a[$dateKey] ?? ''), (string) ($b[$dateKey] ?? ''));
        });

        foreach ($rows as $row) {
            $date = (string) ($row[$dateKey] ?? '');
            if ($date === '') {
                continue;
            }

            $ms = $this->toEpochMs($date);
            if ($ms === null) {
                continue;
            }

            $group = $groupKey === null ? '_' : (string) ($row[$groupKey] ?? '_');
            $prev = $lastKeptMs[$group] ?? null;

            if ($prev !== null && ($ms - $prev) < $minGapMs) {
                continue;
            }

            $lastKeptMs[$group] = $ms;
            $out[] = $row;
        }

        return $out;
    }

    private function toEpochMs(string $date): ?int
    {
        try {
            $dt = new \DateTimeImmutable($date);

            return (int) $dt->format('U') * 1000 + (int) $dt->format('v');
        } catch (\Throwable) {
            return null;
        }
    }
}
