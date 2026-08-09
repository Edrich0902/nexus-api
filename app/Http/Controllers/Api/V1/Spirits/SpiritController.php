<?php

namespace App\Http\Controllers\Api\V1\Spirits;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Analysis\AnalyseDrinkRequest;
use App\Http\Requests\Api\V1\Spirits\StoreSpiritRequest;
use App\Http\Requests\Api\V1\Spirits\UpdateSpiritRequest;
use App\Http\Resources\Api\V1\Spirits\SpiritResource;
use App\Services\Analysis\DrinkAnalysisService;
use App\Services\Spirit\SpiritService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SpiritController extends Controller
{
    public function __construct(
        private readonly SpiritService $spirits,
        private readonly DrinkAnalysisService $analysis,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return SpiritResource::collection(
            $this->spirits->list($request->user(), [
                'q' => $request->query('q'),
                'per_page' => $request->integer('per_page', 20),
            ]),
        );
    }

    public function store(StoreSpiritRequest $request): JsonResponse
    {
        $spirit = $this->spirits->create($request->user(), $request->validated());

        return (new SpiritResource($spirit))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $spirit): SpiritResource
    {
        return new SpiritResource($this->spirits->findOwned($request->user(), $spirit));
    }

    public function update(UpdateSpiritRequest $request, int $spirit): SpiritResource
    {
        $model = $this->spirits->findOwned($request->user(), $spirit);

        return new SpiritResource(
            $this->spirits->update($request->user(), $model, $request->validated()),
        );
    }

    public function destroy(Request $request, int $spirit): JsonResponse
    {
        $model = $this->spirits->findOwned($request->user(), $spirit);
        $this->spirits->delete($request->user(), $model);

        return response()->json(['message' => 'Spirit deleted.']);
    }

    public function analyse(AnalyseDrinkRequest $request, int $spirit): JsonResponse
    {
        $model = $this->spirits->findOwned($request->user(), $spirit);
        $result = $this->analysis->requestAnalysis(
            $request->user(),
            $model,
            (bool) $request->boolean('force'),
            $request->validated('extra_context'),
        );

        $response = (new SpiritResource($result['model']))->response();

        return $result['status'] === 'pending'
            ? $response->setStatusCode(202)
            : $response;
    }
}
