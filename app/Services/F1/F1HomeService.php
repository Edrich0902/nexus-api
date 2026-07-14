<?php

namespace App\Services\F1;

use App\Models\F1\F1ChampionshipDriver;
use App\Models\F1\F1ChampionshipTeam;
use App\Models\F1\F1Driver;
use App\Models\F1\F1HomeSnapshot;
use App\Models\F1\F1Meeting;
use App\Models\F1\F1Session;
use App\Models\F1\F1SyncRun;
use Illuminate\Support\Carbon;

class F1HomeService
{
    public function getSnapshot(): array
    {
        $row = F1HomeSnapshot::query()->where('key', 'default')->first();

        if ($row === null) {
            return $this->rebuild();
        }

        $payload = $row->payload ?? [];

        return array_merge($payload, [
            'computed_at' => $row->computed_at?->toIso8601String(),
        ]);
    }

    public function rebuild(): array
    {
        $year = (int) now('UTC')->year;
        $now = Carbon::now('UTC');

        $nextMeeting = F1Meeting::query()
            ->where('year', $year)
            ->where('is_cancelled', false)
            ->where(function ($q) use ($now) {
                $q->whereNull('date_end')
                    ->orWhere('date_end', '>=', $now);
            })
            ->orderBy('date_start')
            ->first();

        $lastMeeting = F1Meeting::query()
            ->where('year', $year)
            ->where('is_cancelled', false)
            ->whereNotNull('date_end')
            ->where('date_end', '<', $now)
            ->orderByDesc('date_end')
            ->first();

        $standingsSessionKey = F1ChampionshipDriver::query()
            ->where('year', $year)
            ->max('session_key');

        $drivers = [];
        $teams = [];

        if ($standingsSessionKey) {
            $driverMeta = $this->driverMetaForSession((int) $standingsSessionKey);

            $drivers = F1ChampionshipDriver::query()
                ->where('session_key', $standingsSessionKey)
                ->orderBy('position_current')
                ->limit(10)
                ->get()
                ->map(fn (F1ChampionshipDriver $row) => [
                    'driver_number' => $row->driver_number,
                    'position' => $row->position_current,
                    'points' => $row->points_current,
                    'name' => $driverMeta[$row->driver_number]['name'] ?? null,
                    'team_name' => $driverMeta[$row->driver_number]['team_name'] ?? null,
                    'team_colour' => $driverMeta[$row->driver_number]['team_colour'] ?? null,
                    'name_acronym' => $driverMeta[$row->driver_number]['name_acronym'] ?? null,
                ])
                ->values()
                ->all();

            $teams = F1ChampionshipTeam::query()
                ->where('session_key', $standingsSessionKey)
                ->orderBy('position_current')
                ->limit(10)
                ->get()
                ->map(fn (F1ChampionshipTeam $row) => [
                    'team_name' => $row->team_name,
                    'position' => $row->position_current,
                    'points' => $row->points_current,
                ])
                ->values()
                ->all();
        }

        $lastSync = F1SyncRun::query()
            ->where('status', F1SyncRun::STATUS_OK)
            ->orderByDesc('finished_at')
            ->first();

        $payload = [
            'year' => $year,
            'next_meeting' => $nextMeeting ? $this->mapMeeting($nextMeeting) : null,
            'last_meeting' => $lastMeeting ? $this->mapMeeting($lastMeeting) : null,
            'standings_drivers_top' => $drivers,
            'standings_teams_top' => $teams,
            'meeting_count' => F1Meeting::query()->where('year', $year)->count(),
            'session_count' => F1Session::query()->where('year', $year)->count(),
            'last_sync_at' => $lastSync?->finished_at?->toIso8601String(),
            'live_tracking' => false,
            'note' => 'Historical OpenF1 data only. Live race tracking requires a paid OpenF1 subscription.',
        ];

        F1HomeSnapshot::query()->updateOrCreate(
            ['key' => 'default'],
            [
                'payload' => $payload,
                'computed_at' => now(),
            ],
        );

        return array_merge($payload, [
            'computed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{meeting_key: int, meeting_name: string, circuit_short_name: ?string, country_name: ?string, country_flag: ?string, circuit_image: ?string, location: ?string, date_start: ?string, date_end: ?string, year: int}
     */
    private function mapMeeting(F1Meeting $meeting): array
    {
        return [
            'meeting_key' => $meeting->meeting_key,
            'meeting_name' => $meeting->meeting_name,
            'circuit_short_name' => $meeting->circuit_short_name,
            'country_name' => $meeting->country_name,
            'country_flag' => $meeting->country_flag,
            'circuit_image' => $meeting->circuit_image,
            'location' => $meeting->location,
            'date_start' => $meeting->date_start?->toIso8601String(),
            'date_end' => $meeting->date_end?->toIso8601String(),
            'year' => $meeting->year,
        ];
    }

    /**
     * @return array<int, array{name: ?string, team_name: ?string, team_colour: ?string, name_acronym: ?string}>
     */
    private function driverMetaForSession(int $sessionKey): array
    {
        $meta = [];

        F1Driver::query()
            ->where('session_key', $sessionKey)
            ->get()
            ->each(function (F1Driver $driver) use (&$meta): void {
                $meta[$driver->driver_number] = [
                    'name' => $driver->full_name ?? $driver->broadcast_name,
                    'team_name' => $driver->team_name,
                    'team_colour' => $driver->team_colour,
                    'name_acronym' => $driver->name_acronym,
                ];
            });

        if ($meta !== []) {
            return $meta;
        }

        // Fall back to any driver row for the same meeting.
        $session = F1Session::query()->where('session_key', $sessionKey)->first();
        if ($session === null) {
            return [];
        }

        F1Driver::query()
            ->where('meeting_key', $session->meeting_key)
            ->orderByDesc('session_key')
            ->get()
            ->each(function (F1Driver $driver) use (&$meta): void {
                if (isset($meta[$driver->driver_number])) {
                    return;
                }
                $meta[$driver->driver_number] = [
                    'name' => $driver->full_name ?? $driver->broadcast_name,
                    'team_name' => $driver->team_name,
                    'team_colour' => $driver->team_colour,
                    'name_acronym' => $driver->name_acronym,
                ];
            });

        return $meta;
    }
}
