<?php

namespace App\Http\Controllers\Api\V1\Spotify;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Spotify\SpotifyRecentlyPlayedResource;
use App\Http\Resources\Api\V1\Spotify\SpotifyTopItemResource;
use App\Services\Spotify\SpotifyListeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SpotifyListeningController extends Controller
{
    public function __construct(
        private readonly SpotifyListeningService $listening,
    ) {}

    public function recentlyPlayed(Request $request): AnonymousResourceCollection
    {
        return SpotifyRecentlyPlayedResource::collection(
            $this->listening->recentlyPlayed($request->user(), (int) $request->integer('per_page', 50)),
        );
    }

    public function top(Request $request, string $type): AnonymousResourceCollection
    {
        abort_unless(in_array($type, ['artists', 'tracks', 'artist', 'track'], true), 404);

        $timeRange = $request->string('time_range', 'medium_term')->toString();
        abort_unless(in_array($timeRange, ['short_term', 'medium_term', 'long_term'], true), 422, 'Invalid time_range.');

        return SpotifyTopItemResource::collection(
            $this->listening->topItems($request->user(), $type, $timeRange),
        );
    }

    public function taste(Request $request): JsonResponse
    {
        return response()->json($this->listening->taste($request->user()));
    }

    public function suggestions(Request $request): JsonResponse
    {
        return response()->json([
            'source' => 'nexus_heuristic',
            'items' => $this->listening->suggestions($request->user()),
        ]);
    }
}
