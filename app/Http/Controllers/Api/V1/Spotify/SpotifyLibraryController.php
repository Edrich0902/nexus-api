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

        return response()->json([
            'uris' => $uris,
            'contains' => $this->library->contains($request->user(), $uris),
        ]);
    }
}
