<?php

namespace App\Http\Controllers\Api\V1\Spotify;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Spotify\SpotifySearchRequest;
use App\Services\Spotify\SpotifySearchService;
use Illuminate\Http\JsonResponse;

class SpotifySearchController extends Controller
{
    public function __construct(
        private readonly SpotifySearchService $search,
    ) {}

    public function __invoke(SpotifySearchRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json(
            $this->search->search(
                $request->user(),
                $data['q'],
                $data['type'] ?? 'track,artist,album,playlist',
                (int) ($data['limit'] ?? 10),
            )
        );
    }
}
