<?php

namespace App\Http\Controllers\Api\V1\Beer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Beer\ImportBreweryRequest;
use App\Http\Requests\Api\V1\Beer\StoreBeerRequest;
use App\Http\Requests\Api\V1\Beer\StoreManualBreweryRequest;
use App\Http\Requests\Api\V1\Beer\UpdateBeerRequest;
use App\Http\Resources\Api\V1\Beer\BeerBeerResource;
use App\Http\Resources\Api\V1\Beer\BeerBreweryResource;
use App\Services\Beer\BeerService;
use App\Services\Beer\BreweryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BeerController extends Controller
{
    public function __construct(
        private readonly BeerService $beers,
        private readonly BreweryService $breweries,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BeerBeerResource::collection(
            $this->beers->list($request->user(), [
                'q' => $request->query('q'),
                'per_page' => $request->integer('per_page', 20),
            ]),
        );
    }

    public function store(StoreBeerRequest $request): JsonResponse
    {
        $beer = $this->beers->create($request->user(), $request->validated());

        return (new BeerBeerResource($beer))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $beer): BeerBeerResource
    {
        return new BeerBeerResource($this->beers->findOwned($request->user(), $beer));
    }

    public function update(UpdateBeerRequest $request, int $beer): BeerBeerResource
    {
        $model = $this->beers->findOwned($request->user(), $beer);

        return new BeerBeerResource(
            $this->beers->update($request->user(), $model, $request->validated()),
        );
    }

    public function destroy(Request $request, int $beer): JsonResponse
    {
        $model = $this->beers->findOwned($request->user(), $beer);
        $this->beers->delete($request->user(), $model);

        return response()->json(['message' => 'Beer deleted.']);
    }

    public function styles(): JsonResponse
    {
        return response()->json([
            'styles' => $this->beers->styles()->map(fn ($s) => [
                'id' => $s->id,
                'slug' => $s->slug,
                'name' => $s->name,
                'family' => $s->family,
            ])->values(),
        ]);
    }

    public function searchBreweries(Request $request): JsonResponse
    {
        return response()->json([
            'results' => $this->breweries->searchUpstream((string) $request->query('q', '')),
        ]);
    }

    public function importBrewery(ImportBreweryRequest $request): JsonResponse
    {
        $brewery = $this->breweries->importFromObdb((string) $request->validated('obdb_id'));

        return (new BeerBreweryResource($brewery))->response()->setStatusCode(201);
    }

    public function storeBrewery(StoreManualBreweryRequest $request): JsonResponse
    {
        $brewery = $this->breweries->createManual($request->user(), $request->validated());

        return (new BeerBreweryResource($brewery))->response()->setStatusCode(201);
    }

    public function listBreweries(Request $request): AnonymousResourceCollection
    {
        return BeerBreweryResource::collection(
            $this->breweries->listLocal($request->integer('per_page', 20)),
        );
    }

    public function showBrewery(int $brewery): BeerBreweryResource
    {
        return new BeerBreweryResource($this->breweries->find($brewery));
    }
}
