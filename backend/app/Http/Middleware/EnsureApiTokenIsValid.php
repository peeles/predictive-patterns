<?php

namespace App\Http\Middleware;

use App\Auth\TokenUser;
use App\Enums\Role;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiTokenIsValid
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $tokens = Collection::make(config('api.tokens', []))
            ->map(function (array $token): array {
                $value = (string) ($token['token'] ?? '');
                $role = $token['role'] ?? Role::Viewer;

                return [
                    'token' => $value,
                    'role' => $role instanceof Role ? $role : Role::tryFrom((string) $role) ?? Role::Viewer,
                ];
            })
            ->filter(fn (array $token): bool => $token['token'] !== '')
            ->values();

        if ($tokens->isEmpty()) {
            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'API tokens are not configured.');
        }

        $providedToken = $this->extractToken($request);

        if ($providedToken === null) {
            abort(Response::HTTP_UNAUTHORIZED, 'Invalid API token.');
        }

        $match = $tokens->first(function (array $token) use ($providedToken): bool {
            return hash_equals($token['token'], $providedToken);
        });

        if ($match === null) {
            abort(Response::HTTP_UNAUTHORIZED, 'Invalid API token.');
        }

        $request->setUserResolver(fn (): Authenticatable => TokenUser::fromRole($match['role']));

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $token = $request->bearerToken();

        if ($token === null) {
            $token = $request->header('X-API-Key');
        }

        if ($token === null) {
            $token = $request->query('api_token');
        }

        if ($token === null) {
            return null;
        }

        $token = trim((string) $token);

        return $token === '' ? null : $token;
    }
}
