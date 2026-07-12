<?php

namespace App\Services\Spotify;

use App\Integrations\Spotify\SpotifyIntegration;
use App\Jobs\Spotify\SyncPlaylistItemsJob;
use App\Jobs\Spotify\SyncPlaylistsJob;
use App\Models\Spotify\SpotifyPlaylist;
use App\Models\Spotify\SpotifyPlaylistItem;
use App\Models\Spotify\SpotifyTrack;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SpotifyPlaylistService
{
    public function __construct(
        private readonly SpotifyIntegration $spotify,
        private readonly SpotifySyncService $sync,
    ) {}

    public function listFromDatabase(User $user, int $perPage = 50): LengthAwarePaginator
    {
        return SpotifyPlaylist::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * Playlist Spotify IDs that already contain the given track URI (from local sync).
     *
     * @return list<string>
     */
    public function playlistIdsContainingUri(User $user, string $uri): array
    {
        return SpotifyPlaylist::query()
            ->where('user_id', $user->id)
            ->whereHas('items.track', fn ($query) => $query->where('uri', $uri))
            ->orderBy('name')
            ->pluck('spotify_id')
            ->values()
            ->all();
    }

    public function findForUser(User $user, string $spotifyId): SpotifyPlaylist
    {
        $playlist = SpotifyPlaylist::query()
            ->where('user_id', $user->id)
            ->where('spotify_id', $spotifyId)
            ->with(['items.track'])
            ->first();

        if ($playlist === null) {
            throw (new ModelNotFoundException)->setModel(SpotifyPlaylist::class, [$spotifyId]);
        }

        return $playlist;
    }

    public function refreshPlaylist(User $user, string $spotifyId): SpotifyPlaylist
    {
        $connection = $this->spotify->requireConnection($user);
        $response = $this->spotify->get($connection, "/playlists/{$spotifyId}");
        $payload = $response->json() ?? [];

        $playlist = $this->sync->upsertPlaylistMeta($user, $connection, $payload);
        if ($playlist === null) {
            throw (new ModelNotFoundException)->setModel(SpotifyPlaylist::class, [$spotifyId]);
        }

        if ($playlist->is_owner || $playlist->collaborative) {
            $this->sync->syncPlaylistItems($user, $playlist);
        }

        return $playlist->fresh(['items.track']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): SpotifyPlaylist
    {
        $connection = $this->spotify->requireConnection($user);
        $response = $this->spotify->post($connection, '/me/playlists', [
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'public' => (bool) ($data['public'] ?? false),
            'collaborative' => (bool) ($data['collaborative'] ?? false),
        ]);

        $playlist = $this->sync->upsertPlaylistMeta($user, $connection, $response->json() ?? []);
        SyncPlaylistsJob::dispatch($user->id);

        return $playlist;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, string $spotifyId, array $data): SpotifyPlaylist
    {
        $connection = $this->spotify->requireConnection($user);
        $body = array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'public' => array_key_exists('public', $data) ? (bool) $data['public'] : null,
            'collaborative' => array_key_exists('collaborative', $data) ? (bool) $data['collaborative'] : null,
        ], fn ($value) => $value !== null);

        $this->spotify->put($connection, "/playlists/{$spotifyId}", $body);

        return $this->refreshPlaylist($user, $spotifyId);
    }

    public function delete(User $user, string $spotifyId): void
    {
        $connection = $this->spotify->requireConnection($user);
        // Spotify has no hard delete endpoint for playlists; unfollow/remove from library.
        $this->spotify->delete($connection, '/me/library', [
            'uris' => ["spotify:playlist:{$spotifyId}"],
        ]);

        SpotifyPlaylist::query()
            ->where('user_id', $user->id)
            ->where('spotify_id', $spotifyId)
            ->delete();
    }

    /**
     * @param  list<string>  $uris
     */
    public function addItems(User $user, string $spotifyId, array $uris, ?int $position = null): void
    {
        $connection = $this->spotify->requireConnection($user);
        $body = ['uris' => array_values($uris)];
        if ($position !== null) {
            $body['position'] = $position;
        }

        $this->spotify->post($connection, "/playlists/{$spotifyId}/items", $body);
        $this->applyLocalAdd($user, $spotifyId, $uris);
        SyncPlaylistItemsJob::dispatch($user->id, $spotifyId);
    }

    /**
     * @param  list<array{uri: string, positions?: list<int>}>  $items
     */
    public function removeItems(User $user, string $spotifyId, array $items): void
    {
        $connection = $this->spotify->requireConnection($user);
        $this->spotify->delete($connection, "/playlists/{$spotifyId}/items", [
            'items' => array_values($items),
        ]);
        $this->applyLocalRemove($user, $spotifyId, $items);
        SyncPlaylistItemsJob::dispatch($user->id, $spotifyId);
    }

    /**
     * @param  list<string>  $uris
     */
    public function replaceItems(User $user, string $spotifyId, array $uris): void
    {
        $connection = $this->spotify->requireConnection($user);
        $this->spotify->put($connection, "/playlists/{$spotifyId}/items", [
            'uris' => array_values($uris),
        ]);
        SyncPlaylistItemsJob::dispatch($user->id, $spotifyId);
    }

    /**
     * Keep local membership accurate until the async items sync finishes.
     *
     * @param  list<string>  $uris
     */
    private function applyLocalAdd(User $user, string $spotifyId, array $uris): void
    {
        $playlist = SpotifyPlaylist::query()
            ->where('user_id', $user->id)
            ->where('spotify_id', $spotifyId)
            ->first();

        if ($playlist === null || $uris === []) {
            return;
        }

        $tracks = SpotifyTrack::query()
            ->whereIn('uri', $uris)
            ->get()
            ->keyBy('uri');

        $nextPosition = ((int) $playlist->items()->max('position')) + 1;

        foreach ($uris as $uri) {
            $track = $tracks->get($uri);
            if ($track === null) {
                continue;
            }

            $exists = SpotifyPlaylistItem::query()
                ->where('spotify_playlist_id', $playlist->id)
                ->where('spotify_track_id', $track->id)
                ->exists();

            if ($exists) {
                continue;
            }

            SpotifyPlaylistItem::query()->create([
                'spotify_playlist_id' => $playlist->id,
                'spotify_track_id' => $track->id,
                'position' => $nextPosition,
                'added_at' => now(),
            ]);
            $nextPosition++;
        }

        $playlist->forceFill([
            'item_count' => $playlist->items()->count(),
        ])->save();
    }

    /**
     * @param  list<array{uri: string, positions?: list<int>}>  $items
     */
    private function applyLocalRemove(User $user, string $spotifyId, array $items): void
    {
        $playlist = SpotifyPlaylist::query()
            ->where('user_id', $user->id)
            ->where('spotify_id', $spotifyId)
            ->first();

        if ($playlist === null || $items === []) {
            return;
        }

        $uris = array_values(array_filter(array_map(
            static fn (array $item): ?string => isset($item['uri']) && is_string($item['uri']) ? $item['uri'] : null,
            $items,
        )));

        if ($uris === []) {
            return;
        }

        $trackIds = SpotifyTrack::query()
            ->whereIn('uri', $uris)
            ->pluck('id');

        if ($trackIds->isEmpty()) {
            return;
        }

        SpotifyPlaylistItem::query()
            ->where('spotify_playlist_id', $playlist->id)
            ->whereIn('spotify_track_id', $trackIds)
            ->delete();

        $playlist->forceFill([
            'item_count' => $playlist->items()->count(),
        ])->save();
    }
}
