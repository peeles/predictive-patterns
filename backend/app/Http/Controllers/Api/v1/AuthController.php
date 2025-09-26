<?php

namespace App\Http\Controllers\Api\v1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SanctumTokenManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    private const REFRESH_COOKIE_PATH = '/api';

    /**
     * User login
     *
     * @param Request $request
     *
     * @return JsonResponse
     * @throws ValidationException
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $tokens = SanctumTokenManager::issue($user);

        $response = response()->json([
            'accessToken' => $tokens['accessToken'],
            'user' => $this->formatUser($user),
            'expiresIn' => $tokens['expiresIn'],
        ]);

        return $this->setRefreshCookie($response, $tokens['refreshToken']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $refreshToken = $request->cookie(SanctumTokenManager::REFRESH_COOKIE_NAME);

        if (! is_string($refreshToken) || $refreshToken === '') {
            return $this->unauthorizedRefreshResponse();
        }

        $result = SanctumTokenManager::refresh($refreshToken);

        if ($result === null) {
            return $this->unauthorizedRefreshResponse();
        }

        $response = response()->json([
            'accessToken' => $result['tokens']['accessToken'],
            'user' => $this->formatUser($result['user']),
            'expiresIn' => $result['tokens']['expiresIn'],
        ]);

        return $this->setRefreshCookie($response, $result['tokens']['refreshToken']);
    }

    public function logout(Request $request): JsonResponse
    {
        SanctumTokenManager::revoke($request->bearerToken());

        $response = response()->json([
            'message' => 'Logged out',
        ]);

        return $this->forgetRefreshCookie($response);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            return response()->json($this->formatUser($user));
        }

        return response()->json([
            'message' => 'Unauthenticated.',
        ], Response::HTTP_UNAUTHORIZED);
    }

    private function formatUser(User $user): array
    {
        $role = $user->role();

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role instanceof Role ? $role->value : (string) $role,
        ];
    }

    private function setRefreshCookie(JsonResponse $response, string $refreshToken): JsonResponse
    {
        $secure = (bool) (config('session.secure') ?? false);

        $cookie = cookie(
            SanctumTokenManager::REFRESH_COOKIE_NAME,
            $refreshToken,
            SanctumTokenManager::REFRESH_TOKEN_TTL_DAYS * 24 * 60,
            self::REFRESH_COOKIE_PATH,
            config('session.domain'),
            $secure,
            true,
            false,
            config('session.same_site') ?? 'lax'
        );

        return $response->withCookie($cookie);
    }

    private function forgetRefreshCookie(JsonResponse $response): JsonResponse
    {
        $secure = (bool) (config('session.secure') ?? false);

        $cookie = cookie(
            SanctumTokenManager::REFRESH_COOKIE_NAME,
            '',
            -1,
            self::REFRESH_COOKIE_PATH,
            config('session.domain'),
            $secure,
            true,
            false,
            config('session.same_site') ?? 'lax'
        );

        return $response->withCookie($cookie);
    }

    private function unauthorizedRefreshResponse(): JsonResponse
    {
        $response = response()->json([
            'message' => 'Invalid refresh token.',
        ], Response::HTTP_UNAUTHORIZED);

        return $this->forgetRefreshCookie($response);
    }
}
