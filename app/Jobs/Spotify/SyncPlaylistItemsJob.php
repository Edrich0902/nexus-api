<?php

namespace App\Jobs\Spotify;

use App\Models\Spotify\SpotifyPlaylist;
use App\Models\User;
use App\Services\Spotify\SpotifySyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncPlaylistItemsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $userId,
        public readonly string $spotifyPlaylistId,
    ) {}

    public function handle(SpotifySyncService $sync): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $playlist = SpotifyPlaylist::query()
            ->where('user_id', $this->userId)
            ->where('spotify_id', $this->spotifyPlaylistId)
            ->first();

        if ($playlist === null) {
            return;
        }

        try {
            $sync->syncPlaylistItems($user, $playlist);
        } catch (\Throwable $e) {
            Log::warning('Spotify playlist items sync failed', [
                'user_id' => $this->userId,
                'playlist_id' => $this->spotifyPlaylistId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
