<?php

namespace App\Services\Beer;

use App\Integrations\OpenBreweryDb\OpenBreweryDbIntegration;
use App\Models\Beer\BeerBrewery;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class BreweryService
{
    public function __construct(
        private readonly OpenBreweryDbIntegration $obdb,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function searchUpstream(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $key = 'obdb:search:'.md5(mb_strtolower($query));
        $ttl = max(60, (int) config('services.openbrewerydb.cache_seconds', 86400));

        $rows = Cache::remember($key, $ttl, fn () => $this->obdb->search($query, 1, 25));

        return array_map(fn (array $row) => $this->summarize($row), $rows);
    }

    public function importFromObdb(string $obdbId): BeerBrewery
    {
        $existing = BeerBrewery::query()->where('obdb_id', $obdbId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $payload = $this->obdb->find($obdbId);
        if ($payload === null) {
            abort(404, 'Brewery not found upstream.');
        }

        return BeerBrewery::query()->create([
            'obdb_id' => (string) ($payload['id'] ?? $obdbId),
            'name' => (string) ($payload['name'] ?? 'Unknown brewery'),
            'brewery_type' => $payload['brewery_type'] ?? null,
            'address_1' => $payload['address_1'] ?? null,
            'address_2' => $payload['address_2'] ?? null,
            'address_3' => $payload['address_3'] ?? null,
            'city' => $payload['city'] ?? null,
            'state_province' => $payload['state_province'] ?? null,
            'postal_code' => $payload['postal_code'] ?? null,
            'country' => $payload['country'] ?? null,
            'latitude' => $payload['latitude'] ?? null,
            'longitude' => $payload['longitude'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'website_url' => $payload['website_url'] ?? null,
            'source' => BeerBrewery::SOURCE_OPENBREWERYDB,
            'raw' => $payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createManual(User $user, array $data): BeerBrewery
    {
        return BeerBrewery::query()->create([
            'name' => $data['name'],
            'brewery_type' => $data['brewery_type'] ?? null,
            'address_1' => $data['address_1'] ?? null,
            'city' => $data['city'] ?? null,
            'state_province' => $data['state_province'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $data['country'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'phone' => $data['phone'] ?? null,
            'source' => BeerBrewery::SOURCE_MANUAL,
            'created_by_user_id' => $user->id,
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, BeerBrewery>
     */
    public function listLocal(int $perPage = 20): LengthAwarePaginator
    {
        return BeerBrewery::query()
            ->orderBy('name')
            ->paginate(max(1, min(50, $perPage)));
    }

    public function find(int $id): BeerBrewery
    {
        $brewery = BeerBrewery::query()->find($id);
        if ($brewery === null) {
            abort(404, 'Brewery not found.');
        }

        return $brewery;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function summarize(array $row): array
    {
        return [
            'obdb_id' => (string) ($row['id'] ?? ''),
            'name' => $row['name'] ?? null,
            'brewery_type' => $row['brewery_type'] ?? null,
            'city' => $row['city'] ?? null,
            'state_province' => $row['state_province'] ?? null,
            'country' => $row['country'] ?? null,
            'website_url' => $row['website_url'] ?? null,
        ];
    }
}
