<?php

namespace App\Console\Commands;

use App\Jobs\Sports\RebuildSportsHomeSnapshotJob;
use App\Jobs\Sports\SyncFootballStandingsJob;
use App\Jobs\Sports\SyncSportsDayJob;
use App\Jobs\Sports\SyncSportsFixturesJob;
use App\Jobs\Sports\SyncSportsLeaguesJob;
use Illuminate\Console\Command;

class SportsSyncCommand extends Command
{
    protected $signature = 'sports:sync {--type=all : all|fixtures|day|standings|leagues|home}';

    protected $description = 'Dispatch SportsDB sync jobs';

    public function handle(): int
    {
        $type = (string) $this->option('type');

        $allowed = ['all', 'fixtures', 'day', 'standings', 'leagues', 'home'];
        if (! in_array($type, $allowed, true)) {
            $this->error('Invalid type. Use: '.implode(', ', $allowed));

            return self::FAILURE;
        }

        match ($type) {
            'leagues' => SyncSportsLeaguesJob::dispatch(),
            'fixtures' => SyncSportsFixturesJob::dispatch(),
            'day' => SyncSportsDayJob::dispatch(),
            'standings' => SyncFootballStandingsJob::dispatch(),
            'home' => RebuildSportsHomeSnapshotJob::dispatch(),
            default => tap(null, function (): void {
                SyncSportsLeaguesJob::dispatch();
                SyncSportsFixturesJob::dispatch();
                SyncSportsDayJob::dispatch();
                SyncFootballStandingsJob::dispatch();
            }),
        };

        $this->info("Dispatched sports {$type} sync.");

        return self::SUCCESS;
    }
}
