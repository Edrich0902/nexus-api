<?php

namespace App\Services\Activity;

use App\Models\Activity\ActivityEvent;
use App\Models\Spotify\SpotifyListenSample;
use App\Models\Spotify\SpotifyTrack;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes the personal activity timeline. Recording is best-effort: a failure
 * here is reported but never breaks the user action that triggered it.
 */
class ActivityRecorder
{
    /** Plays closer together than this extend the same listening block. */
    private const LISTENING_GAP_MINUTES = 45;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(
        int $userId,
        string $module,
        string $type,
        string $title,
        ?string $body = null,
        ?Model $subject = null,
        array $meta = [],
        ?CarbonInterface $occurredAt = null,
    ): ?ActivityEvent {
        return rescue(fn () => ActivityEvent::query()->create([
            'user_id' => $userId,
            'module' => $module,
            'type' => $type,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'title' => Str::limit($title, 197),
            'body' => $body !== null ? Str::limit($body, 497) : null,
            'meta' => $meta === [] ? null : $meta,
            'occurred_at' => $occurredAt ?? now(),
        ]), null);
    }

    /**
     * Fold a finished play into the current listening block, or start a new one.
     */
    public function listened(SpotifyListenSample $sample, SpotifyTrack $track): void
    {
        rescue(function () use ($sample, $track): void {
            $playedAt = $sample->played_at ?? now();
            $minutes = max(0, (int) round(((int) $sample->listened_ms) / 60000));
            $artists = collect($track->artists ?? [])
                ->pluck('name')
                ->filter()
                ->take(2)
                ->implode(', ');

            $block = ActivityEvent::query()
                ->where('user_id', $sample->user_id)
                ->where('module', 'listening')
                ->where('type', 'listening.block')
                ->where('occurred_at', '>=', $playedAt->copy()->subMinutes(self::LISTENING_GAP_MINUTES))
                ->latest('occurred_at')
                ->first();

            $meta = $block?->meta ?? [];
            $tracks = (int) ($meta['tracks'] ?? 0) + 1;
            $total = (int) ($meta['minutes'] ?? 0) + $minutes;

            $attributes = [
                'title' => Str::limit($track->name, 197),
                'body' => $artists !== '' ? Str::limit($artists, 497) : null,
                'occurred_at' => $playedAt,
                'meta' => [
                    'tracks' => $tracks,
                    'minutes' => $total,
                    'started_at' => $meta['started_at'] ?? $playedAt->toIso8601String(),
                    'image' => $track->album_image_url,
                    'spotify_id' => $track->spotify_id,
                ],
            ];

            if ($block === null) {
                ActivityEvent::query()->create($attributes + [
                    'user_id' => $sample->user_id,
                    'module' => 'listening',
                    'type' => 'listening.block',
                ]);

                return;
            }

            $block->fill($attributes)->save();
        });
    }
}
