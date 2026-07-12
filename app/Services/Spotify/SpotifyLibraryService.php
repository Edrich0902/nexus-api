<?php

namespace App\Services\Spotify;

use App\Integrations\Spotify\SpotifyIntegration;
use App\Models\User;

class SpotifyLibraryService
{
    public function __construct(
        private readonly SpotifyIntegration $spotify,
    ) {}

    /**
     * @param  list<string>  $uris
     */
    public function save(User $user, array $uris): void
    {
        $connection = $this->spotify->requireConnection($user);
        $this->spotify->put($connection, '/me/library', [
            'uris' => array_values($uris),
        ]);
    }

    /**
     * @param  list<string>  $uris
     */
    public function remove(User $user, array $uris): void
    {
        $connection = $this->spotify->requireConnection($user);
        $this->spotify->delete($connection, '/me/library', [
            'uris' => array_values($uris),
        ]);
    }

    /**
     * @param  list<string>  $uris
     * @return list<bool>
     */
    public function contains(User $user, array $uris): array
    {
        $uris = array_values(array_slice($uris, 0, 50));
        if ($uris === []) {
            return [];
        }

        $connection = $this->spotify->requireConnection($user);
        $response = $this->spotify->get($connection, '/me/library/contains', [
            'uris' => implode(',', $uris),
        ]);

        $result = $response->json();

        return is_array($result) ? array_map('boolval', $result) : [];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int, next: bool}
     */
    public function savedTracks(User $user, int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(20, $limit));
        $offset = max(0, $offset);
        $connection = $this->spotify->requireConnection($user);
        $response = $this->spotify->get($connection, '/me/tracks', [
            'limit' => $limit,
            'offset' => $offset,
        ]);
        $payload = $response->json() ?? [];
        $items = [];

        foreach (($payload['items'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $track = $row['track'] ?? null;
            if (! is_array($track) || ! is_string($track['id'] ?? null)) {
                continue;
            }
            $items[] = [
                'added_at' => $row['added_at'] ?? null,
                'track' => $this->mapTrack($track),
            ];
        }

        return [
            'items' => $items,
            'total' => (int) ($payload['total'] ?? count($items)),
            'limit' => $limit,
            'offset' => $offset,
            'next' => is_string($payload['next'] ?? null),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int, next: bool}
     */
    public function savedAlbums(User $user, int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(20, $limit));
        $offset = max(0, $offset);
        $connection = $this->spotify->requireConnection($user);
        $response = $this->spotify->get($connection, '/me/albums', [
            'limit' => $limit,
            'offset' => $offset,
        ]);
        $payload = $response->json() ?? [];
        $items = [];

        foreach (($payload['items'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $album = $row['album'] ?? null;
            if (! is_array($album) || ! is_string($album['id'] ?? null)) {
                continue;
            }
            $items[] = [
                'added_at' => $row['added_at'] ?? null,
                'album' => [
                    'id' => $album['id'],
                    'name' => (string) ($album['name'] ?? 'Unknown'),
                    'uri' => (string) ($album['uri'] ?? 'spotify:album:'.$album['id']),
                    'image_url' => data_get($album, 'images.0.url'),
                    'release_date' => $album['release_date'] ?? null,
                    'total_tracks' => (int) ($album['total_tracks'] ?? 0),
                    'artists' => collect($album['artists'] ?? [])
                        ->filter(fn ($a) => is_array($a) && is_string($a['id'] ?? null))
                        ->map(fn (array $a) => ['id' => $a['id'], 'name' => (string) ($a['name'] ?? '')])
                        ->values()
                        ->all(),
                    'external_url' => data_get($album, 'external_urls.spotify'),
                ],
            ];
        }

        return [
            'items' => $items,
            'total' => (int) ($payload['total'] ?? count($items)),
            'limit' => $limit,
            'offset' => $offset,
            'next' => is_string($payload['next'] ?? null),
        ];
    }

    /**
     * @return array{artists: list<array<string, mixed>>, total: int, next: bool, cursors: array<string, mixed>|null}
     */
    public function followedArtists(User $user, int $limit = 20, ?string $after = null): array
    {
        $limit = max(1, min(20, $limit));
        $connection = $this->spotify->requireConnection($user);
        $query = [
            'type' => 'artist',
            'limit' => $limit,
        ];
        if ($after) {
            $query['after'] = $after;
        }

        $response = $this->spotify->get($connection, '/me/following', $query);
        $artists = $response->json('artists') ?? [];
        $items = [];

        foreach (($artists['items'] ?? []) as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                continue;
            }
            $items[] = [
                'id' => $item['id'],
                'name' => (string) ($item['name'] ?? 'Unknown'),
                'genres' => is_array($item['genres'] ?? null) ? $item['genres'] : [],
                'images' => is_array($item['images'] ?? null) ? $item['images'] : [],
                'uri' => (string) ($item['uri'] ?? 'spotify:artist:'.$item['id']),
                'external_url' => data_get($item, 'external_urls.spotify'),
            ];
        }

        return [
            'artists' => $items,
            'total' => (int) ($artists['total'] ?? count($items)),
            'next' => is_string($artists['next'] ?? null),
            'cursors' => is_array($artists['cursors'] ?? null) ? $artists['cursors'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $track
     * @return array<string, mixed>
     */
    private function mapTrack(array $track): array
    {
        return [
            'id' => $track['id'],
            'name' => (string) ($track['name'] ?? 'Unknown'),
            'uri' => (string) ($track['uri'] ?? 'spotify:track:'.$track['id']),
            'duration_ms' => (int) ($track['duration_ms'] ?? 0),
            'explicit' => (bool) ($track['explicit'] ?? false),
            'album_name' => data_get($track, 'album.name'),
            'album_image_url' => data_get($track, 'album.images.0.url'),
            'album_id' => data_get($track, 'album.id'),
            'artists' => collect($track['artists'] ?? [])
                ->filter(fn ($a) => is_array($a) && is_string($a['id'] ?? null))
                ->map(fn (array $a) => ['id' => $a['id'], 'name' => (string) ($a['name'] ?? '')])
                ->values()
                ->all(),
            'external_url' => data_get($track, 'external_urls.spotify'),
        ];
    }
}
