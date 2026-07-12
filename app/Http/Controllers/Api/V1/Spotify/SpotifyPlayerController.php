<?php

namespace App\Http\Controllers\Api\V1\Spotify;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Spotify\AddQueueItemRequest;
use App\Http\Requests\Api\V1\Spotify\PlayerPlayRequest;
use App\Http\Requests\Api\V1\Spotify\PlayerRepeatRequest;
use App\Http\Requests\Api\V1\Spotify\PlayerSeekRequest;
use App\Http\Requests\Api\V1\Spotify\PlayerShuffleRequest;
use App\Http\Requests\Api\V1\Spotify\PlayerTransferRequest;
use App\Http\Requests\Api\V1\Spotify\PlayerVolumeRequest;
use App\Services\Spotify\SpotifyPlayerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SpotifyPlayerController extends Controller
{
    public function __construct(
        private readonly SpotifyPlayerService $player,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->player->playbackState($request->user()));
    }

    public function devices(Request $request): JsonResponse
    {
        return response()->json([
            'devices' => $this->player->devices($request->user()),
        ]);
    }

    public function transfer(PlayerTransferRequest $request): Response
    {
        $this->player->transfer($request->user(), $request->validated());

        return response()->noContent();
    }

    public function play(PlayerPlayRequest $request): Response
    {
        $this->player->play($request->user(), $request->validated());

        return response()->noContent();
    }

    public function pause(Request $request): Response
    {
        $this->player->pause($request->user(), $request->query('device_id'));

        return response()->noContent();
    }

    public function next(Request $request): Response
    {
        $this->player->next($request->user(), $request->query('device_id'));

        return response()->noContent();
    }

    public function previous(Request $request): Response
    {
        $this->player->previous($request->user(), $request->query('device_id'));

        return response()->noContent();
    }

    public function seek(PlayerSeekRequest $request): Response
    {
        $data = $request->validated();
        $this->player->seek($request->user(), (int) $data['position_ms'], $data['device_id'] ?? null);

        return response()->noContent();
    }

    public function volume(PlayerVolumeRequest $request): Response
    {
        $data = $request->validated();
        $this->player->volume($request->user(), (int) $data['volume_percent'], $data['device_id'] ?? null);

        return response()->noContent();
    }

    public function shuffle(PlayerShuffleRequest $request): Response
    {
        $data = $request->validated();
        $this->player->shuffle($request->user(), (bool) $data['state'], $data['device_id'] ?? null);

        return response()->noContent();
    }

    public function repeat(PlayerRepeatRequest $request): Response
    {
        $data = $request->validated();
        $this->player->repeat($request->user(), $data['state'], $data['device_id'] ?? null);

        return response()->noContent();
    }

    public function queue(Request $request): JsonResponse
    {
        return response()->json($this->player->queue($request->user()));
    }

    public function addQueueItem(AddQueueItemRequest $request): Response
    {
        $data = $request->validated();
        $this->player->addToQueue($request->user(), $data['uri'], $data['device_id'] ?? null);

        return response()->noContent();
    }
}
