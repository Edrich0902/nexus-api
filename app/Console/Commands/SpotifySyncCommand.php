<?php

namespace App\Console\Commands;

use App\Jobs\Spotify\SyncAllConnectedUsersJob;
use Illuminate\Console\Command;

class SpotifySyncCommand extends Command
{
    protected $signature = 'spotify:sync {--type=recent : recent|tops|playlists}';

    protected $description = 'Dispatch Spotify sync jobs for all connected users';

    public function handle(): int
    {
        $type = (string) $this->option('type');

        if (! in_array($type, ['recent', 'tops', 'playlists'], true)) {
            $this->error('Invalid type. Use recent, tops, or playlists.');

            return self::FAILURE;
        }

        SyncAllConnectedUsersJob::dispatch($type);
        $this->info("Dispatched Spotify {$type} sync for connected users.");

        return self::SUCCESS;
    }
}
