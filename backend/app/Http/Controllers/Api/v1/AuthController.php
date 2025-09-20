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

        return response()->json([
            'accessToken' => $tokens['accessToken'],
            'refreshToken' => $tokens['refreshToken'],
            'user' => $this->formatUser($user),
            'expiresIn' => $tokens['expiresIn'],
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refreshToken' => ['required', 'string'],
        ]);

        $result = SanctumTokenManager::refresh($data['refreshToken']);

        if ($result === null) {
            return response()->json([
                'message' => 'Invalid refresh token.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'accessToken' => $result['tokens']['accessToken'],
            'refreshToken' => $result['tokens']['refreshToken'],
            'user' => $this->formatUser($result['user']),
            'expiresIn' => $result['tokens']['expiresIn'],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        SanctumTokenManager::revoke($request->bearerToken());

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
