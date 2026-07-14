<?php

namespace App\Http\Controllers\Api\V1\Spotify;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Spotify\ListeningHeartbeatRequest;
use App\Http\Requests\Api\V1\Spotify\UpdateListeningSettingsRequest;
use App\Http\Resources\Api\V1\Spotify\SpotifyRecentlyPlayedResource;
use App\Http\Resources\Api\V1\Spotify\SpotifyTopItemResource;
use App\Models\Spotify\SpotifyTrack;
use App\Services\Spotify\ListeningProfileService;
use App\Services\Spotify\ListeningSessionService;
use App\Services\Spotify\SpotifyListeningService;
use App\Services\Spotify\TrackAudioFeaturesService;
use App\Services\Spotify\TrackRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SpotifyListeningController extends Controller
{
    public function __construct(
        private readonly SpotifyListeningService $listening,
        private readonly ListeningSessionService $sessions,
        private readonly ListeningProfileService $profile,
        private readonly TrackAudioFeaturesService $features,
        private readonly TrackRecommendationService $recommendations,
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

    public function heartbeat(ListeningHeartbeatRequest $request): JsonResponse
    {
        return response()->json(
            $this->sessions->heartbeat($request->user(), $request->validated()),
        );
    }

    public function trackFeatures(Request $request, string $spotifyId): JsonResponse
    {
        $track = SpotifyTrack::query()->where('spotify_id', $spotifyId)->first();
        if ($track === null) {
            return response()->json([
                'status' => 'unavailable',
                'features' => null,
            ]);
        }

        $async = ! $request->boolean('sync');
        $resolved = $this->features->resolve($track, async: $async);

        return response()->json($resolved);
    }

    public function listeningProfile(Request $request): JsonResponse
    {
        return response()->json($this->profile->forUser($request->user()));
    }

    public function similarRecommendations(Request $request): JsonResponse
    {
        $seed = $request->string('seed')->toString();
        abort_if($seed === '', 422, 'seed is required.');

        $limit = min(30, max(1, (int) $request->integer('limit', 12)));

        return response()->json([
            'source' => 'spotify_neighborhood',
            'seed' => $seed,
            'items' => $this->recommendations->similar($request->user(), $seed, $limit),
        ]);
    }

    public function listeningSettings(Request $request): JsonResponse
    {
        return response()->json(
            $this->sessions->settingsFor($request->user())->toPublicArray(),
        );
    }

    public function updateListeningSettings(UpdateListeningSettingsRequest $request): JsonResponse
    {
        $settings = $this->sessions->updateSettings(
            $request->user(),
            $request->validated(),
        );

        return response()->json($settings->toPublicArray());
    }
}
