<?php

namespace App\Http\Controllers\Api\v1;

use App\Auth\TokenUser;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AuthToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
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

        $tokens = AuthToken::issueForUser($user);

        return response()->json([
            'accessToken' => $tokens['accessToken'],
            'refreshToken' => $tokens['refreshToken'],
            'user' => $this->formatUser($user),
            'expiresIn' => AuthToken::ACCESS_TOKEN_TTL_MINUTES * 60,
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refreshToken' => ['required', 'string'],
        ]);

        $token = AuthToken::resolveValidRefreshToken($data['refreshToken']);

        if ($token === null || $token->user === null) {
            if ($token !== null && $token->user === null) {
                $token->delete();
            }

            return response()->json([
                'message' => 'Invalid refresh token.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $tokens = $token->rotateTokens();

        return response()->json([
            'accessToken' => $tokens['accessToken'],
            'refreshToken' => $tokens['refreshToken'],
            'user' => $this->formatUser($token->user),
            'expiresIn' => AuthToken::ACCESS_TOKEN_TTL_MINUTES * 60,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $accessToken = $request->bearerToken();

        if ($accessToken !== null) {
            AuthToken::query()
                ->where('access_token_hash', AuthToken::hashToken($accessToken))
                ->delete();
        }

        return response()->json([
            'message' => 'Logged out',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            return response()->json($this->formatUser($user));
        }

        if ($user instanceof TokenUser) {
            return response()->json([
                'id' => $user->getAuthIdentifier(),
                'name' => $user->name,
                'email' => null,
                'role' => $user->role()->value,
            ]);
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
}
