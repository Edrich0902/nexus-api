<?php

namespace App\Jobs\Spotify;

use App\Integrations\Spotify\SpotifyIntegration;
use App\Models\Integration\IntegrationConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncAllConnectedUsersJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $syncType = 'recent',
    ) {}

    public function handle(): void
    {
        $userIds = IntegrationConnection::query()
            ->where('provider', SpotifyIntegration::PROVIDER)
            ->where('status', IntegrationConnection::STATUS_ACTIVE)
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            match ($this->syncType) {
                'tops' => SyncTopItemsJob::dispatch((int) $userId),
                'playlists' => SyncPlaylistsJob::dispatch((int) $userId),
                default => SyncRecentlyPlayedJob::dispatch((int) $userId),
            };
        }
    }
}
