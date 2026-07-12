<?php

namespace App\Services\Github;

use App\Http\Resources\Api\V1\Github\GithubRepoResource;
use App\Integrations\Github\GithubIntegration;
use App\Models\Github\GithubRepo;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GithubRepoService
{
    public function __construct(
        private readonly GithubIntegration $github,
    ) {}

    public function listRepos(User $user): AnonymousResourceCollection
    {
        $this->github->requireConnection($user);

        $repos = GithubRepo::query()
            ->where('user_id', $user->id)
            ->orderByDesc('pushed_at')
            ->orderBy('full_name')
            ->get();

        return GithubRepoResource::collection($repos);
    }

    public function findRepo(User $user, string $owner, string $repo): GithubRepo
    {
        $this->github->requireConnection($user);

        $model = GithubRepo::query()
            ->where('user_id', $user->id)
            ->where('owner_login', $owner)
            ->where('name', $repo)
            ->first();

        if ($model === null) {
            abort(404, 'Repository not found. Sync GitHub repos and try again.');
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(User $user): array
    {
        $connection = $this->github->requireConnection($user);
        $response = $this->github->get($connection, 'user');
        $payload = $response->json() ?? [];

        return [
            'id' => isset($payload['id']) ? (string) $payload['id'] : null,
            'login' => $payload['login'] ?? null,
            'name' => $payload['name'] ?? null,
            'avatar_url' => $payload['avatar_url'] ?? null,
            'html_url' => $payload['html_url'] ?? null,
            'bio' => $payload['bio'] ?? null,
            'public_repos' => $payload['public_repos'] ?? null,
            'total_private_repos' => $payload['total_private_repos'] ?? null,
            'followers' => $payload['followers'] ?? null,
            'following' => $payload['following'] ?? null,
        ];
    }

    /**
     * @return list<array{name: string, protected: bool}>
     */
    public function branches(User $user, string $owner, string $repo, int $page = 1, int $perPage = 100): array
    {
        $connection = $this->github->requireConnection($user);
        $response = $this->github->get($connection, "repos/{$owner}/{$repo}/branches", [
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $perPage)),
        ]);

        /** @var list<array<string, mixed>> $items */
        $items = $response->json() ?? [];
        $out = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $out[] = [
                'name' => (string) ($item['name'] ?? ''),
                'protected' => (bool) ($item['protected'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * @return array{items: list<array<string, mixed>>, page: int, per_page: int}
     */
    public function commits(
        User $user,
        string $owner,
        string $repo,
        ?string $sha = null,
        int $page = 1,
        int $perPage = 30,
    ): array {
        $connection = $this->github->requireConnection($user);
        $query = [
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $perPage)),
        ];
        if ($sha !== null && $sha !== '') {
            $query['sha'] = $sha;
        }

        $response = $this->github->get($connection, "repos/{$owner}/{$repo}/commits", $query);
        /** @var list<array<string, mixed>> $items */
        $items = $response->json() ?? [];
        $mapped = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $commit = is_array($item['commit'] ?? null) ? $item['commit'] : [];
            $author = is_array($commit['author'] ?? null) ? $commit['author'] : [];
            $mapped[] = [
                'sha' => $item['sha'] ?? null,
                'message' => $commit['message'] ?? null,
                'author_name' => $author['name'] ?? null,
                'author_date' => $author['date'] ?? null,
                'html_url' => $item['html_url'] ?? null,
            ];
        }

        return [
            'items' => $mapped,
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $perPage)),
        ];
    }

    /**
     * Compare two refs (branches/SHAs) for create-PR preview.
     *
     * @return array{
     *     status: string|null,
     *     ahead_by: int|null,
     *     behind_by: int|null,
     *     total_commits: int|null,
     *     html_url: string|null,
     *     files: list<array<string, mixed>>
     * }
     */
    public function compare(User $user, string $owner, string $repo, string $base, string $head): array
    {
        $this->findRepo($user, $owner, $repo);
        $connection = $this->github->requireConnection($user);

        $baseEnc = rawurlencode($base);
        $headEnc = rawurlencode($head);
        $response = $this->github->get(
            $connection,
            "repos/{$owner}/{$repo}/compare/{$baseEnc}...{$headEnc}",
        );
        $payload = $response->json() ?? [];
        $files = is_array($payload['files'] ?? null) ? $payload['files'] : [];
        $mapped = [];

        foreach ($files as $item) {
            if (! is_array($item)) {
                continue;
            }
            $mapped[] = [
                'sha' => $item['sha'] ?? null,
                'filename' => $item['filename'] ?? null,
                'status' => $item['status'] ?? null,
                'additions' => $item['additions'] ?? null,
                'deletions' => $item['deletions'] ?? null,
                'changes' => $item['changes'] ?? null,
                'patch' => $item['patch'] ?? null,
                'blob_url' => $item['blob_url'] ?? null,
                'raw_url' => $item['raw_url'] ?? null,
            ];
        }

        return [
            'status' => isset($payload['status']) ? (string) $payload['status'] : null,
            'ahead_by' => isset($payload['ahead_by']) ? (int) $payload['ahead_by'] : null,
            'behind_by' => isset($payload['behind_by']) ? (int) $payload['behind_by'] : null,
            'total_commits' => isset($payload['total_commits']) ? (int) $payload['total_commits'] : null,
            'html_url' => isset($payload['html_url']) ? (string) $payload['html_url'] : null,
            'files' => $mapped,
        ];
    }
}
