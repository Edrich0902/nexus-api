<?php

use App\Jobs\Github\SyncAllGithubUsersJob;
use App\Jobs\Sports\SyncFootballStandingsJob;
use App\Jobs\Sports\SyncSportsDayJob;
use App\Jobs\Sports\SyncSportsFixturesJob;
use App\Jobs\Sports\SyncSportsLeaguesJob;
use App\Jobs\Spotify\SyncAllConnectedUsersJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SyncAllConnectedUsersJob('recent'))->everyFifteenMinutes();
Schedule::job(new SyncAllConnectedUsersJob('tops'))->dailyAt('03:15');
Schedule::job(new SyncAllConnectedUsersJob('playlists'))->everyThreeHours();
Schedule::job(new SyncAllGithubUsersJob)->everyThreeHours();

/*
 * Sports: light, staggered waves — micro-jobs chain with fail-fast rate limiting.
 * Intentionally infrequent so free-tier SportsDB + small workers stay invisible.
 */
Schedule::job(new SyncSportsFixturesJob)
    ->everyTwoHours()
    ->withoutOverlapping(30);

Schedule::job(new SyncSportsDayJob)
    ->cron('25 */6 * * *')
    ->withoutOverlapping(30);

Schedule::job(new SyncFootballStandingsJob)
    ->dailyAt('05:20')
    ->withoutOverlapping(30);

Schedule::job(new SyncSportsLeaguesJob)
    ->dailyAt('04:10')
    ->withoutOverlapping(30);
