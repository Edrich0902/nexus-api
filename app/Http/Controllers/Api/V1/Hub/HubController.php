<?php

namespace App\Http\Controllers\Api\V1\Hub;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Hub\ActivityIndexRequest;
use App\Http\Requests\Api\V1\Hub\NowRequest;
use App\Http\Requests\Api\V1\Hub\PaletteBatchRequest;
use App\Http\Requests\Api\V1\Hub\PaletteRequest;
use App\Http\Requests\Api\V1\Hub\SearchRequest;
use App\Http\Resources\Api\V1\Hub\ActivityEventResource;
use App\Services\Activity\ActivityFeedService;
use App\Services\Hub\NowService;
use App\Services\Hub\SearchService;
use App\Services\Media\PaletteService;
use Illuminate\Http\JsonResponse;

/**
 * Cross-module endpoints behind the home screen and the command palette.
 */
class HubController extends Controller
{
    public function __construct(
        private readonly ActivityFeedService $activity,
        private readonly NowService $now,
        private readonly SearchService $search,
        private readonly PaletteService $palettes,
    ) {}

    public function activity(ActivityIndexRequest $request): JsonResponse
    {
        $page = $this->activity->feed(
            $request->user(),
            $request->validated('module'),
            (int) ($request->validated('limit') ?? 20),
        );

        return response()->json([
            'data' => ActivityEventResource::collection($page->items())->resolve(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function now(NowRequest $request): JsonResponse
    {
        return response()->json($this->now->for($request->user(), $request->timezone()));
    }

    public function search(SearchRequest $request): JsonResponse
    {
        $query = (string) $request->validated('q');

        return response()->json([
            'query' => $query,
            'groups' => $this->search->search($request->user(), $query),
        ]);
    }

    public function palette(PaletteRequest $request): JsonResponse
    {
        return response()->json($this->palettes->status((string) $request->validated('url')));
    }

    /**
     * Ready palettes for a page of artwork. Disallowed hosts are skipped and
     * unknown URLs are queued, so missing keys mean "not available yet".
     */
    public function palettes(PaletteBatchRequest $request): JsonResponse
    {
        $ready = array_filter($this->palettes->forUrls($request->validated('urls')));

        return response()->json(['palettes' => (object) $ready]);
    }
}
