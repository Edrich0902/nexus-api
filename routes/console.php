<?php

use App\Jobs\Github\SyncAllGithubUsersJob;
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
