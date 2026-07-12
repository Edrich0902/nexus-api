<?php

namespace App\Http\Controllers\Api\V1\Github;

use App\Http\Controllers\Controller;
use App\Services\Github\GithubSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GithubSearchController extends Controller
{
    public function __construct(
        private readonly GithubSearchService $search,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $q = $request->string('q')->toString();
        $type = $request->string('type', 'repositories')->toString();

        return response()->json($this->search->search(
            $request->user(),
            $q,
            $type,
            max(1, (int) $request->query('page', 1)),
            max(1, min(20, (int) $request->query('per_page', 20))),
        ));
    }
}
