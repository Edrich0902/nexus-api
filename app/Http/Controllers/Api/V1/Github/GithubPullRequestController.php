<?php

namespace App\Http\Controllers\Api\V1\Github;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Github\MergeGithubPullRequest;
use App\Http\Requests\Api\V1\Github\StoreGithubPullRequest;
use App\Services\Github\GithubPullRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GithubPullRequestController extends Controller
{
    public function __construct(
        private readonly GithubPullRequestService $pulls,
    ) {}

    public function inbox(Request $request): JsonResponse
    {
        $state = $request->string('state', 'open')->toString();

        return response()->json($this->pulls->inbox(
            $request->user(),
            $state,
            max(1, (int) $request->query('page', 1)),
            max(1, min(100, (int) $request->query('per_page', 30))),
        ));
    }

    public function index(Request $request, string $owner, string $repo): JsonResponse
    {
        $state = $request->string('state', 'open')->toString();

        return response()->json($this->pulls->listForRepo(
            $request->user(),
            $owner,
            $repo,
            $state,
            max(1, (int) $request->query('page', 1)),
            max(1, min(100, (int) $request->query('per_page', 30))),
        ));
    }

    public function show(Request $request, string $owner, string $repo, int $number): JsonResponse
    {
        return response()->json($this->pulls->show($request->user(), $owner, $repo, $number));
    }

    public function files(Request $request, string $owner, string $repo, int $number): JsonResponse
    {
        return response()->json($this->pulls->files(
            $request->user(),
            $owner,
            $repo,
            $number,
            max(1, (int) $request->query('page', 1)),
            max(1, min(100, (int) $request->query('per_page', 100))),
        ));
    }

    public function store(StoreGithubPullRequest $request, string $owner, string $repo): JsonResponse
    {
        $pull = $this->pulls->create($request->user(), $owner, $repo, $request->validated());

        return response()->json($pull, 201);
    }

    public function merge(MergeGithubPullRequest $request, string $owner, string $repo, int $number): JsonResponse
    {
        return response()->json($this->pulls->merge(
            $request->user(),
            $owner,
            $repo,
            $number,
            $request->validated(),
        ));
    }
}
