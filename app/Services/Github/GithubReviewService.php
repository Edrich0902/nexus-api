<?php

namespace App\Services\Github;

use App\Integrations\Github\GithubIntegration;
use App\Models\User;

class GithubReviewService
{
    public function __construct(
        private readonly GithubIntegration $github,
        private readonly GithubRepoService $repos,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function list(User $user, string $owner, string $repo, int $number): array
    {
        $this->repos->findRepo($user, $owner, $repo);
        $connection = $this->github->requireConnection($user);
        $response = $this->github->get($connection, "repos/{$owner}/{$repo}/pulls/{$number}/reviews");
        /** @var list<array<string, mixed>> $items */
        $items = $response->json() ?? [];
        $mapped = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $mapped[] = $this->mapReview($item);
        }

        return ['items' => $mapped];
    }

    /**
     * @param  array{event: string, body?: string|null}  $input
     * @return array<string, mixed>
     */
    public function submit(User $user, string $owner, string $repo, int $number, array $input): array
    {
        $this->repos->findRepo($user, $owner, $repo);
        $connection = $this->github->requireConnection($user);

        $body = [
            'event' => $input['event'],
        ];
        if (array_key_exists('body', $input) && $input['body'] !== null && $input['body'] !== '') {
            $body['body'] = $input['body'];
        }

        $response = $this->github->post(
            $connection,
            "repos/{$owner}/{$repo}/pulls/{$number}/reviews",
            $body,
        );

        return $this->mapReview($response->json() ?? []);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapReview(array $item): array
    {
        $user = is_array($item['user'] ?? null) ? $item['user'] : [];

        return [
            'id' => $item['id'] ?? null,
            'state' => $item['state'] ?? null,
            'body' => $item['body'] ?? null,
            'submitted_at' => $item['submitted_at'] ?? null,
            'html_url' => $item['html_url'] ?? null,
            'user' => [
                'login' => $user['login'] ?? null,
                'avatar_url' => $user['avatar_url'] ?? null,
            ],
        ];
    }
}
