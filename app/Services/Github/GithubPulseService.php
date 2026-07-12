<?php

namespace App\Services\Github;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Github\GithubIntegration;
use App\Models\Github\GithubRepo;
use App\Models\User;

class GithubPulseService
{
    private const OPEN_PULL_LIMIT = 5;

    private const MERGED_PULL_LIMIT = 3;

    private const COMMIT_REPO_LIMIT = 4;

    private const COMMITS_PER_REPO = 5;

    private const COMMIT_LIMIT = 5;

    public function __construct(
        private readonly GithubIntegration $github,
        private readonly GithubPullRequestService $pulls,
        private readonly GithubRepoService $repos,
    ) {}

    /**
     * @return array{
     *     open_pulls: list<array<string, mixed>>,
     *     merged_pulls: list<array<string, mixed>>,
     *     commits: list<array<string, mixed>>
     * }
     */
    public function pulse(User $user): array
    {
        $this->github->requireConnection($user);

        $open = $this->pulls->inbox($user, 'open', 1, self::OPEN_PULL_LIMIT);
        $merged = $this->pulls->inbox($user, 'merged', 1, self::MERGED_PULL_LIMIT);

        return [
            'open_pulls' => $open['items'],
            'merged_pulls' => $merged['items'],
            'commits' => $this->recentCommits($user),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentCommits(User $user): array
    {
        $repoRows = GithubRepo::query()
            ->where('user_id', $user->id)
            ->orderByDesc('pushed_at')
            ->orderBy('full_name')
            ->limit(self::COMMIT_REPO_LIMIT)
            ->get();

        $commits = [];

        foreach ($repoRows as $repo) {
            try {
                $page = $this->repos->commits(
                    $user,
                    $repo->owner_login,
                    $repo->name,
                    null,
                    1,
                    self::COMMITS_PER_REPO,
                );

                foreach ($page['items'] as $item) {
                    $commits[] = array_merge($item, [
                        'repository' => [
                            'owner' => $repo->owner_login,
                            'name' => $repo->name,
                            'full_name' => $repo->full_name,
                        ],
                    ]);
                }
            } catch (IntegrationException) {
                continue;
            }
        }

        usort($commits, function (array $a, array $b): int {
            $aTime = strtotime(is_string($a['author_date'] ?? null) ? $a['author_date'] : '') ?: 0;
            $bTime = strtotime(is_string($b['author_date'] ?? null) ? $b['author_date'] : '') ?: 0;

            return $bTime <=> $aTime;
        });

        return array_slice($commits, 0, self::COMMIT_LIMIT);
    }
}
