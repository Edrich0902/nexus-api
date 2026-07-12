<?php

namespace App\Services\Spotify;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Spotify\SpotifyIntegration;
use App\Models\Spotify\SpotifyArtist;
use App\Models\Spotify\SpotifyTrack;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class SpotifyBrowseService
{
    public function __construct(
        private readonly SpotifyIntegration $spotify,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getArtist(User $user, string $artistId): array
    {
        return Cache::remember(
            "spotify:browse:artist:{$artistId}",
            600,
            function () use ($user, $artistId): array {
                try {
                    $connection = $this->spotify->requireConnection($user);
                    $response = $this->spotify->get($connection, '/artists/'.$artistId);
                    $item = $response->json() ?? [];

                    if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                        return $this->localArtistFallback($artistId, 'Artist not found on Spotify.');
                    }

                    return [
                        'available' => true,
                        'source' => 'spotify',
                        'id' => $item['id'],
                        'name' => (string) ($item['name'] ?? 'Unknown'),
                        'genres' => is_array($item['genres'] ?? null) ? $item['genres'] : [],
                        'images' => is_array($item['images'] ?? null) ? $item['images'] : [],
                        'followers' => (int) data_get($item, 'followers.total', 0),
                        'popularity' => (int) ($item['popularity'] ?? 0),
                        'uri' => (string) ($item['uri'] ?? 'spotify:artist:'.$item['id']),
                        'external_url' => data_get($item, 'external_urls.spotify'),
                    ];
                } catch (IntegrationException $e) {
                    if (in_array($e->statusCode, [403, 404], true)) {
                        return $this->localArtistFallback(
                            $artistId,
                            $e->statusCode === 403
                                ? 'Spotify catalog is unavailable for this app. Showing synced data when available.'
                                : 'Artist not found on Spotify.'
                        );
                    }
                    throw $e;
                }
            }
        );
    }

    /**
     * @return array{available: bool, message: ?string, tracks: list<array<string, mixed>>}
     */
    public function getArtistTopTracks(User $user, string $artistId, string $market = 'US'): array
    {
        return Cache::remember(
            "spotify:browse:artist:{$artistId}:top:{$market}",
            600,
            function () use ($user, $artistId, $market): array {
                try {
                    $connection = $this->spotify->requireConnection($user);
                    $response = $this->spotify->get($connection, '/artists/'.$artistId.'/top-tracks', [
                        'market' => $market,
                    ]);
                    $items = $response->json('tracks') ?? [];

                    return [
                        'available' => true,
                        'message' => null,
                        'tracks' => $this->mapTracks(is_array($items) ? $items : []),
                    ];
                } catch (IntegrationException $e) {
                    if (in_array($e->statusCode, [403, 404], true)) {
                        return [
                            'available' => false,
                            'message' => 'Top tracks unavailable from Spotify for this app.',
                            'tracks' => $this->localTracksForArtist($artistId, 10),
                        ];
                    }
                    throw $e;
                }
            }
        );
    }

    /**
     * @return array{available: bool, message: ?string, albums: list<array<string, mixed>>}
     */
    public function getArtistAlbums(User $user, string $artistId, int $limit = 20): array
    {
        $limit = max(1, min(20, $limit));

        return Cache::remember(
            "spotify:browse:artist:{$artistId}:albums:{$limit}",
            600,
            function () use ($user, $artistId, $limit): array {
                try {
                    $connection = $this->spotify->requireConnection($user);
                    $response = $this->spotify->get($connection, '/artists/'.$artistId.'/albums', [
                        'include_groups' => 'album,single',
                        'limit' => $limit,
                    ]);
                    $items = $response->json('items') ?? [];

                    return [
                        'available' => true,
                        'message' => null,
                        'albums' => $this->mapAlbums(is_array($items) ? $items : []),
                    ];
                } catch (IntegrationException $e) {
                    if (in_array($e->statusCode, [403, 404], true)) {
                        return [
                            'available' => false,
                            'message' => 'Artist albums unavailable from Spotify for this app.',
                            'albums' => [],
                        ];
                    }
                    throw $e;
                }
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getAlbum(User $user, string $albumId): array
    {
        return Cache::remember(
            "spotify:browse:album:{$albumId}",
            600,
            function () use ($user, $albumId): array {
                try {
                    $connection = $this->spotify->requireConnection($user);
                    $response = $this->spotify->get($connection, '/albums/'.$albumId);
                    $item = $response->json() ?? [];

                    if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                        return [
                            'available' => false,
                            'message' => 'Album not found on Spotify.',
                            'source' => 'none',
                        ];
                    }

                    $tracks = [];
                    foreach (($item['tracks']['items'] ?? []) as $track) {
                        if (! is_array($track) || ! is_string($track['id'] ?? null)) {
                            continue;
                        }
                        $tracks[] = [
                            'id' => $track['id'],
                            'name' => (string) ($track['name'] ?? 'Unknown'),
                            'uri' => (string) ($track['uri'] ?? 'spotify:track:'.$track['id']),
                            'duration_ms' => (int) ($track['duration_ms'] ?? 0),
                            'track_number' => (int) ($track['track_number'] ?? 0),
                            'explicit' => (bool) ($track['explicit'] ?? false),
                            'artists' => collect($track['artists'] ?? [])
                                ->filter(fn ($a) => is_array($a) && is_string($a['id'] ?? null))
                                ->map(fn (array $a) => ['id' => $a['id'], 'name' => (string) ($a['name'] ?? '')])
                                ->values()
                                ->all(),
                        ];
                    }

                    return [
                        'available' => true,
                        'source' => 'spotify',
                        'id' => $item['id'],
                        'name' => (string) ($item['name'] ?? 'Unknown'),
                        'uri' => (string) ($item['uri'] ?? 'spotify:album:'.$item['id']),
                        'image_url' => data_get($item, 'images.0.url'),
                        'release_date' => $item['release_date'] ?? null,
                        'total_tracks' => (int) ($item['total_tracks'] ?? count($tracks)),
                        'artists' => collect($item['artists'] ?? [])
                            ->filter(fn ($a) => is_array($a) && is_string($a['id'] ?? null))
                            ->map(fn (array $a) => ['id' => $a['id'], 'name' => (string) ($a['name'] ?? '')])
                            ->values()
                            ->all(),
                        'external_url' => data_get($item, 'external_urls.spotify'),
                        'tracks' => $tracks,
                    ];
                } catch (IntegrationException $e) {
                    if (in_array($e->statusCode, [403, 404], true)) {
                        return [
                            'available' => false,
                            'message' => $e->statusCode === 403
                                ? 'Spotify catalog is unavailable for this app.'
                                : 'Album not found on Spotify.',
                            'source' => 'none',
                        ];
                    }
                    throw $e;
                }
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function localArtistFallback(string $artistId, string $message): array
    {
        $local = SpotifyArtist::query()->where('spotify_id', $artistId)->first();
        if ($local === null) {
            return [
                'available' => false,
                'message' => $message,
                'source' => 'none',
                'local' => null,
            ];
        }

        return [
            'available' => true,
            'source' => 'local',
            'message' => $message,
            'id' => $local->spotify_id,
            'name' => $local->name,
            'genres' => $local->genres ?? [],
            'images' => $local->images ?? [],
            'followers' => 0,
            'popularity' => 0,
            'uri' => 'spotify:artist:'.$local->spotify_id,
            'external_url' => null,
            'local' => true,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function localTracksForArtist(string $artistId, int $limit): array
    {
        $tracks = SpotifyTrack::query()
            ->where(function ($query) use ($artistId): void {
                $query->where('artists', 'like', '%"id":"'.$artistId.'"%')
                    ->orWhere('artists', 'like', '%"id": "'.$artistId.'"%');
            })
            ->limit($limit)
            ->get();

        return $tracks->map(function (SpotifyTrack $track): array {
            $artists = is_array($track->artists) ? $track->artists : [];

            return [
                'id' => $track->spotify_id,
                'name' => $track->name,
                'uri' => $track->uri,
                'duration_ms' => $track->duration_ms,
                'explicit' => $track->explicit,
                'album_name' => $track->album_name,
                'album_image_url' => $track->album_image_url,
                'album_id' => null,
                'artists' => collect($artists)
                    ->filter(fn ($a) => is_array($a) && is_string($a['id'] ?? null))
                    ->map(fn (array $a) => [
                        'id' => $a['id'],
                        'name' => (string) ($a['name'] ?? ''),
                    ])
                    ->values()
                    ->all(),
            ];
        })->all();
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
                'album_type' => $item['album_type'] ?? null,
                'artists' => collect($item['artists'] ?? [])
                    ->filter(fn ($a) => is_array($a) && is_string($a['id'] ?? null))
                    ->map(fn (array $a) => ['id' => $a['id'], 'name' => (string) ($a['name'] ?? '')])
                    ->values()
                    ->all(),
            ];
        }

        return $out;
    }
}
