<?php

namespace App\Services\Spotify;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Spotify\SpotifyIntegration;
use App\Models\User;

class SpotifySearchService
{
    public function __construct(
        private readonly SpotifyIntegration $spotify,
    ) {}

    /**
     * @return array{
     *     tracks: list<array<string, mixed>>,
     *     artists: list<array<string, mixed>>,
     *     albums: list<array<string, mixed>>,
     *     playlists: list<array<string, mixed>>
     * }
     */
    public function search(User $user, string $query, string $types = 'track,artist,album,playlist', int $limit = 10): array
    {
        $connection = $this->spotify->requireConnection($user);
        $limit = max(1, min(10, $limit));
        $types = $this->normalizeTypes($types);

        try {
            $response = $this->spotify->get($connection, '/search', [
                'q' => $query,
                'type' => $types,
                'limit' => $limit,
            ]);
        } catch (IntegrationException $e) {
            if (in_array($e->statusCode, [400, 403], true)) {
                return $this->emptyResult();
            }
            throw $e;
        }

        $payload = $response->json() ?? [];

        return [
            'tracks' => $this->mapTracks($payload['tracks']['items'] ?? []),
            'artists' => $this->mapArtists($payload['artists']['items'] ?? []),
            'albums' => $this->mapAlbums($payload['albums']['items'] ?? []),
            'playlists' => $this->mapPlaylists($payload['playlists']['items'] ?? []),
        ];
    }

    /**
     * @return array{tracks: list<mixed>, artists: list<mixed>, albums: list<mixed>, playlists: list<mixed>}
     */
    private function emptyResult(): array
    {
        return [
            'tracks' => [],
            'artists' => [],
            'albums' => [],
            'playlists' => [],
        ];
    }

    private function normalizeTypes(string $types): string
    {
        $allowed = ['track', 'artist', 'album', 'playlist'];
        $parts = array_values(array_filter(array_map('trim', explode(',', strtolower($types)))));
        $parts = array_values(array_intersect($parts, $allowed));

        return $parts === [] ? 'track,artist,album,playlist' : implode(',', $parts);
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function mapTracks(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                continue;
            }
            $out[] = [
                'id' => $item['id'],
                'name' => (string) ($item['name'] ?? 'Unknown'),
                'uri' => (string) ($item['uri'] ?? 'spotify:track:'.$item['id']),
                'duration_ms' => (int) ($item['duration_ms'] ?? 0),
                'explicit' => (bool) ($item['explicit'] ?? false),
                'album_name' => data_get($item, 'album.name'),
                'album_image_url' => data_get($item, 'album.images.0.url'),
                'album_id' => data_get($item, 'album.id'),
                'artists' => collect($item['artists'] ?? [])
                    ->filter(fn ($a) => is_array($a) && is_string($a['id'] ?? null))
                    ->map(fn (array $a) => ['id' => $a['id'], 'name' => (string) ($a['name'] ?? '')])
                    ->values()
                    ->all(),
                'external_url' => data_get($item, 'external_urls.spotify'),
            ];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function mapArtists(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                continue;
            }
            $out[] = [
                'id' => $item['id'],
                'name' => (string) ($item['name'] ?? 'Unknown'),
                'genres' => is_array($item['genres'] ?? null) ? $item['genres'] : [],
                'images' => is_array($item['images'] ?? null) ? $item['images'] : [],
                'external_url' => data_get($item, 'external_urls.spotify'),
                'uri' => (string) ($item['uri'] ?? 'spotify:artist:'.$item['id']),
            ];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function mapAlbums(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                continue;
            }
            $out[] = [
                'id' => $item['id'],
                'name' => (string) ($item['name'] ?? 'Unknown'),
                'uri' => (string) ($item['uri'] ?? 'spotify:album:'.$item['id']),
                'image_url' => data_get($item, 'images.0.url'),
                'release_date' => $item['release_date'] ?? null,
                'total_tracks' => (int) ($item['total_tracks'] ?? 0),
                'artists' => collect($item['artists'] ?? [])
                    ->filter(fn ($a) => is_array($a) && is_string($a['id'] ?? null))
                    ->map(fn (array $a) => ['id' => $a['id'], 'name' => (string) ($a['name'] ?? '')])
                    ->values()
                    ->all(),
                'external_url' => data_get($item, 'external_urls.spotify'),
            ];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function mapPlaylists(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                continue;
            }
            $out[] = [
                'id' => $item['id'],
                'name' => (string) ($item['name'] ?? 'Unknown'),
                'uri' => (string) ($item['uri'] ?? 'spotify:playlist:'.$item['id']),
                'image_url' => data_get($item, 'images.0.url'),
                'description' => $item['description'] ?? null,
                'owner_name' => data_get($item, 'owner.display_name'),
                'item_count' => (int) data_get($item, 'tracks.total', 0),
                'external_url' => data_get($item, 'external_urls.spotify'),
            ];
        }

        return $out;
    }
}
