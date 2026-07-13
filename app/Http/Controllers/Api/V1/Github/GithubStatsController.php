<?php

namespace App\Http\Controllers\Api\V1\Github;

use App\Http\Controllers\Controller;
use App\Services\Github\GithubStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GithubStatsController extends Controller
{
    public function __construct(
        private readonly GithubStatsService $stats,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $force = $request->boolean('refresh');

        return response()->json($this->stats->forUser($request->user(), $force));
    }
}
