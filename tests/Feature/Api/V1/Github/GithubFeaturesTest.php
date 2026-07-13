<?php

namespace Tests\Feature\Api\V1\Github;

use App\Integrations\Github\GithubIntegration;
use App\Models\Github\GithubRepo;
use App\Models\Integration\IntegrationConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GithubFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.github.client_id' => 'test-github-client',
            'services.github.client_secret' => 'test-github-secret',
            'services.github.redirect' => 'http://127.0.0.1:80/github/callback',
            'services.github.frontend_redirect' => 'http://nexus.test/github',
        ]);

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        IntegrationConnection::query()->create([
            'user_id' => $this->user->id,
            'provider' => GithubIntegration::PROVIDER,
            'external_user_id' => '4242',
            'scopes' => '',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'status' => IntegrationConnection::STATUS_ACTIVE,
        ]);

        GithubRepo::query()->create([
            'user_id' => $this->user->id,
            'github_id' => 99,
            'owner_login' => 'edrich',
            'name' => 'nexus-api',
            'full_name' => 'edrich/nexus-api',
            'private' => true,
            'default_branch' => 'main',
            'html_url' => 'https://github.com/edrich/nexus-api',
            'description' => 'API',
            'pushed_at' => now(),
            'language' => 'PHP',
            'starred' => false,
        ]);
    }

    public function test_search_proxies_repositories(): void
    {
        Http::fake([
            'api.github.com/user' => Http::response([
                'id' => 4242,
                'login' => 'edrich',
            ], 200),
            'api.github.com/search/repositories*' => Http::response([
                'total_count' => 1,
                'items' => [
                    [
                        'id' => 1,
                        'full_name' => 'edrich/nexus-api',
                        'name' => 'nexus-api',
                        'owner' => ['login' => 'edrich'],
                        'private' => true,
                        'description' => 'API',
                        'html_url' => 'https://github.com/edrich/nexus-api',
                        'language' => 'PHP',
                        'stargazers_count' => 3,
                    ],
                ],
            ], 200),
        ]);

        $this->getJson('/api/v1/github/search?q=nexus&type=repositories')
            ->assertOk()
            ->assertJsonPath('type', 'repositories')
            ->assertJsonPath('items.0.full_name', 'edrich/nexus-api')
            ->assertJsonPath('total_count', 1);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'search/repositories')) {
                return false;
            }

            return ($request['q'] ?? null) === 'nexus user:edrich';
        });
    }

    public function test_star_and_unstar_repo(): void
    {
        Http::fake([
            'api.github.com/user/starred/edrich/nexus-api' => Http::sequence()
                ->push([], 204)
                ->push([], 204),
        ]);

        $this->postJson('/api/v1/github/repos/edrich/nexus-api/star')
            ->assertOk()
            ->assertJsonPath('starred', true)
            ->assertJsonPath('github_synced', true);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT') {
                return false;
            }
            if (! str_contains($request->url(), 'user/starred/edrich/nexus-api')) {
                return false;
            }

            return ($request->header('Content-Length')[0] ?? null) === '0'
                || $request->body() === '';
        });

        $this->assertDatabaseHas('github_repos', [
            'user_id' => $this->user->id,
            'full_name' => 'edrich/nexus-api',
            'starred' => true,
        ]);

        $this->deleteJson('/api/v1/github/repos/edrich/nexus-api/star')
            ->assertOk()
            ->assertJsonPath('starred', false)
            ->assertJsonPath('github_synced', true);

        $this->assertDatabaseHas('github_repos', [
            'user_id' => $this->user->id,
            'full_name' => 'edrich/nexus-api',
            'starred' => false,
        ]);
    }

    public function test_star_falls_back_to_nexus_when_github_blocks_write(): void
    {
        Http::fake([
            'api.github.com/user/starred/edrich/nexus-api' => Http::response([
                'message' => 'Resource not accessible by integration',
                'documentation_url' => 'https://docs.github.com/rest/reference/activity#star-a-repository-for-the-authenticated-user',
            ], 403),
        ]);

        $this->postJson('/api/v1/github/repos/edrich/nexus-api/star')
            ->assertOk()
            ->assertJsonPath('starred', true)
            ->assertJsonPath('github_synced', false);

        $this->assertDatabaseHas('github_repos', [
            'user_id' => $this->user->id,
            'full_name' => 'edrich/nexus-api',
            'starred' => true,
        ]);
    }

    public function test_pulse_returns_open_merged_and_commits(): void
    {
        GithubRepo::query()
            ->where('user_id', $this->user->id)
            ->update(['pushed_at' => now()]);

        Http::fake([
            'api.github.com/user' => Http::response([
                'id' => 4242,
                'login' => 'edrich',
            ], 200),
            'api.github.com/search/issues*' => function ($request) {
                $q = $request['q'] ?? '';
                if (str_contains($q, 'is:merged')) {
                    return Http::response([
                        'total_count' => 1,
                        'items' => [
                            [
                                'id' => 2,
                                'number' => 6,
                                'title' => 'Merged fix',
                                'state' => 'closed',
                                'updated_at' => '2026-07-12T12:00:00Z',
                                'repository_url' => 'https://api.github.com/repos/edrich/nexus-api',
                                'user' => ['login' => 'edrich'],
                                'pull_request' => ['merged_at' => '2026-07-12T12:00:00Z'],
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'total_count' => 1,
                    'items' => [
                        [
                            'id' => 1,
                            'number' => 7,
                            'title' => 'Open feature',
                            'state' => 'open',
                            'draft' => false,
                            'updated_at' => '2026-07-13T10:00:00Z',
                            'repository_url' => 'https://api.github.com/repos/edrich/nexus-api',
                            'user' => ['login' => 'edrich'],
                            'pull_request' => [],
                        ],
                    ],
                ], 200);
            },
            'api.github.com/repos/edrich/nexus-api/commits*' => Http::response([
                [
                    'sha' => 'abc1234',
                    'html_url' => 'https://github.com/edrich/nexus-api/commit/abc1234',
                    'commit' => [
                        'message' => "Fix pulse widget\n\nDetails",
                        'author' => [
                            'name' => 'Edrich',
                            'date' => '2026-07-13T09:00:00Z',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->getJson('/api/v1/github/pulse')
            ->assertOk()
            ->assertJsonPath('open_pulls.0.number', 7)
            ->assertJsonPath('merged_pulls.0.number', 6)
            ->assertJsonPath('commits.0.sha', 'abc1234')
            ->assertJsonPath('commits.0.repository.full_name', 'edrich/nexus-api');
    }

    public function test_create_and_delete_branch(): void
    {
        Http::fake([
            'api.github.com/repos/edrich/nexus-api/git/ref/heads/*' => Http::response([
                'ref' => 'refs/heads/main',
                'object' => ['sha' => 'abc123def'],
            ], 200),
            'api.github.com/repos/edrich/nexus-api/git/refs' => Http::response([
                'ref' => 'refs/heads/feature/x',
                'object' => ['sha' => 'abc123def'],
            ], 201),
            'api.github.com/repos/edrich/nexus-api/git/refs/heads/*' => Http::response([], 204),
        ]);

        $this->postJson('/api/v1/github/repos/edrich/nexus-api/branches', [
            'name' => 'feature/x',
            'from' => 'main',
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'feature/x');

        $this->deleteJson('/api/v1/github/repos/edrich/nexus-api/branches/'.rawurlencode('feature/x'))
            ->assertNoContent();

        $this->deleteJson('/api/v1/github/repos/edrich/nexus-api/branches/main')
            ->assertStatus(422);
    }

    public function test_create_draft_pull_and_mark_ready(): void
    {
        Http::fake([
            'api.github.com/repos/edrich/nexus-api/pulls' => Http::response([
                'id' => 10,
                'node_id' => 'PR_kwDOA',
                'number' => 12,
                'title' => 'Draft PR',
                'state' => 'open',
                'draft' => true,
                'user' => ['login' => 'edrich'],
                'head' => ['ref' => 'feat'],
                'base' => ['ref' => 'main'],
                'html_url' => 'https://github.com/edrich/nexus-api/pull/12',
            ], 201),
            'api.github.com/repos/edrich/nexus-api/pulls/12' => Http::response([
                'id' => 10,
                'node_id' => 'PR_kwDOA',
                'number' => 12,
                'title' => 'Draft PR',
                'state' => 'open',
                'draft' => false,
                'user' => ['login' => 'edrich'],
                'head' => ['ref' => 'feat'],
                'base' => ['ref' => 'main'],
                'html_url' => 'https://github.com/edrich/nexus-api/pull/12',
            ], 200),
            'api.github.com/graphql' => Http::response([
                'data' => [
                    'markPullRequestReadyForReview' => [
                        'pullRequest' => ['number' => 12, 'isDraft' => false],
                    ],
                ],
            ], 200),
        ]);

        $this->postJson('/api/v1/github/repos/edrich/nexus-api/pulls', [
            'title' => 'Draft PR',
            'head' => 'feat',
            'base' => 'main',
            'draft' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('draft', true)
            ->assertJsonPath('number', 12);

        $this->postJson('/api/v1/github/repos/edrich/nexus-api/pulls/12/ready')
            ->assertOk()
            ->assertJsonPath('draft', false);
    }

    public function test_list_and_submit_reviews(): void
    {
        Http::fake([
            'api.github.com/repos/edrich/nexus-api/pulls/7/reviews' => function ($request) {
                if ($request->method() === 'POST') {
                    return Http::response([
                        'id' => 55,
                        'state' => 'APPROVED',
                        'body' => 'LGTM',
                        'submitted_at' => '2026-07-13T00:00:00Z',
                        'user' => ['login' => 'edrich'],
                    ], 201);
                }

                return Http::response([
                    [
                        'id' => 54,
                        'state' => 'COMMENTED',
                        'body' => 'Nit',
                        'submitted_at' => '2026-07-12T00:00:00Z',
                        'user' => ['login' => 'reviewer'],
                    ],
                ], 200);
            },
        ]);

        $this->getJson('/api/v1/github/repos/edrich/nexus-api/pulls/7/reviews')
            ->assertOk()
            ->assertJsonPath('items.0.state', 'COMMENTED');

        $this->postJson('/api/v1/github/repos/edrich/nexus-api/pulls/7/reviews', [
            'event' => 'APPROVE',
            'body' => 'LGTM',
        ])
            ->assertCreated()
            ->assertJsonPath('state', 'APPROVED');

        $this->postJson('/api/v1/github/repos/edrich/nexus-api/pulls/7/reviews', [
            'event' => 'COMMENT',
        ])->assertStatus(422);
    }

    public function test_stats_endpoint_returns_contribution_snapshot(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        Http::fake([
            'api.github.com/user' => Http::response([
                'id' => 4242,
                'login' => 'edrich',
            ], 200),
            'api.github.com/graphql' => Http::response([
                'data' => [
                    'viewer' => [
                        'contributionsCollection' => [
                            'contributionCalendar' => [
                                'totalContributions' => 42,
                                'weeks' => [
                                    [
                                        'contributionDays' => [
                                            [
                                                'date' => $yesterday,
                                                'contributionCount' => 2,
                                                'color' => '#26a641',
                                            ],
                                            [
                                                'date' => $today,
                                                'contributionCount' => 1,
                                                'color' => '#39d353',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'api.github.com/search/issues*' => Http::response([
                'total_count' => 3,
                'items' => [],
            ], 200),
        ]);

        $this->getJson('/api/v1/github/stats')
            ->assertOk()
            ->assertJsonPath('total_contributions', 42)
            ->assertJsonPath('current_streak', 2)
            ->assertJsonPath('open_pr_count', 3)
            ->assertJsonPath('languages.0.language', 'PHP')
            ->assertJsonPath('top_repos.0.full_name', 'edrich/nexus-api')
            ->assertJsonStructure([
                'calendar' => ['weeks'],
                'sparkline',
                'computed_at',
            ]);
    }
}
