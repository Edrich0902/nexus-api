<?php

namespace App\Jobs\Media;

use App\Models\User;
use App\Services\Media\MediaMirrorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class MirrorRemoteImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>|null  $sourceMeta
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $url,
        public readonly string $sourceRef,
        public readonly ?int $actingUserId = null,
        public readonly ?string $attachType = null,
        public readonly ?int $attachId = null,
        public readonly ?array $sourceMeta = null,
    ) {
        $this->onQueue((string) config('media.queue', 'default'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('media-mirror:'.$this->provider.':'.$this->sourceRef))
                ->releaseAfter(30)
                ->expireAfter(120),
        ];
    }

    public function handle(MediaMirrorService $mirrors): void
    {
        try {
            $user = $this->actingUserId !== null
                ? User::query()->find($this->actingUserId)
                : null;

            $attachTo = null;
            if ($this->attachType !== null && $this->attachId !== null) {
                $attachTo = [
                    'type' => $this->attachType,
                    'id' => $this->attachId,
                ];
            }

            $mirrors->mirrorNow(
                provider: $this->provider,
                url: $this->url,
                sourceRef: $this->sourceRef,
                actingUser: $user,
                attachTo: $attachTo,
                sourceMeta: $this->sourceMeta,
            );
        } catch (\Throwable $e) {
            report($e);

            throw $e;
        }
    }
}
