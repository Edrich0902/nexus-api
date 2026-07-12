<?php

namespace App\Http\Controllers\Api\V1\Spotify;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Spotify\LibraryUrisRequest;
use App\Services\Spotify\SpotifyLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SpotifyLibraryController extends Controller
{
    public function __construct(
        private readonly SpotifyLibraryService $library,
    ) {}

    public function save(LibraryUrisRequest $request): Response
    {
        $this->library->save($request->user(), $request->validated('uris'));

        return response()->noContent();
    }

    public function remove(LibraryUrisRequest $request): Response
    {
        $this->library->remove($request->user(), $request->validated('uris'));

        return response()->noContent();
    }

    public function contains(Request $request): JsonResponse
    {
        $uris = array_values(array_filter(explode(',', (string) $request->query('uris', ''))));
        $uris = array_slice($uris, 0, 50);

        return response()->json([
            'uris' => $uris,
            'contains' => $this->library->contains($request->user(), $uris),
        ]);
    }

    public function tracks(Request $request): JsonResponse
    {
        return response()->json(
            $this->library->savedTracks(
                $request->user(),
                (int) $request->query('limit', 20),
                (int) $request->query('offset', 0),
            )
        );
    }

    public function albums(Request $request): JsonResponse
    {
        return response()->json(
            $this->library->savedAlbums(
                $request->user(),
                (int) $request->query('limit', 20),
                (int) $request->query('offset', 0),
            )
        );
    }

    public function artists(Request $request): JsonResponse
    {
        $after = $request->query('after');

        return response()->json(
            $this->library->followedArtists(
                $request->user(),
                (int) $request->query('limit', 20),
                is_string($after) && $after !== '' ? $after : null,
            )
        );
    }
}
