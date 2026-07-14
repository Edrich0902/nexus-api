<?php

namespace App\Integrations\ReccoBeats;

use App\Integrations\Support\ProviderHttpClient;

/**
 * Free, unauthenticated ReccoBeats client (audio features + recommendations).
 */
class ReccoBeatsIntegration
{
    public const PROVIDER = 'reccobeats';

    public function __construct(
        private readonly ProviderHttpClient $http,
    ) {}

    public function provider(): string
    {
        return self::PROVIDER;
    }

    /**
     * @param  list<string>  $ids  Spotify track IDs and/or ReccoBeats UUIDs
     * @return list<array<string, mixed>>
     */
    public function audioFeatures(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && $id !== '')));
        if ($ids === []) {
            return [];
        }

        $payload = $this->get('/v1/audio-features', [
            'ids' => implode(',', $ids),
        ])->json();

        $content = $payload['content'] ?? null;

        return is_array($content) ? array_values(array_filter($content, 'is_array')) : [];
    }

    /**
     * @param  list<string>  $seeds  Spotify track IDs and/or ReccoBeats UUIDs
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function recommendations(array $seeds, int $size = 10, array $filters = []): array
    {
        $seeds = array_values(array_unique(array_filter($seeds, fn ($id) => is_string($id) && $id !== '')));
        if ($seeds === []) {
            return [];
        }

        $query = array_merge($filters, [
            'seeds' => implode(',', $seeds),
            'size' => max(1, min(50, $size)),
        ]);

        $payload = $this->get('/v1/track/recommendation', $query)->json();
        $content = $payload['content'] ?? $payload['recommendations'] ?? null;

        return is_array($content) ? array_values(array_filter($content, 'is_array')) : [];
    }

    /**
     * Extract Spotify track ID from a ReccoBeats href, if present.
     */
    public static function spotifyIdFromHref(?string $href): ?string
    {
        if ($href === null || $href === '') {
            return null;
        }

        if (preg_match('#open\.spotify\.com/track/([a-zA-Z0-9]+)#', $href, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = []): \Illuminate\Http\Client\Response
    {
        $base = rtrim((string) config('services.reccobeats.base_url', 'https://api.reccobeats.com'), '/');

        return $this->http->send(self::PROVIDER, 'GET', $base.$path, [
            'query' => $query,
            'timeout' => (int) config('services.reccobeats.timeout', 12),
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);
    }
}
