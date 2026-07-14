<?php

namespace Tests\Unit\Services\F1;

use App\Services\F1\F1Downsample;
use PHPUnit\Framework\TestCase;

class F1DownsampleTest extends TestCase
{
    public function test_downsamples_to_about_one_hz_per_driver(): void
    {
        $rows = [];
        $base = strtotime('2024-01-01T12:00:00Z');

        for ($i = 0; $i < 20; $i++) {
            $rows[] = [
                'driver_number' => 1,
                'date' => gmdate('Y-m-d\TH:i:s.000\Z', $base + (int) floor($i / 4)),
                'x' => $i,
                'y' => $i,
            ];
        }

        $kept = (new F1Downsample)->byHz($rows, 1.0);

        $this->assertLessThan(count($rows), count($kept));
        $this->assertGreaterThanOrEqual(4, count($kept));
        $this->assertLessThanOrEqual(6, count($kept));
    }

    public function test_groups_by_driver(): void
    {
        $rows = [
            ['driver_number' => 1, 'date' => '2024-01-01T12:00:00.000Z', 'x' => 1],
            ['driver_number' => 2, 'date' => '2024-01-01T12:00:00.000Z', 'x' => 2],
            ['driver_number' => 1, 'date' => '2024-01-01T12:00:00.200Z', 'x' => 3],
            ['driver_number' => 2, 'date' => '2024-01-01T12:00:00.200Z', 'x' => 4],
            ['driver_number' => 1, 'date' => '2024-01-01T12:00:01.000Z', 'x' => 5],
            ['driver_number' => 2, 'date' => '2024-01-01T12:00:01.000Z', 'x' => 6],
        ];

        $kept = (new F1Downsample)->byHz($rows, 1.0);

        $this->assertCount(4, $kept);
    }
}
