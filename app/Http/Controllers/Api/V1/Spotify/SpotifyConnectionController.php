<?php

namespace App\Http\Controllers\Api\V1\Spotify;

use App\Http\Controllers\Controller;
use App\Integrations\Exceptions\IntegrationException;
use App\Services\Spotify\SpotifyConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SpotifyConnectionController extends Controller
{
    public function __construct(
        private readonly SpotifyConnectionService $connections,
    ) {}

    public function connect(Request $request): JsonResponse
    {
        return response()->json($this->connections->connectUrl($request->user()));
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->connections->handleCallbackError($request->string('error')->toString());
        }

        $code = $request->string('code')->toString();
        $state = $request->string('state')->toString();

        if ($code === '' || $state === '') {
            return $this->connections->handleCallbackError('missing_parameters');
        }

        try {
            return $this->connections->handleCallback($code, $state);
        } catch (IntegrationException $e) {
            return $this->connections->handleCallbackError('oauth_failed');
        }
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->connections->status($request->user()));
    }

    public function disconnect(Request $request): Response
    {
        $this->connections->disconnect($request->user());

        return response()->noContent();
    }

    public function sync(Request $request): JsonResponse
    {
        $this->connections->dispatchSync($request->user());

        return response()->json([
            'message' => 'Spotify sync queued.',
        ]);
    }
}
