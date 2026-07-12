<?php

namespace App\Http\Controllers\Api\V1\Github;

use App\Http\Controllers\Controller;
use App\Services\Github\GithubRepoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GithubMeController extends Controller
{
    public function __construct(
        private readonly GithubRepoService $repos,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->repos->profile($request->user()));
    }
}
