<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminOpsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOpsController extends Controller
{
    public function __construct(
        private readonly AdminOpsService $ops,
    ) {}

    public function overview(): JsonResponse
    {
        return response()->json($this->ops->overview());
    }

    public function jobs(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->integer('per_page', 25)));
        $queue = $request->filled('queue') ? (string) $request->string('queue') : null;

        return response()->json($this->ops->pendingJobs($perPage, $queue));
    }

    public function failedJobs(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->integer('per_page', 25)));

        return response()->json($this->ops->failedJobs($perPage));
    }

    public function retryFailedJob(string $uuid): JsonResponse
    {
        $this->ops->retryFailedJob($uuid);

        return response()->json(['message' => 'Job queued for retry.', 'uuid' => $uuid]);
    }

    public function forgetFailedJob(string $uuid): JsonResponse
    {
        $this->ops->forgetFailedJob($uuid);

        return response()->json(['message' => 'Failed job forgotten.', 'uuid' => $uuid]);
    }

    public function recentJobs(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->integer('limit', 40)));

        return response()->json([
            'data' => $this->ops->recentJobs($limit),
        ]);
    }
}
