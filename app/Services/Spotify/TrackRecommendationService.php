<?php

namespace App\Services\Spotify;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\ReccoBeats\ReccoBeatsIntegration;
use App\Models\Spotify\SpotifyListenSample;
use App\Models\Spotify\SpotifyListenSession;
use App\Models\Spotify\SpotifyTrack;
use App\Models\Spotify\TrackAudioFeatures;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Track-anchored, session-guarded recommendations.
 *
 * Spotify related-artists is often unavailable (deprecated for newer apps), so
 * we diversify via: credited artists + genre search + ReccoBeats (acoustic-gated).
 */
class TrackRecommendationService
{
    private const MAX_RELATED_ARTISTS = 5;

    private const MAX_SEED_ARTISTS = 3;

    /** Pool size per seed artist (enough to fill a list when related is thin). */
    private const MAX_PRIMARY_POOL_TRACKS = 8;

    /** Pool size per related / extra seed artist. */
    private const MAX_RELATED_POOL_TRACKS = 8;

    private const MAX_POOL_TRACKS = 60;

    private const MAX_NEGATIVES = 5;

    /** When other-artist candidates exist, reserve at least this share for them. */
    private const OTHER_ARTIST_SLOT_RATIO = 0.55;

    private const POOL_CACHE_SECONDS = 1800;

    private const RESPONSE_CACHE_SECONDS = 120;

    private const CACHE_VERSION = 'v4';

    /** Drop non-neighborhood ReccoBeats candidates beyond this acoustic distance. */
    private const FOREIGN_MAX_DISTANCE = 0.36;

    private const ENERGY_BAND = 0.22;

    private const TEMPO_BAND = 18.0;

    private const DISTANCE_KEYS = [
        'energy',
        'danceability',
        'valence',
        'acousticness',
        'instrumentalness',
        'speechiness',
    ];

    public function __construct(
        private readonly ReccoBeatsIntegration $reccobeats,
        private readonly SpotifyCatalogService $catalog,
        private readonly TrackAudioFeaturesService $features,
        private readonly SpotifyBrowseService $browse,
        private readonly SpotifySearchService $search,
    ) {}

    /**
     * @return list<array{reason: string, source: string, track: array<string, mixed>}>
     */
    public function similar(User $user, string $seedSpotifyId, int $limit = 12): array
    {
        $limit = max(1, min(30, $limit));
        $cacheKey = 'spotify:similar:'.self::CACHE_VERSION.":{$user->id}:{$seedSpotifyId}:{$limit}";

        /** @var list<array{reason: string, source: string, track: array<string, mixed>}>|null $cached */
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $items = $this->buildSimilar($user, $seedSpotifyId, $limit);
        } catch (IntegrationException $e) {
            if ($e->statusCode === 429) {
                Log::warning('Spotify rate-limited during similar recommendations', [
                    'seed' => $seedSpotifyId,
                    'user_id' => $user->id,
                ]);

                return [];
            }
            throw $e;
        }

        Cache::put($cacheKey, $items, self::RESPONSE_CACHE_SECONDS);

        return $items;
    }

    /**
     * @return list<array{reason: string, source: string, track: array<string, mixed>}>
     */
    private function buildSimilar(User $user, string $seedSpotifyId, int $limit): array
    {
        $window = $this->sessionWindow();
        $current = SpotifyTrack::query()
            ->with('audioFeatures')
            ->where('spotify_id', $seedSpotifyId)
            ->first();

        $seedArtistIds = array_slice($this->artistIdsFromTrack($current), 0, self::MAX_SEED_ARTISTS);
        $primaryArtistId = $seedArtistIds[0] ?? null;
        $bans = $this->sessionBans($user, $seedSpotifyId, $window);
        $engagedBoostIds = $this->engagedNeighborhoodTrackIds($user, $window);

        $relatedIds = $primaryArtistId !== null
            ? array_slice(
                $this->browse->relatedArtistIds($user, $primaryArtistId),
                0,
                self::MAX_RELATED_ARTISTS,
            )
            : [];

        $neighborhoodArtistIds = array_values(array_unique(array_merge($seedArtistIds, $relatedIds)));
        $neighborhoodLookup = array_fill_keys($neighborhoodArtistIds, true);
        $seedArtistLookup = array_fill_keys($seedArtistIds, true);

        /** @var array<string, array{track: SpotifyTrack, popularity: int|null, source: string}> $candidates */
        $candidates = [];

        foreach ($this->candidatePool($user, $seedSpotifyId, $primaryArtistId, $neighborhoodArtistIds) as $entry) {
            $track = $entry['track'];
            if ($track->spotify_id === $seedSpotifyId) {
                continue;
            }
            if (! $this->passesBanGates($track, $bans)) {
                continue;
            }
            if (! $this->artistOverlap($track, $neighborhoodLookup)) {
                continue;
            }
            $candidates[$track->spotify_id] = $entry + ['source' => 'neighborhood'];
        }

        foreach ($this->genreSearchPool($user, $primaryArtistId, $seedSpotifyId) as $entry) {
            $track = $entry['track'];
            if ($track->spotify_id === $seedSpotifyId || isset($candidates[$track->spotify_id])) {
                continue;
            }
            if (! $this->passesBanGates($track, $bans)) {
                continue;
            }
            $candidates[$track->spotify_id] = $entry + ['source' => 'genre'];
        }

        // Always ask ReccoBeats for vibe neighbors — gate on acoustics when not in neighborhood.
        foreach ($this->reccobeatsFill($current, $seedSpotifyId, $seedArtistIds, $bans['track_seeds']) as $entry) {
            $track = $entry['track'];
            if ($track->spotify_id === $seedSpotifyId || isset($candidates[$track->spotify_id])) {
                continue;
            }
            if (! $this->passesBanGates($track, $bans)) {
                continue;
            }
            $candidates[$track->spotify_id] = $entry + ['source' => 'reccobeats'];
        }

        $ranked = $this->rankCandidates(
            array_values($candidates),
            $current?->audioFeatures,
            $primaryArtistId,
            $seedArtistLookup,
            $neighborhoodLookup,
            $engagedBoostIds,
        );

        $selected = $this->selectDiverse($ranked, $primaryArtistId, $limit);

        $items = [];
        foreach ($selected as $entry) {
            $items[] = [
                'reason' => $entry['reason'],
                'source' => 'spotify_neighborhood',
                'track' => $this->trackArray($entry['track']),
            ];
        }

        return $items;
    }

    /**
     * @param  list<string>  $neighborhoodArtistIds
     * @return list<array{track: SpotifyTrack, popularity: int|null}>
     */
    private function candidatePool(
        User $user,
        string $seedSpotifyId,
        ?string $primaryArtistId,
        array $neighborhoodArtistIds,
    ): array {
        if ($primaryArtistId === null || $neighborhoodArtistIds === []) {
            return [];
        }

        $market = $this->browse->marketForUser($user);
        $cacheKey = 'spotify:neighborhood-pool:'.self::CACHE_VERSION.":{$primaryArtistId}:{$market}";

        /** @var list<string>|null $cachedIds */
        $cachedIds = Cache::get($cacheKey);
        if (is_array($cachedIds) && $cachedIds !== []) {
            return $this->tracksFromIds($cachedIds, $seedSpotifyId);
        }

        $seen = [];
        $orderedIds = [];
        $pool = [];

        // Related artists first — diversity before more-of-same-artist.
        $relatedIds = array_values(array_filter(
            $neighborhoodArtistIds,
            fn (string $id) => $id !== $primaryArtistId,
        ));
        $fetchOrder = array_merge($relatedIds, [$primaryArtistId]);

        foreach ($fetchOrder as $artistId) {
            $perArtistCap = $artistId === $primaryArtistId
                ? self::MAX_PRIMARY_POOL_TRACKS
                : self::MAX_RELATED_POOL_TRACKS;
            $takenForArtist = 0;

            try {
                $payload = $this->browse->getArtistTopTracks($user, $artistId, $market);
            } catch (IntegrationException $e) {
                if ($e->statusCode === 429) {
                    break;
                }
                throw $e;
            }

            foreach ($payload['tracks'] ?? [] as $row) {
                if ($takenForArtist >= $perArtistCap) {
                    break;
                }
                if (! is_array($row) || ! is_string($row['id'] ?? null)) {
                    continue;
                }
                $id = $row['id'];
                if ($id === $seedSpotifyId || isset($seen[$id])) {
                    continue;
                }

                $track = $this->catalog->upsertTrack([
                    'id' => $id,
                    'name' => $row['name'] ?? 'Unknown Track',
                    'uri' => $row['uri'] ?? 'spotify:track:'.$id,
                    'duration_ms' => $row['duration_ms'] ?? null,
                    'explicit' => $row['explicit'] ?? false,
                    'artists' => $row['artists'] ?? [],
                    'album' => [
                        'name' => $row['album_name'] ?? null,
                        'images' => isset($row['album_image_url'])
                            ? [['url' => $row['album_image_url']]]
                            : [],
                    ],
                    'external_urls' => ['spotify' => null],
                ]);
                if ($track === null) {
                    continue;
                }

                $seen[$id] = true;
                $orderedIds[] = $id;
                $takenForArtist++;
                $pool[] = [
                    'track' => $track,
                    'popularity' => null,
                ];

                if (count($pool) >= self::MAX_POOL_TRACKS) {
                    break 2;
                }
            }
        }

        if ($orderedIds !== []) {
            Cache::put($cacheKey, $orderedIds, self::POOL_CACHE_SECONDS);
        }

        return $pool;
    }

    /**
     * @param  list<string>  $spotifyIds
     * @return list<array{track: SpotifyTrack, popularity: int|null}>
     */
    private function tracksFromIds(array $spotifyIds, string $seedSpotifyId): array
    {
        $tracks = SpotifyTrack::query()
            ->whereIn('spotify_id', $spotifyIds)
            ->get()
            ->keyBy('spotify_id');

        $pool = [];
        foreach ($spotifyIds as $id) {
            if ($id === $seedSpotifyId) {
                continue;
            }
            $track = $tracks->get($id);
            if ($track instanceof SpotifyTrack) {
                $pool[] = ['track' => $track, 'popularity' => null];
            }
        }

        return $pool;
    }

    /**
     * @param  array{track_ids: array<string, true>, artist_ids: array<string, true>, track_seeds: list<string>}  $bans
     */
    private function passesBanGates(SpotifyTrack $track, array $bans): bool
    {
        if (isset($bans['track_ids'][$track->spotify_id])) {
            return false;
        }
        foreach ($this->artistIdsFromTrack($track) as $artistId) {
            if (isset($bans['artist_ids'][$artistId])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Genre-scoped Spotify search from the seed artist's genres (culture / era lock).
     *
     * @return list<array{track: SpotifyTrack, popularity: int|null}>
     */
    private function genreSearchPool(User $user, ?string $primaryArtistId, string $seedSpotifyId): array
    {
        if ($primaryArtistId === null) {
            return [];
        }

        try {
            $artist = $this->browse->getArtist($user, $primaryArtistId);
        } catch (\Throwable) {
            return [];
        }

        if (($artist['available'] ?? false) !== true) {
            return [];
        }

        $genres = [];
        foreach ($artist['genres'] ?? [] as $genre) {
            if (is_string($genre) && $genre !== '') {
                $genres[] = $genre;
            }
        }
        $genres = array_slice(array_values(array_unique($genres)), 0, 2);
        if ($genres === []) {
            return [];
        }

        $pool = [];
        $seen = [];
        foreach ($genres as $genre) {
            $safe = str_replace('"', '', $genre);
            try {
                $result = $this->search->search($user, 'genre:"'.$safe.'"', 'track', 10);
            } catch (\Throwable) {
                continue;
            }

            foreach ($result['tracks'] as $row) {
                if (! is_array($row) || ! is_string($row['id'] ?? null)) {
                    continue;
                }
                $id = $row['id'];
                if ($id === $seedSpotifyId || isset($seen[$id])) {
                    continue;
                }
                $track = $this->catalog->upsertTrack([
                    'id' => $id,
                    'name' => $row['name'] ?? 'Unknown Track',
                    'uri' => $row['uri'] ?? 'spotify:track:'.$id,
                    'duration_ms' => $row['duration_ms'] ?? null,
                    'explicit' => $row['explicit'] ?? false,
                    'artists' => $row['artists'] ?? [],
                    'album' => [
                        'name' => $row['album_name'] ?? null,
                        'images' => isset($row['album_image_url'])
                            ? [['url' => $row['album_image_url']]]
                            : [],
                    ],
                    'external_urls' => ['spotify' => null],
                ]);
                if ($track === null) {
                    continue;
                }
                $seen[$id] = true;
                $pool[] = ['track' => $track, 'popularity' => null];
                if (count($pool) >= 24) {
                    return $pool;
                }
            }
        }

        return $pool;
    }

    /**
     * ReccoBeats vibe neighbors — acoustic filters, not open culture dump.
     *
     * @param  list<string>  $seedArtistIds
     * @param  list<string>  $negativeSeeds
     * @return list<array{track: SpotifyTrack, popularity: int|null}>
     */
    private function reccobeatsFill(
        ?SpotifyTrack $current,
        string $seedSpotifyId,
        array $seedArtistIds,
        array $negativeSeeds,
    ): array {
        $seeds = [];
        $trackSeed = $current?->audioFeatures?->provider_track_id;
        $seeds[] = is_string($trackSeed) && $trackSeed !== '' ? $trackSeed : $seedSpotifyId;
        foreach (array_slice($seedArtistIds, 0, 2) as $artistId) {
            $seeds[] = $artistId;
        }
        $seeds = array_slice(array_values(array_unique($seeds)), 0, 5);

        $filters = $this->currentTrackFilters($current);
        $neg = array_slice($negativeSeeds, 0, self::MAX_NEGATIVES);
        if ($neg !== []) {
            $filters['negativeSeeds'] = implode(',', $neg);
        }

        try {
            $rows = $this->reccobeats->recommendations($seeds, 24, $filters);
        } catch (\Throwable $e) {
            Log::warning('ReccoBeats thin-fill failed', ['message' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $spotifyId = ReccoBeatsIntegration::spotifyIdFromHref(
                is_string($row['href'] ?? null) ? $row['href'] : null,
            );
            if ($spotifyId === null) {
                continue;
            }
            $track = $this->upsertRecommendationTrack($row, $spotifyId);
            if ($track === null) {
                continue;
            }
            $out[] = [
                'track' => $track,
                'popularity' => is_numeric($row['popularity'] ?? null) ? (int) $row['popularity'] : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentTrackFilters(?SpotifyTrack $current): array
    {
        $features = $current?->audioFeatures;
        if (! $features instanceof TrackAudioFeatures || ! $features->isReady()) {
            return [];
        }

        $filters = ['featureWeight' => 2];
        foreach (['energy', 'danceability', 'valence', 'acousticness', 'instrumentalness', 'speechiness'] as $key) {
            if (is_numeric($features->{$key})) {
                $filters[$key] = round((float) $features->{$key}, 3);
            }
        }
        if (is_numeric($features->tempo)) {
            $filters['tempo'] = (int) round((float) $features->tempo);
        }
        if ($features->mode === 0 || $features->mode === 1) {
            $filters['mode'] = (int) $features->mode;
        }

        return $filters;
    }

    /**
     * @param  list<array{track: SpotifyTrack, popularity: int|null, source?: string}>  $candidates
     * @param  array<string, true>  $seedArtistLookup
     * @param  array<string, true>  $neighborhoodLookup
     * @param  array<string, true>  $engagedBoostIds
     * @return list<array{track: SpotifyTrack, popularity: int|null, score: float, reason: string, same_artist: bool, source: string}>
     */
    private function rankCandidates(
        array $candidates,
        ?TrackAudioFeatures $anchor,
        ?string $primaryArtistId,
        array $seedArtistLookup,
        array $neighborhoodLookup,
        array $engagedBoostIds,
    ): array {
        if ($candidates === []) {
            return [];
        }

        $ids = array_map(fn (array $c) => $c['track']->spotify_id, $candidates);
        $featureById = $this->batchFeaturesBySpotifyId($ids);

        $ranked = [];
        foreach ($candidates as $candidate) {
            /** @var SpotifyTrack $track */
            $track = $candidate['track'];
            $source = is_string($candidate['source'] ?? null) ? $candidate['source'] : 'neighborhood';
            $features = $featureById[$track->spotify_id] ?? $track->audioFeatures;
            $distance = $this->featureDistance($anchor, $features);
            $sameArtist = $primaryArtistId !== null
                && in_array($primaryArtistId, $this->artistIdsFromTrack($track), true);
            $seedArtist = ! $sameArtist && $this->artistOverlap($track, $seedArtistLookup);
            $relatedArtist = ! $sameArtist && ! $seedArtist && $this->artistOverlap($track, $neighborhoodLookup);
            $genreSource = $source === 'genre';
            $inBand = $this->inAcousticBand($anchor, $features);
            $sessionBoost = isset($engagedBoostIds[$track->spotify_id]);
            $popularity = $candidate['popularity'];
            $popScore = $popularity === null ? 0.45 : min(1.0, max(0.0, $popularity / 100));

            // Foreign ReccoBeats rows need a tight acoustic match — no language dump.
            if ($source === 'reccobeats' && ! $sameArtist && ! $seedArtist && ! $relatedArtist) {
                if ($distance === null || $distance > self::FOREIGN_MAX_DISTANCE) {
                    continue;
                }
                if (! $inBand && $distance > self::FOREIGN_MAX_DISTANCE * 0.75) {
                    continue;
                }
            }

            $distanceScore = $distance === null ? 0.48 : min(1.0, $distance);
            $score = (1.0 - $distanceScore) * 0.58;
            if ($genreSource) {
                $score += 0.18;
            } elseif ($relatedArtist || $seedArtist) {
                $score += 0.14;
            } elseif ($sameArtist) {
                $score += 0.08;
            }
            if ($inBand) {
                $score += 0.12;
            } elseif ($distance !== null) {
                $score -= min(0.18, $distance * 0.22);
            }
            if ($sessionBoost) {
                $score += 0.05;
            }
            $score += $popScore * 0.04;

            $reason = 'Close acoustic fit';
            if ($genreSource) {
                $reason = 'Same genre / vibe';
            } elseif ($relatedArtist || $seedArtist) {
                $reason = 'Related artist';
            } elseif ($sameArtist) {
                $reason = 'Same artist';
            }

            $ranked[] = [
                'track' => $track,
                'popularity' => $popularity,
                'score' => $score,
                'reason' => $reason,
                'same_artist' => $sameArtist,
                'source' => $source,
            ];
        }

        usort($ranked, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $ranked;
    }

    /**
     * Reserve most slots for other artists when available, then fill to $limit.
     *
     * @param  list<array{track: SpotifyTrack, popularity: int|null, score: float, reason: string, same_artist: bool, source: string}>  $ranked
     * @return list<array{track: SpotifyTrack, popularity: int|null, score: float, reason: string, same_artist: bool, source: string}>
     */
    private function selectDiverse(array $ranked, ?string $primaryArtistId, int $limit): array
    {
        if ($ranked === [] || $limit < 1) {
            return [];
        }

        $others = [];
        $same = [];
        foreach ($ranked as $entry) {
            $isSame = ($entry['same_artist'] ?? false) === true
                || ($primaryArtistId !== null
                    && in_array($primaryArtistId, $this->artistIdsFromTrack($entry['track']), true));
            if ($isSame) {
                $same[] = $entry;
            } else {
                $others[] = $entry;
            }
        }

        $otherSlots = $others === []
            ? 0
            : min(count($others), max(1, (int) ceil($limit * self::OTHER_ARTIST_SLOT_RATIO)));

        $selected = [];
        $selectedIds = [];
        $artistCounts = [];

        $take = function (array $pool, int $max) use (&$selected, &$selectedIds, &$artistCounts, $limit): void {
            foreach ($pool as $entry) {
                if (count($selected) >= $limit || $max <= 0) {
                    return;
                }
                $id = $entry['track']->spotify_id;
                if (isset($selectedIds[$id])) {
                    continue;
                }
                $artistKey = $this->artistIdsFromTrack($entry['track'])[0] ?? '_unknown';
                // Soft per-artist spread while alternatives exist, never block fill entirely later.
                if (($artistCounts[$artistKey] ?? 0) >= 2 && $max > 1) {
                    continue;
                }
                $selected[] = $entry;
                $selectedIds[$id] = true;
                $artistCounts[$artistKey] = ($artistCounts[$artistKey] ?? 0) + 1;
                $max--;
            }
        };

        $take($others, $otherSlots);
        $take($same, $limit - count($selected));

        // Fill remaining from full ranked list (completeness over purity).
        foreach ($ranked as $entry) {
            if (count($selected) >= $limit) {
                break;
            }
            $id = $entry['track']->spotify_id;
            if (isset($selectedIds[$id])) {
                continue;
            }
            $selected[] = $entry;
            $selectedIds[$id] = true;
        }

        return $selected;
    }

    private function inAcousticBand(?TrackAudioFeatures $anchor, ?TrackAudioFeatures $candidate): bool
    {
        if (! $anchor?->isReady() || ! $candidate?->isReady()) {
            return false;
        }

        foreach (['energy', 'danceability', 'valence'] as $key) {
            if (! is_numeric($anchor->{$key}) || ! is_numeric($candidate->{$key})) {
                continue;
            }
            if (abs((float) $anchor->{$key} - (float) $candidate->{$key}) > self::ENERGY_BAND) {
                return false;
            }
        }

        if (is_numeric($anchor->tempo) && is_numeric($candidate->tempo)) {
            if (abs((float) $anchor->tempo - (float) $candidate->tempo) > self::TEMPO_BAND) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{track_ids: array<string, true>, artist_ids: array<string, true>, track_seeds: list<string>}
     */
    private function sessionBans(User $user, string $seedSpotifyId, Carbon $since): array
    {
        $engageMs = (int) config('services.spotify.listening.engage_progress_ms', 30_000);

        $skipSessions = SpotifyListenSession::query()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', $since)
            ->where('status', SpotifyListenSession::STATUS_CLOSED)
            ->where('max_progress_ms', '<', $engageMs)
            ->where('spotify_id', '!=', $seedSpotifyId)
            ->orderByDesc('started_at')
            ->limit(12)
            ->get();

        $trackIds = [];
        $artistIds = [];
        $trackSeeds = [];

        $tracks = SpotifyTrack::query()
            ->with('audioFeatures')
            ->whereIn('id', $skipSessions->pluck('spotify_track_id')->filter()->all())
            ->get()
            ->keyBy('id');

        foreach ($skipSessions as $session) {
            $track = $tracks->get($session->spotify_track_id);
            if (! $track instanceof SpotifyTrack) {
                continue;
            }
            $trackIds[$track->spotify_id] = true;
            $seed = $track->audioFeatures?->provider_track_id ?: $track->spotify_id;
            if (is_string($seed) && $seed !== '') {
                $trackSeeds[] = $seed;
            }
            foreach ($this->artistIdsFromTrack($track) as $artistId) {
                $artistIds[$artistId] = true;
            }
        }

        return [
            'track_ids' => $trackIds,
            'artist_ids' => $artistIds,
            'track_seeds' => array_slice(array_values(array_unique($trackSeeds)), 0, self::MAX_NEGATIVES),
        ];
    }

    /**
     * @return array<string, true>
     */
    private function engagedNeighborhoodTrackIds(User $user, Carbon $since): array
    {
        $ids = SpotifyListenSample::query()
            ->where('user_id', $user->id)
            ->where('played_at', '>=', $since)
            ->where('weight', '>', 0)
            ->orderByDesc('played_at')
            ->limit(30)
            ->pluck('spotify_track_id');

        if ($ids->isEmpty()) {
            return [];
        }

        $lookup = [];
        SpotifyTrack::query()
            ->whereIn('id', $ids)
            ->pluck('spotify_id')
            ->each(function (?string $spotifyId) use (&$lookup): void {
                if (is_string($spotifyId) && $spotifyId !== '') {
                    $lookup[$spotifyId] = true;
                }
            });

        return $lookup;
    }

    private function sessionWindow(): Carbon
    {
        $minutes = max(15, (int) config('services.spotify.listening.session_window_minutes', 45));

        return Carbon::now()->subMinutes($minutes);
    }

    private function primaryArtistId(?SpotifyTrack $track): ?string
    {
        $ids = $this->artistIdsFromTrack($track);

        return $ids[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function artistIdsFromTrack(?SpotifyTrack $track): array
    {
        if ($track === null || ! is_array($track->artists)) {
            return [];
        }

        $ids = [];
        foreach ($track->artists as $artist) {
            if (! is_array($artist)) {
                continue;
            }
            $id = $artist['id'] ?? null;
            if (is_string($id) && $id !== '' && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, true>  $lookup
     */
    private function artistOverlap(SpotifyTrack $track, array $lookup): bool
    {
        foreach ($this->artistIdsFromTrack($track) as $id) {
            if (isset($lookup[$id])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $spotifyIds
     * @return array<string, TrackAudioFeatures>
     */
    private function batchFeaturesBySpotifyId(array $spotifyIds): array
    {
        $spotifyIds = array_values(array_unique(array_filter($spotifyIds)));
        if ($spotifyIds === []) {
            return [];
        }

        $tracks = SpotifyTrack::query()
            ->with('audioFeatures')
            ->whereIn('spotify_id', $spotifyIds)
            ->get();

        $missing = [];
        foreach ($tracks as $track) {
            if (! $track->audioFeatures?->isReady()) {
                $missing[] = $track;
            }
        }

        // Warm a small batch only — avoid ReccoBeats stampede.
        foreach (array_slice($missing, 0, 12) as $track) {
            try {
                $this->features->fetchAndStore($track);
                $track->unsetRelation('audioFeatures');
                $track->load('audioFeatures');
            } catch (\Throwable) {
                // Score without features.
            }
        }

        $map = [];
        foreach ($tracks as $track) {
            $features = $track->audioFeatures;
            if ($features?->isReady()) {
                $map[$track->spotify_id] = $features;
            }
        }

        return $map;
    }

    private function featureDistance(?TrackAudioFeatures $a, ?TrackAudioFeatures $b): ?float
    {
        if (! $a?->isReady() || ! $b?->isReady()) {
            return null;
        }

        $sum = 0.0;
        $dims = 0;
        foreach (self::DISTANCE_KEYS as $key) {
            if (! is_numeric($a->{$key}) || ! is_numeric($b->{$key})) {
                continue;
            }
            $delta = (float) $a->{$key} - (float) $b->{$key};
            $sum += $delta * $delta;
            $dims++;
        }

        if (is_numeric($a->tempo) && is_numeric($b->tempo)) {
            $ta = min(1.0, max(0.0, ((float) $a->tempo - 60.0) / 120.0));
            $tb = min(1.0, max(0.0, ((float) $b->tempo - 60.0) / 120.0));
            $sum += ($ta - $tb) * ($ta - $tb);
            $dims++;
        }

        if ($dims === 0) {
            return null;
        }

        return sqrt($sum / $dims);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertRecommendationTrack(array $row, string $spotifyId): ?SpotifyTrack
    {
        $artists = [];
        foreach ($row['artists'] ?? [] as $artist) {
            if (! is_array($artist)) {
                continue;
            }
            $artists[] = [
                'id' => ReccoBeatsIntegration::spotifyIdFromHref(
                    is_string($artist['href'] ?? null) ? $artist['href'] : null,
                ) ?? ($artist['id'] ?? null),
                'name' => $artist['name'] ?? null,
            ];
        }

        return $this->catalog->upsertTrack([
            'id' => $spotifyId,
            'name' => $row['trackTitle'] ?? $row['name'] ?? 'Unknown Track',
            'duration_ms' => $row['durationMs'] ?? $row['duration_ms'] ?? null,
            'uri' => 'spotify:track:'.$spotifyId,
            'external_urls' => ['spotify' => $row['href'] ?? null],
            'artists' => $artists,
            'album' => [
                'name' => null,
                'images' => [],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function trackArray(SpotifyTrack $track): array
    {
        return [
            'id' => $track->spotify_id,
            'name' => $track->name,
            'uri' => $track->uri,
            'duration_ms' => $track->duration_ms,
            'explicit' => (bool) $track->explicit,
            'album_name' => $track->album_name,
            'album_image_url' => $track->album_image_url,
            'artists' => $track->artists ?? [],
            'external_url' => $track->external_url,
        ];
    }
}
