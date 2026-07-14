<?php

namespace App\Http\Controllers\Api\V1\F1;

use App\Http\Controllers\Controller;
use App\Jobs\F1\RebuildF1HomeSnapshotJob;
use App\Jobs\F1\SyncF1ChampionshipJob;
use App\Jobs\F1\SyncF1SeasonJob;
use App\Jobs\F1\SyncF1SessionDetailJob;
use App\Services\F1\F1HomeService;
use App\Services\F1\F1OverviewService;
use App\Services\F1\F1ReplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class F1Controller extends Controller
{
    public function __construct(
        private readonly F1OverviewService $overview,
        private readonly F1HomeService $home,
        private readonly F1ReplayService $replay,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json($this->overview->status());
    }

    public function sync(Request $request): JsonResponse
    {
        $type = (string) $request->input('type', 'all');
        $year = $request->filled('year') ? (int) $request->integer('year') : null;

        match ($type) {
            'season' => SyncF1SeasonJob::dispatch($year),
            'championship' => SyncF1ChampionshipJob::dispatch($year),
            'detail' => SyncF1SessionDetailJob::dispatch(
                $request->filled('session_key') ? (int) $request->integer('session_key') : null,
            ),
            'home' => RebuildF1HomeSnapshotJob::dispatch(),
            default => tap(null, function () use ($year): void {
                SyncF1SeasonJob::dispatch($year);
                SyncF1ChampionshipJob::dispatch($year);
                SyncF1SessionDetailJob::dispatch();
            }),
        };

        return response()->json([
            'message' => 'F1 sync queued.',
            'type' => $type === '' ? 'all' : $type,
            'year' => $year,
        ]);
    }

    public function home(): JsonResponse
    {
        return response()->json($this->home->getSnapshot());
    }

    public function season(Request $request): JsonResponse
    {
        $year = $request->filled('year') ? (int) $request->integer('year') : null;

        return response()->json($this->overview->season($year));
    }

    public function standings(Request $request): JsonResponse
    {
        $year = $request->filled('year') ? (int) $request->integer('year') : null;

        return response()->json($this->overview->standings($year));
    }

    public function meeting(int $meetingKey): JsonResponse
    {
        return response()->json($this->overview->meeting($meetingKey));
    }

    public function session(int $sessionKey): JsonResponse
    {
        return response()->json($this->overview->session($sessionKey));
    }

    public function analysis(int $sessionKey): JsonResponse
    {
        return response()->json($this->overview->analysis($sessionKey));
    }

    public function replay(Request $request, int $sessionKey): JsonResponse
    {
        $driverNumber = $request->filled('driver_number')
            ? (int) $request->integer('driver_number')
            : null;

        return response()->json($this->replay->payload($sessionKey, $driverNumber));
    }

    public function replayStatus(int $sessionKey): JsonResponse
    {
        return response()->json($this->replay->status($sessionKey));
    }

    public function replayRetry(int $sessionKey): JsonResponse
    {
        return response()->json($this->replay->retry($sessionKey));
    }
}
