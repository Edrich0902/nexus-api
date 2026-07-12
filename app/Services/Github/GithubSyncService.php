<?php

namespace App\Services\Github;

use App\Integrations\Github\GithubIntegration;
use App\Models\Github\GithubRepo;
use App\Models\Integration\IntegrationConnection;
use App\Models\User;
use Illuminate\Support\Carbon;

class GithubSyncService
{
    public function __construct(
        private readonly GithubIntegration $github,
    ) {}

    public function syncRepos(User $user): void
    {
        $connection = $this->github->requireConnection($user);
        $seenIds = [];
        $page = 1;

        do {
            $response = $this->github->get($connection, 'user/repos', [
                'per_page' => 100,
                'page' => $page,
                'sort' => 'pushed',
                'affiliation' => 'owner,collaborator,organization_member',
            ]);

            /** @var list<array<string, mixed>> $repos */
            $repos = $response->json() ?? [];
            if (! is_array($repos)) {
                break;
            }

            foreach ($repos as $repo) {
                if (! is_array($repo) || ! isset($repo['id'])) {
                    continue;
                }

                $githubId = (int) $repo['id'];
                $seenIds[] = $githubId;
                $ownerLogin = is_array($repo['owner'] ?? null)
                    ? (string) ($repo['owner']['login'] ?? '')
                    : '';

                GithubRepo::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'github_id' => $githubId,
                    ],
                    [
                        'owner_login' => $ownerLogin,
                        'name' => (string) ($repo['name'] ?? ''),
                        'full_name' => (string) ($repo['full_name'] ?? ''),
                        'private' => (bool) ($repo['private'] ?? false),
                        'default_branch' => isset($repo['default_branch']) ? (string) $repo['default_branch'] : null,
                        'html_url' => isset($repo['html_url']) ? (string) $repo['html_url'] : null,
                        'description' => isset($repo['description']) && is_string($repo['description'])
                            ? $repo['description']
                            : null,
                        'pushed_at' => isset($repo['pushed_at']) && is_string($repo['pushed_at'])
                            ? Carbon::parse($repo['pushed_at'])
                            : null,
                        'language' => isset($repo['language']) && is_string($repo['language'])
                            ? $repo['language']
                            : null,
                    ],
                );
            }

            $page++;
        } while (count($repos) === 100);

        if ($seenIds !== []) {
            GithubRepo::query()
                ->where('user_id', $user->id)
                ->whereNotIn('github_id', $seenIds)
                ->delete();
        }

        $connection->forceFill([
            'last_synced_at' => now(),
        ])->save();
    }

    public function connectionFor(User $user): ?IntegrationConnection
    {
        return $this->github->connectionFor($user);
    }
}
