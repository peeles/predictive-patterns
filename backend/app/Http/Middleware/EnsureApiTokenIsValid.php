<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiTokenIsValid
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $tokens = array_filter(config('api.tokens', []));

        if ($tokens === []) {
            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'API tokens are not configured.');
        }

        $providedToken = $this->extractToken($request);

        if ($providedToken !== null && $this->matchesConfiguredToken($providedToken, $tokens)) {
            return $next($request);
        }

        abort(Response::HTTP_UNAUTHORIZED, 'Invalid API token.');
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

        $token = trim((string)$token);

        return $token === '' ? null : $token;
    }

    /**
     * @param string[] $tokens
     */
    private function matchesConfiguredToken(string $providedToken, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (!is_string($token) || $token === '') {
                continue;
            }

            if (hash_equals($token, $providedToken)) {
                return true;
            }
        }

        return false;
    }
}
