<?php

namespace App\Services\Spotify;

use App\Integrations\Spotify\SpotifyIntegration;
use App\Models\Integration\IntegrationConnection;
use App\Models\Spotify\SpotifyPlaylist;
use App\Models\Spotify\SpotifyPlaylistItem;
use App\Models\Spotify\SpotifyRecentlyPlayed;
use App\Models\Spotify\SpotifyTopItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SpotifySyncService
{
    private const TIME_RANGES = ['short_term', 'medium_term', 'long_term'];

    public function __construct(
        private readonly SpotifyIntegration $spotify,
        private readonly SpotifyCatalogService $catalog,
        private readonly SpotifyTasteService $taste,
    ) {}

    public function syncAll(User $user): void
    {
        $this->syncRecentlyPlayed($user);
        $this->syncTopItems($user);
        $this->syncPlaylists($user);
        $this->taste->recompute($user);
        $this->touchLastSynced($user);
    }

    public function syncRecentlyPlayed(User $user): void
    {
        $connection = $this->spotify->requireConnection($user);
        $response = $this->spotify->get($connection, '/me/player/recently-played', [
            'limit' => 50,
        ]);

        $items = $response->json('items') ?? [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $trackPayload = $item['track'] ?? null;
            if (! is_array($trackPayload)) {
                continue;
            }

            $track = $this->catalog->upsertTrack($trackPayload);
            if ($track === null) {
                continue;
            }

            $playedAt = isset($item['played_at']) ? Carbon::parse($item['played_at']) : null;
            if ($playedAt === null) {
                continue;
            }

            SpotifyRecentlyPlayed::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'spotify_track_id' => $track->id,
                    'played_at' => $playedAt,
                ],
                [
                    'context_uri' => data_get($item, 'context.uri'),
                    'context_type' => data_get($item, 'context.type'),
                ],
            );
        }

        $this->touchLastSynced($user);
    }

    public function syncTopItems(User $user): void
    {
        $connection = $this->spotify->requireConnection($user);

        foreach (self::TIME_RANGES as $timeRange) {
            $this->syncTopType($connection, $user, 'artists', $timeRange);
            $this->syncTopType($connection, $user, 'tracks', $timeRange);
        }

        $this->taste->recompute($user);
        $this->touchLastSynced($user);
    }

    public function syncPlaylists(User $user): void
    {
        $connection = $this->spotify->requireConnection($user);
        $seenIds = [];
        $offset = 0;

        do {
            $response = $this->spotify->get($connection, '/me/playlists', [
                'limit' => 50,
                'offset' => $offset,
            ]);

            $items = $response->json('items') ?? [];
            $total = (int) ($response->json('total') ?? 0);

            foreach ($items as $playlistPayload) {
                if (! is_array($playlistPayload)) {
                    continue;
                }

                $playlist = $this->upsertPlaylistMeta($user, $connection, $playlistPayload);
                if ($playlist === null) {
                    continue;
                }

                $seenIds[] = $playlist->spotify_id;

                if ($playlist->is_owner || $playlist->collaborative) {
                    $this->syncPlaylistItems($user, $playlist);
                }
            }

            $offset += 50;
        } while ($offset < $total);

        SpotifyPlaylist::query()
            ->where('user_id', $user->id)
            ->whereNotIn('spotify_id', $seenIds)
            ->delete();

        $this->touchLastSynced($user);
    }

    public function syncPlaylistItems(User $user, SpotifyPlaylist $playlist): void
    {
        $connection = $this->spotify->requireConnection($user);
        $offset = 0;
        $position = 0;
        $rows = [];

        do {
            $response = $this->spotify->get($connection, "/playlists/{$playlist->spotify_id}/items", [
                'limit' => 50,
                'offset' => $offset,
            ]);

            $items = $response->json('items') ?? [];
            $total = (int) ($response->json('total') ?? 0);

            foreach ($items as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $item = $entry['item'] ?? $entry['track'] ?? null;
                $track = is_array($item) && ($item['type'] ?? null) === 'track'
                    ? $this->catalog->upsertTrack($item)
                    : (is_array($item) && isset($item['id']) ? $this->catalog->upsertTrack($item) : null);

                $rows[] = [
                    'spotify_playlist_id' => $playlist->id,
                    'spotify_track_id' => $track?->id,
                    'position' => $position,
                    'added_at' => isset($entry['added_at']) ? Carbon::parse($entry['added_at']) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $position++;
            }

            $offset += 50;
        } while ($offset < $total);

        DB::transaction(function () use ($playlist, $rows, $position): void {
            SpotifyPlaylistItem::query()
                ->where('spotify_playlist_id', $playlist->id)
                ->delete();

            if ($rows !== []) {
                SpotifyPlaylistItem::query()->insert($rows);
            }

            $playlist->forceFill([
                'item_count' => $position,
                'synced_at' => now(),
            ])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsertPlaylistMeta(User $user, IntegrationConnection $connection, array $payload): ?SpotifyPlaylist
    {
        $spotifyId = $payload['id'] ?? null;
        if (! is_string($spotifyId) || $spotifyId === '') {
            return null;
        }

        $ownerId = data_get($payload, 'owner.id');
        $isOwner = is_string($ownerId) && $ownerId !== '' && $ownerId === $connection->external_user_id;

        $images = $payload['images'] ?? [];
        $imageUrl = is_array($images) && isset($images[0]['url']) ? $images[0]['url'] : null;

        $itemCount = (int) (
            data_get($payload, 'items.total')
            ?? data_get($payload, 'tracks.total')
            ?? 0
        );

        return SpotifyPlaylist::withTrashed()->updateOrCreate(
            [
                'user_id' => $user->id,
                'spotify_id' => $spotifyId,
            ],
            [
                'name' => (string) ($payload['name'] ?? 'Untitled playlist'),
                'description' => $payload['description'] ?? null,
                'public' => (bool) ($payload['public'] ?? false),
                'collaborative' => (bool) ($payload['collaborative'] ?? false),
                'is_owner' => $isOwner,
                'image_url' => $imageUrl,
                'snapshot_id' => $payload['snapshot_id'] ?? null,
                'uri' => $payload['uri'] ?? null,
                'item_count' => $itemCount,
                'synced_at' => now(),
                'deleted_at' => null,
            ],
        );
    }

    private function syncTopType(
        IntegrationConnection $connection,
        User $user,
        string $type,
        string $timeRange,
    ): void {
        $response = $this->spotify->get($connection, "/me/top/{$type}", [
            'limit' => 20,
            'time_range' => $timeRange,
        ]);

        $items = $response->json('items') ?? [];
        $singular = $type === 'artists' ? 'artist' : 'track';

        SpotifyTopItem::query()
            ->where('user_id', $user->id)
            ->where('type', $singular)
            ->where('time_range', $timeRange)
            ->delete();

        foreach ($items as $index => $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $artistId = null;
            $trackId = null;

            if ($singular === 'artist') {
                $artist = $this->catalog->upsertArtist($payload);
                $artistId = $artist?->id;
            } else {
                $track = $this->catalog->upsertTrack($payload);
                $trackId = $track?->id;
            }

            SpotifyTopItem::query()->create([
                'user_id' => $user->id,
                'type' => $singular,
                'time_range' => $timeRange,
                'rank' => $index + 1,
                'spotify_artist_id' => $artistId,
                'spotify_track_id' => $trackId,
                'synced_at' => now(),
            ]);
        }
    }

    private function touchLastSynced(User $user): void
    {
        IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('provider', SpotifyIntegration::PROVIDER)
            ->update(['last_synced_at' => now()]);
    }
}
