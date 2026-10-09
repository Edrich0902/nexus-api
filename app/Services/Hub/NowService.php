<?php

namespace App\Services\Hub;

use App\Models\Cellar\CellarWine;
use App\Models\F1\F1Session;
use App\Models\Spotify\SpotifyListenSession;
use App\Models\User;
use App\Services\Media\PaletteService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Picks the single most relevant "moment" for the home stage: live music,
 * a race weekend session, a bottle for tonight, or a plain greeting.
 */
class NowService
{
    private const CACHE_SECONDS = 20;

    /** Heartbeats arrive every few seconds while playing; older means paused or gone. */
    private const LIVE_MUSIC_SECONDS = 90;

    private const RACE_LEAD_HOURS = 3;

    public function __construct(
        private readonly PaletteService $palettes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, string $timezone): array
    {
        return Cache::remember(
            "hub:now:{$user->id}:{$timezone}",
            self::CACHE_SECONDS,
            fn () => $this->resolve($user, CarbonImmutable::now($timezone)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(User $user, CarbonImmutable $now): array
    {
        $daypart = $this->daypart($now);

        $moment = $this->music($user)
            ?? $this->race($now)
            ?? ($daypart === 'evening' || $daypart === 'night' ? $this->bottle($user, $now) : null)
            ?? $this->greeting($daypart);

        return $moment + [
            'daypart' => $daypart,
            'live' => false,
            'image' => null,
            'palette' => null,
            'progress' => null,
            'lede' => null,
            'accent' => null,
            'subject' => null,
            'actions' => [],
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function music(User $user): ?array
    {
        $session = SpotifyListenSession::query()
            ->where('user_id', $user->id)
            ->where('status', SpotifyListenSession::STATUS_ACTIVE)
            ->where('updated_at', '>=', now()->subSeconds(self::LIVE_MUSIC_SECONDS))
            ->with('track:id,spotify_id,name,album_name,album_image_url,artists,duration_ms')
            ->latest('updated_at')
            ->first();

        $track = $session?->track;
        if ($track === null) {
            return null;
        }

        $artists = collect($track->artists ?? [])->pluck('name')->filter()->take(3)->implode(', ');
        $duration = (int) ($session->duration_ms ?: $track->duration_ms);

        return [
            'moment' => 'music',
            'live' => true,
            'eyebrow' => 'Now playing',
            'title' => $track->name,
            'lede' => collect([$artists, $track->album_name])->filter()->implode(' · ') ?: null,
            'image' => $track->album_image_url,
            'palette' => $this->palettes->forUrl($track->album_image_url),
            'progress' => $duration > 0 ? round(min(1, $session->last_progress_ms / $duration), 4) : null,
            'subject' => ['type' => 'spotify_track', 'id' => $track->spotify_id],
            'actions' => [
                ['label' => 'Open listening', 'to' => '/spotify', 'kind' => 'primary'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function race(CarbonImmutable $now): ?array
    {
        $utc = $now->utc();

        $session = F1Session::query()
            ->where('is_cancelled', false)
            ->whereNotNull('date_start')
            ->where('date_start', '<=', $utc->addHours(self::RACE_LEAD_HOURS))
            ->where(function ($q) use ($utc): void {
                $q->where('date_end', '>=', $utc)
                    ->orWhere(fn ($inner) => $inner->whereNull('date_end')->where('date_start', '>=', $utc->subHours(2)));
            })
            ->with('meeting:id,meeting_key,meeting_name,circuit_short_name,location,country_name')
            ->orderBy('date_start')
            ->first();

        if ($session === null) {
            return null;
        }

        $live = $session->date_start->lte($utc);
        $meetingName = (string) ($session->meeting?->meeting_name ?? $session->country_name ?? 'Formula 1');
        [$title, $accent] = Str::endsWith($meetingName, 'Grand Prix')
            ? [trim(Str::beforeLast($meetingName, 'Grand Prix')), 'Grand Prix']
            : [$meetingName, null];

        return [
            'moment' => 'race',
            'live' => $live,
            'eyebrow' => $live
                ? 'Live · '.$session->session_name
                : $session->session_name.' · '.$session->date_start->setTimezone($now->timezone)->format('H:i'),
            'title' => $title,
            'accent' => $accent,
            'lede' => collect([
                $session->meeting?->circuit_short_name ?? $session->circuit_short_name,
                $session->meeting?->location ?? $session->location,
            ])->filter()->unique()->implode(' · ') ?: null,
            'subject' => ['type' => 'f1_session', 'id' => $session->session_key],
            'actions' => [
                ['label' => $live ? 'Follow session' : 'Session details', 'to' => '/f1/sessions/'.$session->session_key, 'kind' => 'primary'],
                ['label' => 'Formula 1', 'to' => '/f1', 'kind' => 'secondary'],
            ],
        ];
    }

    /**
     * A stable pick for the evening from the best-rated bottles.
     *
     * @return array<string, mixed>|null
     */
    private function bottle(User $user, CarbonImmutable $now): ?array
    {
        $candidates = CellarWine::query()
            ->where('user_id', $user->id)
            ->orderByRaw('rating is null')
            ->orderByDesc('rating')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'name', 'producer_name', 'vintage', 'region_name', 'country', 'media_url', 'rating']);

        if ($candidates->isEmpty()) {
            return null;
        }

        $wine = $candidates[crc32($user->id.'|'.$now->toDateString()) % $candidates->count()];

        return [
            'moment' => 'cellar',
            'eyebrow' => 'Tonight',
            'title' => 'Open the',
            'accent' => trim(($wine->vintage ? $wine->vintage.' ' : '').$wine->name),
            'lede' => collect([$wine->producer_name, $wine->region_name ?? $wine->country])->filter()->implode(' · ') ?: null,
            'image' => $wine->media_url,
            'palette' => $this->palettes->forUrl($wine->media_url),
            'subject' => ['type' => 'cellar_wine', 'id' => $wine->id],
            'actions' => [
                ['label' => 'View bottle', 'to' => '/cellar/wines/'.$wine->id, 'kind' => 'primary'],
                ['label' => 'Cellar', 'to' => '/cellar', 'kind' => 'secondary'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function greeting(string $daypart): array
    {
        return [
            'moment' => $daypart,
            'eyebrow' => 'Today',
            'title' => $daypart === 'night' ? 'Still' : 'Good',
            'accent' => $daypart === 'night' ? 'up?' : $daypart,
        ];
    }

    private function daypart(CarbonImmutable $now): string
    {
        $hour = $now->hour;

        return match (true) {
            $hour >= 5 && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 17 => 'afternoon',
            $hour >= 17 && $hour < 23 => 'evening',
            default => 'night',
        };
    }
}
