<?php

namespace App\Integrations\SportsDb;

use App\Integrations\Support\ProviderHttpClient;
use Illuminate\Http\Client\Response;

/**
 * TheSportsDB API-key client (no OAuth). Free tier key defaults to "123".
 */
class SportsDbIntegration
{
    public const PROVIDER = 'sportsdb';

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
    public function lookupLeague(int $leagueId): array
    {
        $payload = $this->get('lookupleague.php', ['id' => $leagueId])->json();

        return $this->asList($payload['leagues'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listNextLeagueEvents(int $leagueId): array
    {
        $payload = $this->get('eventsnextleague.php', ['id' => $leagueId])->json();

        return $this->asList($payload['events'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPastLeagueEvents(int $leagueId): array
    {
        $payload = $this->get('eventspastleague.php', ['id' => $leagueId])->json();

        return $this->asList($payload['events'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eventsOnDay(string $date, ?string $sportApiName = null): array
    {
        $query = ['d' => $date];
        if ($sportApiName !== null && $sportApiName !== '') {
            $query['s'] = $sportApiName;
        }

        $payload = $this->get('eventsday.php', $query)->json();

        return $this->asList($payload['events'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lookupTable(int $leagueId, ?string $season = null): array
    {
        $query = ['l' => $leagueId];
        if ($season !== null && $season !== '') {
            $query['s'] = $season;
        }

        $payload = $this->get('lookuptable.php', $query)->json();

        return $this->asList($payload['table'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lookupEvent(int $eventId): ?array
    {
        $payload = $this->get('lookupevent.php', ['id' => $eventId])->json();
        $events = $this->asList($payload['events'] ?? null);

        return $events[0] ?? null;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function get(string $endpoint, array $query = []): Response
    {
        $base = rtrim((string) config('services.sportsdb.base_url'), '/');
        $key = (string) config('services.sportsdb.api_key', '123');
        $url = "{$base}/{$key}/{$endpoint}";

        return $this->http->send(self::PROVIDER, 'GET', $url, [
            'query' => $query,
            'timeout' => 12,
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
