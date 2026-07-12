<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\AccessTokenResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $result = $this->authService->login(
            email: $credentials['email'],
            password: $credentials['password'],
            tokenName: $credentials['device_name'] ?? AuthService::DEFAULT_TOKEN_NAME,
            rememberMe: (bool) ($credentials['remember'] ?? false),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return $this->tokenJsonResponse($result);
    }

    public function refresh(Request $request): JsonResponse
    {
        $result = $this->authService->refreshCurrentToken(
            user: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return $this->tokenJsonResponse($result);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function sessions(Request $request): AnonymousResourceCollection
    {
        return AccessTokenResource::collection(
            $this->authService->listSessions($request->user()),
        );
    }

    public function revokeSession(Request $request, int $tokenId): Response
    {
        $this->authService->revokeSession($request->user(), $tokenId);

        return response()->noContent();
    }

    public function logout(Request $request): Response
    {
        $this->authService->revokeCurrentToken($request->user());

        return response()->noContent();
    }

    public function logoutAll(Request $request): Response
    {
        $this->authService->revokeAllTokens($request->user());

        return response()->noContent();
    }

    /**
     * @param  array{user: \App\Models\User, token: string, expires_at: string}  $result
     */
    private function tokenJsonResponse(array $result): JsonResponse
    {
        return response()->json([
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'expires_at' => $result['expires_at'],
            'user' => (new UserResource($result['user']))->resolve(),
        ]);
    }
}
