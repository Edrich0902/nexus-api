<?php

namespace App\Http\Controllers\Api\V1\Cellar;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Analysis\AnalyseDrinkRequest;
use App\Http\Requests\Api\V1\Cellar\StoreCellarWineRequest;
use App\Http\Requests\Api\V1\Cellar\StoreCellarWineTastingRequest;
use App\Http\Requests\Api\V1\Cellar\UpdateCellarWineRequest;
use App\Http\Requests\Api\V1\Cellar\UpdateCellarWineTastingRequest;
use App\Http\Resources\Api\V1\Cellar\CellarWineResource;
use App\Http\Resources\Api\V1\Cellar\CellarWineTastingResource;
use App\Services\Analysis\DrinkAnalysisService;
use App\Services\Cellar\CellarWineService;
use App\Services\Cellar\WineTastingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CellarController extends Controller
{
    public function __construct(
        private readonly CellarWineService $wines,
        private readonly WineTastingService $tastings,
        private readonly DrinkAnalysisService $analysis,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return CellarWineResource::collection(
            $this->wines->list($request->user(), [
                'match_status' => $request->query('match_status'),
                'q' => $request->query('q'),
                'per_page' => $request->integer('per_page', 20),
            ]),
        );
    }

    public function store(StoreCellarWineRequest $request): JsonResponse
    {
        $wine = $this->wines->create($request->user(), $request->validated());

        return (new CellarWineResource($wine->loadCount('tastings')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $wine): CellarWineResource
    {
        return new CellarWineResource(
            $this->wines->findOwned($request->user(), $wine),
        );
    }

    public function update(UpdateCellarWineRequest $request, int $wine): CellarWineResource
    {
        $model = $this->wines->findOwned($request->user(), $wine);

        return new CellarWineResource(
            $this->wines->update($request->user(), $model, $request->validated()),
        );
    }

    public function destroy(Request $request, int $wine): JsonResponse
    {
        $model = $this->wines->findOwned($request->user(), $wine);
        $this->wines->delete($request->user(), $model);

        return response()->json(['message' => 'Wine deleted.']);
    }

    public function analyse(AnalyseDrinkRequest $request, int $wine): JsonResponse
    {
        $model = $this->wines->findOwned($request->user(), $wine);
        $result = $this->analysis->requestAnalysis(
            $request->user(),
            $model,
            (bool) $request->boolean('force'),
            $request->validated('extra_context'),
        );

        $resource = new CellarWineResource($result['model']->loadCount('tastings'));
        $response = $resource->response();

        return $result['status'] === 'pending'
            ? $response->setStatusCode(202)
            : $response;
    }

    public function storeTasting(StoreCellarWineTastingRequest $request, int $wine): JsonResponse
    {
        $model = $this->wines->findOwned($request->user(), $wine);

        return (new CellarWineTastingResource(
            $this->tastings->create($request->user(), $model, $request->validated()),
        ))->response()->setStatusCode(201);
    }

    public function updateTasting(UpdateCellarWineTastingRequest $request, int $tasting): CellarWineTastingResource
    {
        $model = $this->tastings->findOwned($request->user(), $tasting);

        return new CellarWineTastingResource(
            $this->tastings->update($request->user(), $model, $request->validated()),
        );
    }

    public function destroyTasting(Request $request, int $tasting): JsonResponse
    {
        $model = $this->tastings->findOwned($request->user(), $tasting);
        $this->tastings->delete($request->user(), $model);

        return response()->json(['message' => 'Tasting deleted.']);
    }
}
