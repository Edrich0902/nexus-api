<?php

namespace App\Http\Controllers\Api\V1\FoodDrink;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FoodDrink\StorePairingRequest;
use App\Http\Resources\Api\V1\FoodDrink\FoodDrinkPairingResource;
use App\Services\FoodDrink\FoodDrinkDashboardService;
use App\Services\FoodDrink\PairingService;
use App\Services\FoodDrink\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class FoodDrinkController extends Controller
{
    public function __construct(
        private readonly FoodDrinkDashboardService $dashboard,
        private readonly PairingService $pairings,
        private readonly RecommendationService $recommendations,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        return response()->json(
            $this->dashboard->dashboard($request->user()),
        );
    }

    public function pairings(Request $request): AnonymousResourceCollection
    {
        return FoodDrinkPairingResource::collection(
            $this->pairings->list($request->user()),
        );
    }

    public function storePairing(StorePairingRequest $request): JsonResponse
    {
        $pairing = $this->pairings->create($request->user(), $request->validated());
        Cache::forget("food-drink:dashboard:{$request->user()->id}");

        return (new FoodDrinkPairingResource($pairing))
            ->response()
            ->setStatusCode(201);
    }

    public function destroyPairing(Request $request, int $pairing): JsonResponse
    {
        $this->pairings->delete($request->user(), $pairing);
        Cache::forget("food-drink:dashboard:{$request->user()->id}");

        return response()->json(['message' => 'Pairing deleted.']);
    }

    public function suggestions(Request $request): JsonResponse
    {
        return response()->json([
            'suggestions' => $this->recommendations->suggest(
                $request->user(),
                $request->integer('limit', 10),
            ),
        ]);
    }
}
