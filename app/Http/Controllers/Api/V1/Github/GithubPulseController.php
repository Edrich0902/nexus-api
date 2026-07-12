<?php

namespace App\Http\Controllers\Api\V1\Github;

use App\Http\Controllers\Controller;
use App\Services\Github\GithubPulseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GithubPulseController extends Controller
{
    public function __construct(
        private readonly GithubPulseService $pulse,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json($this->pulse->pulse($request->user()));
    }
}
