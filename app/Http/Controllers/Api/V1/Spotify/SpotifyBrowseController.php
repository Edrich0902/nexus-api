<?php

namespace App\Http\Controllers\Api\V1\Spotify;

use App\Http\Controllers\Controller;
use App\Services\Spotify\SpotifyBrowseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpotifyBrowseController extends Controller
{
    public function __construct(
        private readonly SpotifyBrowseService $browse,
    ) {}

    public function artist(Request $request, string $artistId): JsonResponse
    {
        return response()->json(
            $this->browse->getArtist($request->user(), $artistId)
        );
    }

    public function artistTopTracks(Request $request, string $artistId): JsonResponse
    {
        $market = (string) $request->query('market', 'US');

        return response()->json(
            $this->browse->getArtistTopTracks($request->user(), $artistId, $market)
        );
    }

    public function artistAlbums(Request $request, string $artistId): JsonResponse
    {
        $limit = (int) $request->query('limit', 20);

        return response()->json(
            $this->browse->getArtistAlbums($request->user(), $artistId, $limit)
        );
    }

    public function album(Request $request, string $albumId): JsonResponse
    {
        return response()->json(
            $this->browse->getAlbum($request->user(), $albumId)
        );
    }
}
