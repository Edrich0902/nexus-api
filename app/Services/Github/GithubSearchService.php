<?php

namespace App\Services\Github;

use App\Integrations\Github\GithubIntegration;
use App\Models\Integration\IntegrationConnection;
use App\Models\User;

class GithubSearchService
{
    public function __construct(
        private readonly GithubIntegration $github,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, total_count: int, page: int, per_page: int, type: string}
     */
    public function search(User $user, string $query, string $type = 'repositories', int $page = 1, int $perPage = 20): array
    {
        $connection = $this->github->requireConnection($user);
        $query = trim($query);
        $type = in_array($type, ['repositories', 'issues', 'code'], true) ? $type : 'repositories';
        $page = max(1, $page);
        $perPage = max(1, min(20, $perPage));

        if ($query === '') {
            return [
                'items' => [],
                'total_count' => 0,
                'page' => $page,
                'per_page' => $perPage,
                'type' => $type,
            ];
        }

        $login = $this->resolveLogin($connection);
        $scopedQuery = $this->scopeQueryToAccount($query, $login);

        $path = match ($type) {
            'issues' => 'search/issues',
            'code' => 'search/code',
            default => 'search/repositories',
        };

        $response = $this->github->get($connection, $path, [
            'q' => $scopedQuery,
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $payload = $response->json() ?? [];
        $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $items = match ($type) {
            'issues' => $this->mapIssues($rawItems),
            'code' => $this->mapCode($rawItems),
            default => $this->mapRepositories($rawItems),
        };

        return [
            'items' => $items,
            'total_count' => (int) ($payload['total_count'] ?? count($items)),
            'page' => $page,
            'per_page' => $perPage,
            'type' => $type,
        ];
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function mapRepositories(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $owner = is_array($item['owner'] ?? null) ? $item['owner'] : [];
            $out[] = [
                'id' => $item['id'] ?? null,
                'full_name' => $item['full_name'] ?? null,
                'name' => $item['name'] ?? null,
                'owner' => $owner['login'] ?? null,
                'private' => (bool) ($item['private'] ?? false),
                'description' => $item['description'] ?? null,
                'html_url' => $item['html_url'] ?? null,
                'language' => $item['language'] ?? null,
                'stargazers_count' => $item['stargazers_count'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function mapIssues(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $repoUrl = is_string($item['repository_url'] ?? null) ? $item['repository_url'] : '';
            $fullName = str_replace('https://api.github.com/repos/', '', $repoUrl);
            [$owner, $name] = array_pad(explode('/', $fullName, 2), 2, null);
            $isPr = isset($item['pull_request']);

            $out[] = [
                'id' => $item['id'] ?? null,
                'number' => $item['number'] ?? null,
                'title' => $item['title'] ?? null,
                'state' => $item['state'] ?? null,
                'html_url' => $item['html_url'] ?? null,
                'is_pull_request' => $isPr,
                'repository' => [
                    'owner' => $owner,
                    'name' => $name,
                    'full_name' => $fullName !== '' ? $fullName : null,
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function mapCode(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $repo = is_array($item['repository'] ?? null) ? $item['repository'] : [];
            $owner = is_array($repo['owner'] ?? null) ? $repo['owner'] : [];

            $out[] = [
                'name' => $item['name'] ?? null,
                'path' => $item['path'] ?? null,
                'sha' => $item['sha'] ?? null,
                'html_url' => $item['html_url'] ?? null,
                'repository' => [
                    'owner' => $owner['login'] ?? null,
                    'name' => $repo['name'] ?? null,
                    'full_name' => $repo['full_name'] ?? null,
                ],
            ];
        }

        return $out;
    }

    private function resolveLogin(IntegrationConnection $connection): string
    {
        $response = $this->github->get($connection, 'user');
        $payload = $response->json() ?? [];
        $login = is_string($payload['login'] ?? null) ? trim($payload['login']) : '';

        if ($login === '') {
            abort(422, 'Unable to resolve GitHub username for scoped search.');
        }

        return $login;
    }

    private function scopeQueryToAccount(string $query, string $login): string
    {
        // Drop ownership / org qualifiers so callers cannot widen past this account.
        $cleaned = preg_replace(
            '/\b(?:user|org|repo|author|assignee|involves|commenter|mentions):[^\s]+/i',
            '',
            $query,
        );
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned ?? $query) ?? $query);

        if ($cleaned === '') {
            $cleaned = '*';
        }

        return $cleaned.' user:'.$login;
    }
}
