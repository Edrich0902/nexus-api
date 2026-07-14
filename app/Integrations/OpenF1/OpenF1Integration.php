<?php

namespace App\Integrations\OpenF1;

use App\Integrations\Support\ProviderHttpClient;
use Illuminate\Http\Client\Response;

/**
 * OpenF1 public API client (historical free; no OAuth).
 */
class OpenF1Integration
{
    public const PROVIDER = 'openf1';

    public function __construct(
        private readonly ProviderHttpClient $http,
    ) {}

    public function provider(): string
    {
        return self::PROVIDER;
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function meetings(array $query = []): array
    {
        return $this->list('meetings', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function sessions(array $query = []): array
    {
        return $this->list('sessions', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function drivers(array $query = []): array
    {
        return $this->list('drivers', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function sessionResult(array $query = []): array
    {
        return $this->listOptional('session_result', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function startingGrid(array $query = []): array
    {
        return $this->listOptional('starting_grid', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function championshipDrivers(array $query = []): array
    {
        return $this->listOptional('championship_drivers', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function championshipTeams(array $query = []): array
    {
        return $this->listOptional('championship_teams', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function laps(array $query = []): array
    {
        return $this->listOptional('laps', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function pits(array $query = []): array
    {
        return $this->listOptional('pit', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function stints(array $query = []): array
    {
        return $this->listOptional('stints', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function positions(array $query = []): array
    {
        return $this->listOptional('position', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function raceControl(array $query = []): array
    {
        return $this->listOptional('race_control', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function weather(array $query = []): array
    {
        return $this->listOptional('weather', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function overtakes(array $query = []): array
    {
        return $this->listOptional('overtakes', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function location(array $query = []): array
    {
        return $this->list('location', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function carData(array $query = []): array
    {
        return $this->list('car_data', $query);
    }

    /**
     * Soft-fail list: missing endpoints (HTTP 404) become an empty collection.
     *
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function listOptional(string $endpoint, array $query = []): array
    {
        return $this->list($endpoint, $query, allowNotFound: true);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    private function list(string $endpoint, array $query = [], bool $allowNotFound = false): array
    {
        try {
            $payload = $this->get($endpoint, $query)->json();
        } catch (\App\Integrations\Exceptions\IntegrationException $e) {
            if ($allowNotFound && $e->statusCode === 404) {
                return [];
            }

            throw $e;
        }

        if (! is_array($payload)) {
            return [];
        }

        // OpenF1 sometimes returns {"detail": "..."} error objects with 2xx — treat as empty.
        if ($payload !== [] && array_is_list($payload) === false) {
            return [];
        }

        $items = [];
        foreach ($payload as $row) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function get(string $endpoint, array $query = []): Response
    {
        $base = rtrim((string) config('services.openf1.base_url'), '/');
        $url = "{$base}/{$endpoint}";
        $timeout = (int) config('services.openf1.timeout', 20);

        return $this->http->send(self::PROVIDER, 'GET', $url, [
            'query' => $query,
            'timeout' => $timeout,
        ]);
    }
}
