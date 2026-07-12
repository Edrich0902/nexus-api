<?php

namespace App\Services\Spotify;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Spotify\SpotifyIntegration;
use App\Models\User;

class SpotifyPlayerService
{
    public function __construct(
        private readonly SpotifyIntegration $spotify,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function playbackState(User $user): array
    {
        $connection = $this->spotify->requireConnection($user);
        $response = $this->spotify->get($connection, '/me/player');

        if ($response->status() === 204 || $response->body() === '') {
            return [
                'is_playing' => false,
                'device' => null,
                'item' => null,
                'progress_ms' => null,
                'shuffle_state' => false,
                'repeat_state' => 'off',
                'message' => 'No active device',
            ];
        }

        return $this->normalizePlayback($response->json() ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function devices(User $user): array
    {
        $connection = $this->spotify->requireConnection($user);
        $response = $this->spotify->get($connection, '/me/player/devices');
        $devices = $response->json('devices') ?? [];

        return is_array($devices) ? array_values($devices) : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function transfer(User $user, array $payload): void
    {
        $connection = $this->spotify->requireConnection($user);
        $this->spotify->put($connection, '/me/player', [
            'device_ids' => $payload['device_ids'],
            'play' => (bool) ($payload['play'] ?? false),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function play(User $user, array $payload = []): void
    {
        $connection = $this->spotify->requireConnection($user);
        $query = [];
        if (isset($payload['device_id'])) {
            $query['device_id'] = $payload['device_id'];
            unset($payload['device_id']);
        }

        $body = array_filter([
            'context_uri' => $payload['context_uri'] ?? null,
            'uris' => $payload['uris'] ?? null,
            'offset' => $payload['offset'] ?? null,
            'position_ms' => $payload['position_ms'] ?? null,
        ], fn ($value) => $value !== null);

        try {
            $this->spotify->put($connection, '/me/player/play', $body, $query);
        } catch (IntegrationException $e) {
            $this->rethrowDeviceErrors($e);
        }
    }

    public function pause(User $user, ?string $deviceId = null): void
    {
        $connection = $this->spotify->requireConnection($user);
        $query = $deviceId ? ['device_id' => $deviceId] : [];

        try {
            $this->spotify->put($connection, '/me/player/pause', [], $query);
        } catch (IntegrationException $e) {
            $this->rethrowDeviceErrors($e);
        }
    }

    public function next(User $user, ?string $deviceId = null): void
    {
        $connection = $this->spotify->requireConnection($user);
        $query = $deviceId ? ['device_id' => $deviceId] : [];
        $this->spotify->post($connection, '/me/player/next', [], $query);
    }

    public function previous(User $user, ?string $deviceId = null): void
    {
        $connection = $this->spotify->requireConnection($user);
        $query = $deviceId ? ['device_id' => $deviceId] : [];
        $this->spotify->post($connection, '/me/player/previous', [], $query);
    }

    public function seek(User $user, int $positionMs, ?string $deviceId = null): void
    {
        $connection = $this->spotify->requireConnection($user);
        $query = ['position_ms' => $positionMs];
        if ($deviceId) {
            $query['device_id'] = $deviceId;
        }
        $this->spotify->put($connection, '/me/player/seek', [], $query);
    }

    public function volume(User $user, int $percent, ?string $deviceId = null): void
    {
        $connection = $this->spotify->requireConnection($user);
        $query = ['volume_percent' => max(0, min(100, $percent))];
        if ($deviceId) {
            $query['device_id'] = $deviceId;
        }
        $this->spotify->put($connection, '/me/player/volume', [], $query);
    }

    public function shuffle(User $user, bool $state, ?string $deviceId = null): void
    {
        $connection = $this->spotify->requireConnection($user);
        $query = ['state' => $state ? 'true' : 'false'];
        if ($deviceId) {
            $query['device_id'] = $deviceId;
        }
        $this->spotify->put($connection, '/me/player/shuffle', [], $query);
    }

    public function repeat(User $user, string $state, ?string $deviceId = null): void
    {
        $connection = $this->spotify->requireConnection($user);
        $query = ['state' => $state];
        if ($deviceId) {
            $query['device_id'] = $deviceId;
        }
        $this->spotify->put($connection, '/me/player/repeat', [], $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function queue(User $user): array
    {
        $connection = $this->spotify->requireConnection($user);
        $response = $this->spotify->get($connection, '/me/player/queue');

        return $response->json() ?? [
            'currently_playing' => null,
            'queue' => [],
        ];
    }

    public function addToQueue(User $user, string $uri, ?string $deviceId = null): void
    {
        $connection = $this->spotify->requireConnection($user);
        $query = ['uri' => $uri];
        if ($deviceId) {
            $query['device_id'] = $deviceId;
        }
        $this->spotify->post($connection, '/me/player/queue', [], $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePlayback(array $payload): array
    {
        return [
            'is_playing' => (bool) ($payload['is_playing'] ?? false),
            'device' => $payload['device'] ?? null,
            'item' => $payload['item'] ?? null,
            'progress_ms' => $payload['progress_ms'] ?? null,
            'shuffle_state' => (bool) ($payload['shuffle_state'] ?? false),
            'repeat_state' => $payload['repeat_state'] ?? 'off',
            'context' => $payload['context'] ?? null,
            'actions' => $payload['actions'] ?? null,
        ];
    }

    private function rethrowDeviceErrors(IntegrationException $e): never
    {
        if ($e->statusCode === 404) {
            throw new IntegrationException(
                '[spotify] No active device. Open Spotify on a device, then try again.',
                404,
                $e->payload,
                $e,
            );
        }

        throw $e;
    }
}
