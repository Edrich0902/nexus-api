<?php

namespace App\Integrations\OpenF1;

use App\Integrations\Exceptions\IntegrationException;
use Illuminate\Support\Facades\Cache;

/**
 * Detects OpenF1 free-tier live-session lockouts and caches health for the UI.
 */
class OpenF1ProviderHealth
{
    public const CACHE_KEY = 'openf1.provider_health';

    public static function isLiveLockout(IntegrationException $e): bool
    {
        if (! in_array($e->statusCode, [401, 409], true)) {
            return false;
        }

        $detail = strtolower((string) ($e->payload['detail'] ?? ''));
        $message = strtolower($e->getMessage());
        $haystack = $detail.' '.$message;

        return str_contains($haystack, 'live f1 session')
            || str_contains($haystack, 'live session in progress')
            || str_contains($haystack, 'restricted to authenticated')
            || str_contains($haystack, 'global api access');
    }

    public static function rememberLockout(?IntegrationException $e = null): void
    {
        $seconds = self::releaseSeconds();
        $reason = is_string($e?->payload['detail'] ?? null)
            ? (string) $e->payload['detail']
            : ($e?->getMessage() ?? 'OpenF1 free API is locked during a live F1 session.');

        Cache::put(self::CACHE_KEY, [
            'available' => false,
            'live_lockout' => true,
            'reason' => $reason,
            'locked_until' => now()->addSeconds($seconds)->toIso8601String(),
            'last_checked_at' => now()->toIso8601String(),
        ], $seconds);
    }

    public static function rememberAvailable(): void
    {
        Cache::put(self::CACHE_KEY, [
            'available' => true,
            'live_lockout' => false,
            'reason' => null,
            'locked_until' => null,
            'last_checked_at' => now()->toIso8601String(),
        ], 600);
    }

    /**
     * @return array{
     *   available: bool,
     *   live_lockout: bool,
     *   reason: ?string,
     *   locked_until: ?string,
     *   last_checked_at: ?string
     * }
     */
    public static function snapshot(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return [
                'available' => (bool) ($cached['available'] ?? true),
                'live_lockout' => (bool) ($cached['live_lockout'] ?? false),
                'reason' => isset($cached['reason']) ? (string) $cached['reason'] : null,
                'locked_until' => isset($cached['locked_until']) ? (string) $cached['locked_until'] : null,
                'last_checked_at' => isset($cached['last_checked_at']) ? (string) $cached['last_checked_at'] : null,
            ];
        }

        return [
            'available' => true,
            'live_lockout' => false,
            'reason' => null,
            'locked_until' => null,
            'last_checked_at' => null,
        ];
    }

    public static function releaseSeconds(): int
    {
        return max(300, (int) config('services.openf1.sync.live_lockout_release_seconds', 1800));
    }
}
