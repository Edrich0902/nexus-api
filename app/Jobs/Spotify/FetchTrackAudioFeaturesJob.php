<?php

namespace App\Jobs\Spotify;

use App\Models\Spotify\SpotifyTrack;
use App\Services\Spotify\TrackAudioFeaturesService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class FetchTrackAudioFeaturesJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $spotifyTrackId,
    ) {}

    public function uniqueId(): string
    {
        return 'spotify-audio-features:'.$this->spotifyTrackId;
    }

    public function handle(TrackAudioFeaturesService $features): void
    {
        $track = SpotifyTrack::query()->find($this->spotifyTrackId);
        if ($track === null) {
            return;
        }

        $cached = $features->findCached($track);
        if ($cached?->isReady()) {
            return;
        }

        try {
            $features->fetchAndStore($track);
        } catch (\Throwable $e) {
            Log::warning('FetchTrackAudioFeaturesJob failed', [
                'spotify_track_id' => $this->spotifyTrackId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
