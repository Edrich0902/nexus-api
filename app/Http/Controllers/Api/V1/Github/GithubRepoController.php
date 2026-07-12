<?php

namespace App\Http\Controllers\Api\V1\Github;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Github\GithubRepoResource;
use App\Services\Github\GithubRepoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GithubRepoController extends Controller
{
    public function __construct(
        private readonly GithubRepoService $repos,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->repos->listRepos($request->user());
    }

    public function show(Request $request, string $owner, string $repo): GithubRepoResource
    {
        return new GithubRepoResource($this->repos->findRepo($request->user(), $owner, $repo));
    }

    public function branches(Request $request, string $owner, string $repo): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 100)));

        return response()->json([
            'items' => $this->repos->branches($request->user(), $owner, $repo, $page, $perPage),
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    public function commits(Request $request, string $owner, string $repo): JsonResponse
    {
        $sha = $request->query('sha');
        $sha = is_string($sha) ? $sha : null;

        return response()->json($this->repos->commits(
            $request->user(),
            $owner,
            $repo,
            $sha,
            max(1, (int) $request->query('page', 1)),
            max(1, min(100, (int) $request->query('per_page', 30))),
        ));
    }

    public function compare(Request $request, string $owner, string $repo): JsonResponse
    {
        $base = $request->string('base')->toString();
        $head = $request->string('head')->toString();

        if ($base === '' || $head === '') {
            return response()->json([
                'message' => 'Both base and head query parameters are required.',
            ], 422);
        }

        return response()->json($this->repos->compare(
            $request->user(),
            $owner,
            $repo,
            $base,
            $head,
        ));
    }
}
