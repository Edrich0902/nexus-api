<?php

namespace App\Http\Controllers\Api\V1\Spotify;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Spotify\PlaylistItemsRequest;
use App\Http\Requests\Api\V1\Spotify\PlaylistRemoveItemsRequest;
use App\Http\Requests\Api\V1\Spotify\StorePlaylistRequest;
use App\Http\Requests\Api\V1\Spotify\UpdatePlaylistRequest;
use App\Http\Resources\Api\V1\Spotify\SpotifyPlaylistResource;
use App\Services\Spotify\SpotifyPlaylistService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SpotifyPlaylistController extends Controller
{
    public function __construct(
        private readonly SpotifyPlaylistService $playlists,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return SpotifyPlaylistResource::collection(
            $this->playlists->listFromDatabase($request->user(), (int) $request->integer('per_page', 50)),
        );
    }

    /**
     * @return array{playlist_ids: list<string>}
     */
    public function containing(Request $request): array
    {
        $validated = $request->validate([
            'uri' => ['required', 'string'],
        ]);

        return [
            'playlist_ids' => $this->playlists->playlistIdsContainingUri(
                $request->user(),
                $validated['uri'],
            ),
        ];
    }

    public function show(Request $request, string $playlistId): SpotifyPlaylistResource
    {
        if ($request->boolean('refresh')) {
            $playlist = $this->playlists->refreshPlaylist($request->user(), $playlistId);
        } else {
            $playlist = $this->playlists->findForUser($request->user(), $playlistId);
        }

        return new SpotifyPlaylistResource($playlist);
    }

    public function store(StorePlaylistRequest $request): SpotifyPlaylistResource
    {
        $playlist = $this->playlists->create($request->user(), $request->validated());

        return new SpotifyPlaylistResource($playlist);
    }

    public function update(UpdatePlaylistRequest $request, string $playlistId): SpotifyPlaylistResource
    {
        $playlist = $this->playlists->update($request->user(), $playlistId, $request->validated());

        return new SpotifyPlaylistResource($playlist);
    }

    public function destroy(Request $request, string $playlistId): Response
    {
        $this->playlists->delete($request->user(), $playlistId);

        return response()->noContent();
    }

    public function addItems(PlaylistItemsRequest $request, string $playlistId): Response
    {
        $data = $request->validated();
        $this->playlists->addItems(
            $request->user(),
            $playlistId,
            $data['uris'],
            $data['position'] ?? null,
        );

        return response()->noContent();
    }

    public function removeItems(PlaylistRemoveItemsRequest $request, string $playlistId): Response
    {
        $this->playlists->removeItems($request->user(), $playlistId, $request->validated('items'));

        return response()->noContent();
    }

    public function replaceItems(PlaylistItemsRequest $request, string $playlistId): Response
    {
        $this->playlists->replaceItems($request->user(), $playlistId, $request->validated('uris'));

        return response()->noContent();
    }
}
