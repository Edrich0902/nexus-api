<?php

namespace App\Services\Github;

use App\Integrations\Github\GithubIntegration;
use App\Models\Github\GithubRepo;
use App\Models\Github\GithubStatsSnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;

class GithubStatsService
{
    private const TTL_HOURS = 6;

    private const TOP_REPO_LIMIT = 8;

    private const CALENDAR_QUERY = <<<'GRAPHQL'
        query {
          viewer {
            contributionsCollection {
              contributionCalendar {
                totalContributions
                weeks {
                  contributionDays {
                    date
                    contributionCount
                    color
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

    public function __construct(
        private readonly GithubIntegration $github,
        private readonly GithubPullRequestService $pulls,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user, bool $force = false): array
    {
        $this->github->requireConnection($user);

        $snapshot = GithubStatsSnapshot::query()->where('user_id', $user->id)->first();

        $stale = $snapshot === null
            || $snapshot->computed_at === null
            || $snapshot->computed_at->lt(now()->subHours(self::TTL_HOURS));

        if ($force || $stale) {
            return $this->recompute($user);
        }

        return array_merge($snapshot->payload ?? [], [
            'computed_at' => $snapshot->computed_at?->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function recompute(User $user): array
    {
        $connection = $this->github->requireConnection($user);
        $data = $this->github->graphql($connection, self::CALENDAR_QUERY);

        $calendar = data_get($data, 'viewer.contributionsCollection.contributionCalendar', []);
        $weeks = is_array($calendar['weeks'] ?? null) ? $calendar['weeks'] : [];
        $days = $this->flattenDays($weeks);
        $streaks = $this->computeStreaks($days);
        $sparkline = $this->lastNDays($days, 14);

        $open = $this->pulls->inbox($user, 'open', 1, 1);
        $merged = $this->pulls->inbox($user, 'merged', 1, 1);

        $payload = [
            'total_contributions' => (int) ($calendar['totalContributions'] ?? 0),
            'current_streak' => $streaks['current'],
            'longest_streak' => $streaks['longest'],
            'open_pr_count' => (int) ($open['total_count'] ?? 0),
            'merged_pr_count' => (int) ($merged['total_count'] ?? 0),
            'calendar' => [
                'weeks' => $this->mapWeeks($weeks),
            ],
            'sparkline' => $sparkline,
            'languages' => $this->languageMix($user),
            'top_repos' => $this->topRepos($user),
        ];

        GithubStatsSnapshot::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'payload' => $payload,
                'computed_at' => now(),
            ],
        );

        return array_merge($payload, [
            'computed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $weeks
     * @return list<array{date: string, count: int, color: string|null}>
     */
    private function flattenDays(array $weeks): array
    {
        $days = [];

        foreach ($weeks as $week) {
            if (! is_array($week)) {
                continue;
            }
            $contributionDays = is_array($week['contributionDays'] ?? null)
                ? $week['contributionDays']
                : [];

            foreach ($contributionDays as $day) {
                if (! is_array($day) || ! is_string($day['date'] ?? null)) {
                    continue;
                }
                $days[] = [
                    'date' => $day['date'],
                    'count' => (int) ($day['contributionCount'] ?? 0),
                    'color' => isset($day['color']) && is_string($day['color']) ? $day['color'] : null,
                ];
            }
        }

        usort($days, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        return $days;
    }

    /**
     * @param  list<array<string, mixed>>  $weeks
     * @return list<array{contribution_days: list<array{date: string, count: int, color: string|null}>}>
     */
    private function mapWeeks(array $weeks): array
    {
        $mapped = [];

        foreach ($weeks as $week) {
            if (! is_array($week)) {
                continue;
            }
            $contributionDays = is_array($week['contributionDays'] ?? null)
                ? $week['contributionDays']
                : [];
            $days = [];
            foreach ($contributionDays as $day) {
                if (! is_array($day) || ! is_string($day['date'] ?? null)) {
                    continue;
                }
                $days[] = [
                    'date' => $day['date'],
                    'count' => (int) ($day['contributionCount'] ?? 0),
                    'color' => isset($day['color']) && is_string($day['color']) ? $day['color'] : null,
                ];
            }
            $mapped[] = ['contribution_days' => $days];
        }

        return $mapped;
    }

    /**
     * @param  list<array{date: string, count: int, color: string|null}>  $days
     * @return array{current: int, longest: int}
     */
    private function computeStreaks(array $days): array
    {
        if ($days === []) {
            return ['current' => 0, 'longest' => 0];
        }

        $longest = 0;
        $run = 0;

        foreach ($days as $day) {
            if ($day['count'] > 0) {
                $run++;
                $longest = max($longest, $run);
            } else {
                $run = 0;
            }
        }

        $current = 0;
        $cursor = Carbon::today();
        $byDate = [];
        foreach ($days as $day) {
            $byDate[$day['date']] = $day['count'];
        }

        // If today has no contributions yet, allow streak to end on yesterday.
        if (($byDate[$cursor->toDateString()] ?? 0) === 0) {
            $cursor = $cursor->copy()->subDay();
        }

        while (($byDate[$cursor->toDateString()] ?? 0) > 0) {
            $current++;
            $cursor = $cursor->copy()->subDay();
        }

        return [
            'current' => $current,
            'longest' => $longest,
        ];
    }

    /**
     * @param  list<array{date: string, count: int, color: string|null}>  $days
     * @return list<array{date: string, count: int}>
     */
    private function lastNDays(array $days, int $n): array
    {
        $slice = array_slice($days, -$n);

        return array_map(
            fn (array $day) => [
                'date' => $day['date'],
                'count' => $day['count'],
            ],
            $slice,
        );
    }

    /**
     * @return list<array{language: string, count: int}>
     */
    private function languageMix(User $user): array
    {
        $counts = [];

        $languages = GithubRepo::query()
            ->where('user_id', $user->id)
            ->whereNotNull('language')
            ->pluck('language');

        foreach ($languages as $language) {
            if (! is_string($language) || $language === '') {
                continue;
            }
            $counts[$language] = ($counts[$language] ?? 0) + 1;
        }

        arsort($counts);

        return collect($counts)
            ->take(12)
            ->map(fn (int $count, string $language) => [
                'language' => $language,
                'count' => $count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topRepos(User $user): array
    {
        return GithubRepo::query()
            ->where('user_id', $user->id)
            ->orderByDesc('pushed_at')
            ->orderBy('full_name')
            ->limit(self::TOP_REPO_LIMIT)
            ->get()
            ->map(fn (GithubRepo $repo) => [
                'owner' => $repo->owner_login,
                'name' => $repo->name,
                'full_name' => $repo->full_name,
                'language' => $repo->language,
                'private' => $repo->private,
                'html_url' => $repo->html_url,
                'pushed_at' => $repo->pushed_at?->toIso8601String(),
            ])
            ->all();
    }
}
