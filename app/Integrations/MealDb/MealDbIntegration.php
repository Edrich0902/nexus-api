<?php

namespace App\Integrations\MealDb;

use App\Integrations\Support\ProviderHttpClient;
use Illuminate\Http\Client\Response;

/**
 * TheMealDB API-key client (key in URL path, same shape as SportsDB).
 */
class MealDbIntegration
{
    public const PROVIDER = 'mealdb';

    public function __construct(
        private readonly ProviderHttpClient $http,
    ) {}

    public function provider(): string
    {
        return self::PROVIDER;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchByName(string $query): array
    {
        $payload = $this->get('search.php', ['s' => $query])->json();

        return $this->asList($payload['meals'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lookup(string $mealId): ?array
    {
        $payload = $this->get('lookup.php', ['i' => $mealId])->json();
        $meals = $this->asList($payload['meals'] ?? null);

        return $meals[0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function random(): ?array
    {
        $payload = $this->get('random.php')->json();
        $meals = $this->asList($payload['meals'] ?? null);

        return $meals[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function filterByCategory(string $category): array
    {
        $payload = $this->get('filter.php', ['c' => $category])->json();

        return $this->asList($payload['meals'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function filterByArea(string $area): array
    {
        $payload = $this->get('filter.php', ['a' => $area])->json();

        return $this->asList($payload['meals'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function filterByIngredient(string $ingredient): array
    {
        $payload = $this->get('filter.php', ['i' => $ingredient])->json();

        return $this->asList($payload['meals'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCategories(): array
    {
        $payload = $this->get('categories.php')->json();

        return $this->asList($payload['categories'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAreas(): array
    {
        $payload = $this->get('list.php', ['a' => 'list'])->json();

        return $this->asList($payload['meals'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listIngredients(): array
    {
        $payload = $this->get('list.php', ['i' => 'list'])->json();

        return $this->asList($payload['meals'] ?? null);
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function get(string $endpoint, array $query = []): Response
    {
        $base = rtrim((string) config('services.mealdb.base_url'), '/');
        $key = (string) config('services.mealdb.api_key', '1');
        $url = "{$base}/{$key}/{$endpoint}";

        return $this->http->send(self::PROVIDER, 'GET', $url, [
            'query' => $query,
            'timeout' => (int) config('services.mealdb.timeout', 12),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function asList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }

        return $items;
    }
}
