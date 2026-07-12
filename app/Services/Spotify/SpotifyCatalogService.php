<?php

namespace App\Services\Spotify;

use App\Models\Spotify\SpotifyArtist;
use App\Models\Spotify\SpotifyTrack;

class SpotifyCatalogService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsertArtist(array $payload): ?SpotifyArtist
    {
        $spotifyId = $payload['id'] ?? null;

        if (! is_string($spotifyId) || $spotifyId === '') {
            return null;
        }

        return SpotifyArtist::query()->updateOrCreate(
            ['spotify_id' => $spotifyId],
            [
                'name' => (string) ($payload['name'] ?? 'Unknown Artist'),
                'genres' => is_array($payload['genres'] ?? null) ? $payload['genres'] : [],
                'images' => is_array($payload['images'] ?? null) ? $payload['images'] : [],
                'external_url' => data_get($payload, 'external_urls.spotify'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsertTrack(array $payload): ?SpotifyTrack
    {
        $spotifyId = $payload['id'] ?? null;

        if (! is_string($spotifyId) || $spotifyId === '') {
            return null;
        }

        $artists = [];
        foreach ($payload['artists'] ?? [] as $artist) {
            if (! is_array($artist)) {
                continue;
            }

            $artists[] = [
                'id' => $artist['id'] ?? null,
                'name' => $artist['name'] ?? null,
            ];
        }

        $images = data_get($payload, 'album.images', []);
        $albumImage = is_array($images) && isset($images[0]['url']) ? $images[0]['url'] : null;

        return SpotifyTrack::query()->updateOrCreate(
            ['spotify_id' => $spotifyId],
            [
                'name' => (string) ($payload['name'] ?? 'Unknown Track'),
                'duration_ms' => isset($payload['duration_ms']) ? (int) $payload['duration_ms'] : null,
                'explicit' => (bool) ($payload['explicit'] ?? false),
                'album_name' => data_get($payload, 'album.name'),
                'album_image_url' => $albumImage,
                'artists' => $artists,
                'external_url' => data_get($payload, 'external_urls.spotify'),
                'uri' => is_string($payload['uri'] ?? null) ? $payload['uri'] : 'spotify:track:'.$spotifyId,
            ],
        );
    }
}
