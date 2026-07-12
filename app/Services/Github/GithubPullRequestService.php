<?php

namespace App\Services\Github;

use App\Integrations\Github\GithubIntegration;
use App\Models\User;

class GithubPullRequestService
{
    public function __construct(
        private readonly GithubIntegration $github,
        private readonly GithubRepoService $repos,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, total_count: int, page: int, per_page: int}
     */
    public function inbox(User $user, string $state = 'open', int $page = 1, int $perPage = 30): array
    {
        $connection = $this->github->requireConnection($user);
        $profile = $this->repos->profile($user);
        $login = is_string($profile['login'] ?? null) ? $profile['login'] : null;

        if ($login === null || $login === '') {
            return [
                'items' => [],
                'total_count' => 0,
                'page' => $page,
                'per_page' => $perPage,
            ];
        }

        $stateFilter = match ($state) {
            'closed' => 'is:closed',
            'merged' => 'is:merged',
            'all' => '',
            default => 'is:open',
        };

        $q = trim("is:pr author:{$login} {$stateFilter}");
        $response = $this->github->get($connection, 'search/issues', [
            'q' => $q,
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $perPage)),
            'sort' => 'updated',
            'order' => 'desc',
        ]);

        $payload = $response->json() ?? [];
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $mapped = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $mapped[] = $this->mapSearchIssue($item);
        }

        return [
            'items' => $mapped,
            'total_count' => (int) ($payload['total_count'] ?? count($mapped)),
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $perPage)),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, page: int, per_page: int}
     */
    public function listForRepo(
        User $user,
        string $owner,
        string $repo,
        string $state = 'open',
        int $page = 1,
        int $perPage = 30,
    ): array {
        $this->repos->findRepo($user, $owner, $repo);
        $connection = $this->github->requireConnection($user);

        $apiState = $state === 'merged' ? 'closed' : $state;
        if (! in_array($apiState, ['open', 'closed', 'all'], true)) {
            $apiState = 'open';
        }

        $response = $this->github->get($connection, "repos/{$owner}/{$repo}/pulls", [
            'state' => $apiState,
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $perPage)),
            'sort' => 'updated',
            'direction' => 'desc',
        ]);

        /** @var list<array<string, mixed>> $items */
        $items = $response->json() ?? [];
        $mapped = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $mappedPr = $this->mapPull($item);
            if ($state === 'merged' && ($mappedPr['merged_at'] ?? null) === null) {
                continue;
            }
            if ($state === 'closed' && ($mappedPr['merged_at'] ?? null) !== null) {
                continue;
            }
            $mapped[] = $mappedPr;
        }

        return [
            'items' => $mapped,
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $perPage)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, string $owner, string $repo, int $number): array
    {
        $this->repos->findRepo($user, $owner, $repo);
        $connection = $this->github->requireConnection($user);
        $response = $this->github->get($connection, "repos/{$owner}/{$repo}/pulls/{$number}");

        return $this->mapPull($response->json() ?? []);
    }

    /**
     * @return array{items: list<array<string, mixed>>, page: int, per_page: int}
     */
    public function files(
        User $user,
        string $owner,
        string $repo,
        int $number,
        int $page = 1,
        int $perPage = 100,
    ): array {
        $this->repos->findRepo($user, $owner, $repo);
        $connection = $this->github->requireConnection($user);
        $response = $this->github->get($connection, "repos/{$owner}/{$repo}/pulls/{$number}/files", [
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $perPage)),
        ]);

        /** @var list<array<string, mixed>> $items */
        $items = $response->json() ?? [];
        $mapped = [];

        foreach ($items as $item) {
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
            'items' => $mapped,
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $perPage)),
        ];
    }

    /**
     * @param  array{title: string, head: string, base: string, body?: string|null, draft?: bool}  $input
     * @return array<string, mixed>
     */
    public function create(User $user, string $owner, string $repo, array $input): array
    {
        $this->repos->findRepo($user, $owner, $repo);
        $connection = $this->github->requireConnection($user);

        $body = [
            'title' => $input['title'],
            'head' => $input['head'],
            'base' => $input['base'],
        ];
        if (array_key_exists('body', $input) && $input['body'] !== null) {
            $body['body'] = $input['body'];
        }
        if (! empty($input['draft'])) {
            $body['draft'] = true;
        }

        $response = $this->github->post($connection, "repos/{$owner}/{$repo}/pulls", $body);

        return $this->mapPull($response->json() ?? []);
    }

    /**
     * @param  array{merge_method?: string, commit_title?: string|null, commit_message?: string|null}  $input
     * @return array<string, mixed>
     */
    public function merge(User $user, string $owner, string $repo, int $number, array $input): array
    {
        $this->repos->findRepo($user, $owner, $repo);
        $connection = $this->github->requireConnection($user);

        $body = [
            'merge_method' => $input['merge_method'] ?? 'merge',
        ];
        if (! empty($input['commit_title'])) {
            $body['commit_title'] = $input['commit_title'];
        }
        if (! empty($input['commit_message'])) {
            $body['commit_message'] = $input['commit_message'];
        }

        $response = $this->github->put($connection, "repos/{$owner}/{$repo}/pulls/{$number}/merge", $body);
        $payload = $response->json() ?? [];

        return [
            'sha' => $payload['sha'] ?? null,
            'merged' => (bool) ($payload['merged'] ?? false),
            'message' => $payload['message'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function markReady(User $user, string $owner, string $repo, int $number): array
    {
        return $this->runDraftMutation(
            $user,
            $owner,
            $repo,
            $number,
            <<<'GRAPHQL'
            mutation MarkReady($id: ID!) {
              markPullRequestReadyForReview(input: { pullRequestId: $id }) {
                pullRequest { number isDraft }
              }
            }
            GRAPHQL,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function convertToDraft(User $user, string $owner, string $repo, int $number): array
    {
        return $this->runDraftMutation(
            $user,
            $owner,
            $repo,
            $number,
            <<<'GRAPHQL'
            mutation ConvertDraft($id: ID!) {
              convertPullRequestToDraft(input: { pullRequestId: $id }) {
                pullRequest { number isDraft }
              }
            }
            GRAPHQL,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runDraftMutation(
        User $user,
        string $owner,
        string $repo,
        int $number,
        string $query,
    ): array {
        $this->repos->findRepo($user, $owner, $repo);
        $connection = $this->github->requireConnection($user);
        $pullResponse = $this->github->get($connection, "repos/{$owner}/{$repo}/pulls/{$number}");
        $pull = $pullResponse->json() ?? [];
        $nodeId = is_string($pull['node_id'] ?? null) ? $pull['node_id'] : null;

        if ($nodeId === null || $nodeId === '') {
            abort(422, 'Pull request node id unavailable.');
        }

        $this->github->graphql($connection, $query, ['id' => $nodeId]);

        return $this->show($user, $owner, $repo, $number);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapPull(array $item): array
    {
        $user = is_array($item['user'] ?? null) ? $item['user'] : [];
        $head = is_array($item['head'] ?? null) ? $item['head'] : [];
        $base = is_array($item['base'] ?? null) ? $item['base'] : [];

        return [
            'id' => $item['id'] ?? null,
            'node_id' => $item['node_id'] ?? null,
            'number' => $item['number'] ?? null,
            'title' => $item['title'] ?? null,
            'body' => $item['body'] ?? null,
            'state' => $item['state'] ?? null,
            'draft' => (bool) ($item['draft'] ?? false),
            'merged' => (bool) ($item['merged'] ?? false),
            'mergeable' => $item['mergeable'] ?? null,
            'mergeable_state' => $item['mergeable_state'] ?? null,
            'merged_at' => $item['merged_at'] ?? null,
            'html_url' => $item['html_url'] ?? null,
            'created_at' => $item['created_at'] ?? null,
            'updated_at' => $item['updated_at'] ?? null,
            'user' => [
                'login' => $user['login'] ?? null,
                'avatar_url' => $user['avatar_url'] ?? null,
            ],
            'head' => [
                'ref' => $head['ref'] ?? null,
                'sha' => $head['sha'] ?? null,
                'label' => $head['label'] ?? null,
            ],
            'base' => [
                'ref' => $base['ref'] ?? null,
                'sha' => $base['sha'] ?? null,
                'label' => $base['label'] ?? null,
            ],
            'additions' => $item['additions'] ?? null,
            'deletions' => $item['deletions'] ?? null,
            'changed_files' => $item['changed_files'] ?? null,
            'comments' => $item['comments'] ?? null,
            'review_comments' => $item['review_comments'] ?? null,
            'commits' => $item['commits'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapSearchIssue(array $item): array
    {
        $user = is_array($item['user'] ?? null) ? $item['user'] : [];
        $repoUrl = is_string($item['repository_url'] ?? null) ? $item['repository_url'] : '';
        $fullName = str_replace('https://api.github.com/repos/', '', $repoUrl);
        [$owner, $name] = array_pad(explode('/', $fullName, 2), 2, null);

        return [
            'id' => $item['id'] ?? null,
            'number' => $item['number'] ?? null,
            'title' => $item['title'] ?? null,
            'state' => $item['state'] ?? null,
            'draft' => (bool) ($item['draft'] ?? false),
            'html_url' => $item['html_url'] ?? null,
            'created_at' => $item['created_at'] ?? null,
            'updated_at' => $item['updated_at'] ?? null,
            'user' => [
                'login' => $user['login'] ?? null,
                'avatar_url' => $user['avatar_url'] ?? null,
            ],
            'repository' => [
                'owner' => $owner,
                'name' => $name,
                'full_name' => $fullName !== '' ? $fullName : null,
            ],
            'pull_request' => $item['pull_request'] ?? null,
        ];
    }
}
