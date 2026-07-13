<?php

namespace App\Http\Controllers\Api\V1\Sports;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Sports\SportsEventResource;
use App\Jobs\Sports\RebuildSportsHomeSnapshotJob;
use App\Jobs\Sports\SyncFootballStandingsJob;
use App\Jobs\Sports\SyncSportsDayJob;
use App\Jobs\Sports\SyncSportsFixturesJob;
use App\Jobs\Sports\SyncSportsLeaguesJob;
use App\Services\Sports\SportsHomeService;
use App\Services\Sports\SportsOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SportsController extends Controller
{
    public function __construct(
        private readonly SportsOverviewService $overview,
        private readonly SportsHomeService $home,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json($this->overview->status());
    }

    public function sync(Request $request): JsonResponse
    {
        $type = (string) $request->input('type', 'all');

        match ($type) {
            'leagues' => SyncSportsLeaguesJob::dispatch(),
            'fixtures' => SyncSportsFixturesJob::dispatch(),
            'day' => SyncSportsDayJob::dispatch(),
            'standings' => SyncFootballStandingsJob::dispatch(),
            'home' => RebuildSportsHomeSnapshotJob::dispatch(),
            default => tap(null, function (): void {
                SyncSportsLeaguesJob::dispatch();
                SyncSportsFixturesJob::dispatch();
                SyncSportsDayJob::dispatch();
                SyncFootballStandingsJob::dispatch();
            }),
        };

        return response()->json([
            'message' => 'Sports sync queued.',
            'type' => $type === '' ? 'all' : $type,
        ]);
    }

    public function home(): JsonResponse
    {
        return response()->json($this->home->getSnapshot());
    }

    public function sport(string $sport): JsonResponse
    {
        return response()->json($this->overview->overview($sport));
    }

    public function events(Request $request, string $sport): AnonymousResourceCollection
    {
        $perPage = (int) $request->integer('per_page', 20);

        return SportsEventResource::collection(
            $this->overview->events($sport, $perPage),
        );
    }

    public function event(int $id): SportsEventResource
    {
        return new SportsEventResource($this->overview->event($id));
    }
}
