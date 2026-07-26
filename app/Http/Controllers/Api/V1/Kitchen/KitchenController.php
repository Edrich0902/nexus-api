<?php

namespace App\Http\Controllers\Api\V1\Kitchen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Kitchen\SaveKitchenRecipeRequest;
use App\Http\Requests\Api\V1\Kitchen\UpdateKitchenRecipeRequest;
use App\Http\Resources\Api\V1\Kitchen\KitchenRecipeResource;
use App\Services\Kitchen\KitchenRecipeService;
use App\Services\Kitchen\MealCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class KitchenController extends Controller
{
    public function __construct(
        private readonly MealCatalogService $catalog,
        private readonly KitchenRecipeService $recipes,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $q = (string) $request->query('q', '');

        return response()->json([
            'results' => $this->catalog->search($q),
        ]);
    }

    public function browse(Request $request): JsonResponse
    {
        return response()->json([
            'results' => $this->catalog->browse(
                $request->query('category'),
                $request->query('area'),
                $request->query('ingredient'),
            ),
        ]);
    }

    public function filters(): JsonResponse
    {
        return response()->json($this->catalog->filters());
    }

    public function randomMeal(): JsonResponse
    {
        $meal = $this->catalog->random()->load('ingredients');

        return response()->json([
            'meal' => $this->mealPayload($meal),
        ]);
    }

    public function showMeal(string $mealdbId): JsonResponse
    {
        $meal = $this->catalog->importByMealdbId($mealdbId)->load('ingredients');

        return response()->json([
            'meal' => $this->mealPayload($meal),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return KitchenRecipeResource::collection(
            $this->recipes->list($request->user(), [
                'q' => $request->query('q'),
                'favourite' => $request->boolean('favourite'),
                'per_page' => $request->integer('per_page', 20),
            ]),
        );
    }

    public function store(SaveKitchenRecipeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $recipe = $this->recipes->saveFromMealdb(
            $request->user(),
            (string) $data['mealdb_id'],
            isset($data['rating']) ? (float) $data['rating'] : null,
            $data['notes'] ?? null,
        );

        return (new KitchenRecipeResource($recipe))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $recipe): KitchenRecipeResource
    {
        return new KitchenRecipeResource(
            $this->recipes->findOwned($request->user(), $recipe),
        );
    }

    public function update(UpdateKitchenRecipeRequest $request, int $recipe): KitchenRecipeResource
    {
        $model = $this->recipes->findOwned($request->user(), $recipe);

        return new KitchenRecipeResource(
            $this->recipes->update($request->user(), $model, $request->validated()),
        );
    }

    public function cooked(Request $request, int $recipe): KitchenRecipeResource
    {
        $model = $this->recipes->findOwned($request->user(), $recipe);

        return new KitchenRecipeResource(
            $this->recipes->markCooked($request->user(), $model),
        );
    }

    public function destroy(Request $request, int $recipe): JsonResponse
    {
        $model = $this->recipes->findOwned($request->user(), $recipe);
        $this->recipes->delete($request->user(), $model);

        return response()->json(['message' => 'Recipe removed.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function mealPayload(\App\Models\MealCatalog\MealCatalogMeal $meal): array
    {
        return [
            'id' => $meal->id,
            'mealdb_id' => $meal->mealdb_id,
            'name' => $meal->name,
            'category' => $meal->category,
            'area' => $meal->area,
            'instructions' => $meal->instructions,
            'thumb_url' => $meal->thumb_url,
            'tags' => $meal->tags,
            'youtube_url' => $meal->youtube_url,
            'source_url' => $meal->source_url,
            'ingredients' => $meal->ingredients->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'measure' => $i->pivot->measure ?? null,
                'position' => $i->pivot->position ?? null,
            ])->values()->all(),
        ];
    }
}
