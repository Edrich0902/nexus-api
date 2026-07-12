<?php

namespace Tests\Feature\Api\V1;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\AuthService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_and_user_for_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', 'edrich@nexus.test')
            ->assertJsonStructure([
                'token',
                'token_type',
                'expires_at',
                'user' => ['id', 'name', 'email', 'created_at', 'updated_at'],
            ]);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_default_token_expires_in_four_hours(): void
    {
        Carbon::setTestNow('2026-07-12 12:00:00');

        User::factory()->create([
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        $response->assertOk();

        $expiresAt = Carbon::parse($response->json('expires_at'));

        $this->assertTrue(
            $expiresAt->equalTo(now()->addMinutes(AuthService::SESSION_LIFETIME_MINUTES)),
        );

        Carbon::setTestNow();
    }

    public function test_login_remember_token_expires_in_twenty_four_hours(): void
    {
        Carbon::setTestNow('2026-07-12 12:00:00');

        User::factory()->create([
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'password',
            'remember' => true,
        ]);

        $response->assertOk();

        $expiresAt = Carbon::parse($response->json('expires_at'));

        $this->assertTrue(
            $expiresAt->equalTo(now()->addMinutes(AuthService::REMEMBER_LIFETIME_MINUTES)),
        );

        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertContains(AuthService::REMEMBER_ABILITY, $token->abilities);

        Carbon::setTestNow();
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_refresh_rotates_token_and_preserves_remember_lifetime(): void
    {
        Carbon::setTestNow('2026-07-12 12:00:00');

        $user = User::factory()->create([
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        $oldToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'password',
            'remember' => true,
        ])->json('token');

        $refreshResponse = $this->withToken($oldToken)
            ->postJson('/api/v1/auth/refresh');

        $refreshResponse
            ->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user']);

        $newToken = $refreshResponse->json('token');
        $this->assertNotSame($oldToken, $newToken);

        $expiresAt = Carbon::parse($refreshResponse->json('expires_at'));
        $this->assertTrue(
            $expiresAt->equalTo(now()->addMinutes(AuthService::REMEMBER_LIFETIME_MINUTES)),
        );

        $this->assertSame(1, $user->fresh()->tokens()->count());
        $this->assertContains(
            AuthService::REMEMBER_ABILITY,
            PersonalAccessToken::findToken($newToken)->abilities,
        );

        $this->app['auth']->forgetGuards();

        $this->withToken($oldToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        $this->withToken($newToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id);

        Carbon::setTestNow();
    }

    public function test_refresh_preserves_session_lifetime_without_remember(): void
    {
        Carbon::setTestNow('2026-07-12 12:00:00');

        User::factory()->create([
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ])->json('token');

        $expiresAt = Carbon::parse(
            $this->withToken($token)
                ->postJson('/api/v1/auth/refresh')
                ->assertOk()
                ->json('expires_at'),
        );

        $this->assertTrue(
            $expiresAt->equalTo(now()->addMinutes(AuthService::SESSION_LIFETIME_MINUTES)),
        );

        Carbon::setTestNow();
    }

    public function test_expired_token_cannot_refresh(): void
    {
        User::factory()->create([
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ])->json('token');

        $accessToken = PersonalAccessToken::findToken($token);
        $accessToken->forceFill([
            'expires_at' => now()->subMinute(),
        ])->save();

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson('/api/v1/auth/refresh')
            ->assertUnauthorized();
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create([
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        $token = $loginResponse->json('token');

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertSame(0, $user->fresh()->tokens()->count());

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_logout_all_revokes_every_token(): void
    {
        $user = User::factory()->create([
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        $firstToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'password',
            'device_name' => 'web',
        ])->json('token');

        $secondToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'password',
            'device_name' => 'mobile',
        ])->json('token');

        $this->assertSame(2, $user->fresh()->tokens()->count());

        $this->withToken($firstToken)
            ->postJson('/api/v1/auth/logout-all')
            ->assertNoContent();

        $this->assertSame(0, $user->fresh()->tokens()->count());

        $this->app['auth']->forgetGuards();

        $this->withToken($secondToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        User::factory()->create([
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'edrich@nexus.test',
                'password' => 'password',
            ])->assertOk();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ])->assertStatus(429);
    }

    public function test_sessions_lists_tokens_with_device_metadata(): void
    {
        $user = User::factory()->create([
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        $firstToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'password',
            'device_name' => 'web',
        ], [
            'User-Agent' => 'NexusTestBrowser/1.0',
        ])->json('token');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'password',
            'device_name' => 'mobile',
            'remember' => true,
        ], [
            'User-Agent' => 'NexusMobile/2.0',
        ]);

        $response = $this->withToken($firstToken)
            ->getJson('/api/v1/auth/sessions');

        $response
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'name',
                    'device' => ['name', 'ip_address', 'user_agent'],
                    'remember',
                    'last_used_at',
                    'expires_at',
                    'created_at',
                    'is_current',
                ],
            ]);

        $sessions = collect($response->json());
        $this->assertTrue($sessions->contains(fn (array $session) => $session['is_current'] === true));
        $this->assertSame(1, $sessions->where('is_current', true)->count());
        $this->assertTrue($sessions->contains(
            fn (array $session) => $session['name'] === 'mobile'
                && $session['remember'] === true
                && $session['device']['user_agent'] === 'NexusMobile/2.0',
        ));
        $this->assertTrue($sessions->contains(
            fn (array $session) => $session['name'] === 'web'
                && $session['device']['user_agent'] === 'NexusTestBrowser/1.0'
                && filled($session['device']['ip_address']),
        ));
        $this->assertSame(2, $user->fresh()->tokens()->count());
    }

    public function test_revoke_session_deletes_one_device_token(): void
    {
        $user = User::factory()->create([
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        $firstToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'password',
            'device_name' => 'web',
        ])->json('token');

        $secondToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'password',
            'device_name' => 'mobile',
        ])->json('token');

        $secondTokenId = PersonalAccessToken::findToken($secondToken)->id;

        $this->withToken($firstToken)
            ->deleteJson("/api/v1/auth/sessions/{$secondTokenId}")
            ->assertNoContent();

        $this->assertSame(1, $user->fresh()->tokens()->count());

        $this->app['auth']->forgetGuards();

        $this->withToken($secondToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        $this->withToken($firstToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_revoke_session_returns_not_found_for_unknown_token(): void
    {
        User::factory()->create([
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'edrich@nexus.test',
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)
            ->deleteJson('/api/v1/auth/sessions/999999')
            ->assertNotFound();
    }
}
