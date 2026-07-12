<?php

namespace App\Services\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

class AuthService
{
    public const DEFAULT_TOKEN_NAME = 'nexus-web';

    public const SESSION_LIFETIME_MINUTES = 240;

    public const REMEMBER_LIFETIME_MINUTES = 1440;

    public const REMEMBER_ABILITY = 'remember';

    /**
     * @return array{user: User, token: string, expires_at: string}
     *
     * @throws ValidationException
     */
    public function login(
        string $email,
        string $password,
        string $tokenName = self::DEFAULT_TOKEN_NAME,
        bool $rememberMe = false,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $accessToken = $this->issueTokenForUser(
            user: $user,
            tokenName: $tokenName,
            rememberMe: $rememberMe,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );

        return $this->tokenResponse($user, $accessToken);
    }

    /**
     * @return array{user: User, token: string, expires_at: string}
     */
    public function refreshCurrentToken(
        User $user,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $currentToken = $user->currentAccessToken();

        if (! $currentToken instanceof PersonalAccessToken) {
            throw ValidationException::withMessages([
                'token' => ['Unable to refresh the current access token.'],
            ]);
        }

        $tokenName = $currentToken->name;
        $rememberMe = in_array(self::REMEMBER_ABILITY, $currentToken->abilities ?? [], true);

        $currentToken->delete();

        $accessToken = $this->issueTokenForUser(
            user: $user,
            tokenName: $tokenName,
            rememberMe: $rememberMe,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );

        return $this->tokenResponse($user, $accessToken);
    }

    public function issueTokenForUser(
        User $user,
        string $tokenName = self::DEFAULT_TOKEN_NAME,
        bool $rememberMe = false,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): NewAccessToken {
        $abilities = $rememberMe ? ['*', self::REMEMBER_ABILITY] : ['*'];
        $lifetimeMinutes = $rememberMe
            ? self::REMEMBER_LIFETIME_MINUTES
            : self::SESSION_LIFETIME_MINUTES;

        $accessToken = $user->createToken(
            $tokenName,
            $abilities,
            now()->addMinutes($lifetimeMinutes),
        );

        $accessToken->accessToken->forceFill([
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ])->save();

        return $accessToken;
    }

    public function revokeCurrentToken(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    /**
     * @return \Illuminate\Support\Collection<int, PersonalAccessToken>
     */
    public function listSessions(User $user)
    {
        return $user->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();
    }

    public function revokeSession(User $user, int $tokenId): void
    {
        $deleted = $user->tokens()->whereKey($tokenId)->delete();

        if ($deleted === 0) {
            abort(404, 'Session not found.');
        }
    }

    public function updateProfile(User $user, string $name): User
    {
        $user->forceFill([
            'name' => $name,
        ])->save();

        return $user->refresh();
    }

    /**
     * @return array{user: User, token: string, expires_at: string}
     */
    private function tokenResponse(User $user, NewAccessToken $accessToken): array
    {
        return [
            'user' => $user,
            'token' => $accessToken->plainTextToken,
            'expires_at' => $accessToken->accessToken->expires_at->toIso8601String(),
        ];
    }
}
