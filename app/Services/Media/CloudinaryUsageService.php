<?php

namespace App\Services\Media;

use App\Integrations\Cloudinary\CloudinaryClient;
use Illuminate\Support\Facades\Cache;

class CloudinaryUsageService
{
    public function __construct(
        private readonly CloudinaryClient $cloudinary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(bool $refresh = false): array
    {
        $ttl = max(60, (int) config('media.usage_cache_seconds', 1800));
        $key = 'media:cloudinary:usage';

        if ($refresh) {
            Cache::forget($key);
        }

        /** @var array<string, mixed> $snapshot */
        $snapshot = Cache::remember($key, $ttl, function (): array {
            $raw = $this->cloudinary->usage();

            return $this->normalize($raw);
        });

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalize(array $raw): array
    {
        $credits = $this->asObject($raw['credits'] ?? null);
        $storage = $this->asObject($raw['storage'] ?? null);
        $bandwidth = $this->asObject($raw['bandwidth'] ?? null);
        $transformations = $this->asObject($raw['transformations'] ?? null);
        $objects = $this->asObject($raw['objects'] ?? null);

        // Cloudinary returns these as bare integers (not {usage: N}).
        $resources = $this->asInt($raw['resources'] ?? null);
        $derived = $this->asInt($raw['derived_resources'] ?? null);
        $requests = $this->asInt($raw['requests'] ?? null);

        // Prefer objects.usage when present (originals + derived); otherwise sum.
        $totalObjects = isset($objects['usage'])
            ? (int) $objects['usage']
            : ($resources + $derived);

        return [
            'plan' => $raw['plan'] ?? null,
            'last_updated' => $raw['last_updated'] ?? null,
            'credits' => [
                'usage' => (float) ($credits['usage'] ?? 0),
                'limit' => isset($credits['limit']) ? (float) $credits['limit'] : null,
                'used_percent' => isset($credits['used_percent']) ? (float) $credits['used_percent'] : null,
            ],
            'storage' => [
                'usage' => (int) ($storage['usage'] ?? 0),
                'credits_usage' => isset($storage['credits_usage']) ? (float) $storage['credits_usage'] : null,
            ],
            'bandwidth' => [
                'usage' => (int) ($bandwidth['usage'] ?? 0),
                'credits_usage' => isset($bandwidth['credits_usage']) ? (float) $bandwidth['credits_usage'] : null,
            ],
            'transformations' => [
                'usage' => (int) ($transformations['usage'] ?? 0),
                'credits_usage' => isset($transformations['credits_usage']) ? (float) $transformations['credits_usage'] : null,
            ],
            'resources' => $resources,
            'derived_resources' => $derived,
            'objects' => $totalObjects,
            'requests' => $requests,
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function asObject(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function asInt(mixed $value): int
    {
        if (is_array($value)) {
            return (int) ($value['usage'] ?? 0);
        }

        return is_numeric($value) ? (int) $value : 0;
    }
}
