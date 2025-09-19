<?php

namespace App\Providers;

use App\Auth\TokenUser;
use App\Enums\Role;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();
            $role = $this->resolveRole($user);
            $limitKey = sprintf('api.rate_limits.%s', $role->value);
            $perMinute = (int) config($limitKey, config('api.rate_limits.' . Role::Viewer->value, 60));
            $identifier = $user?->getAuthIdentifier() ?? $request->ip() ?? 'unknown';

            return Limit::perMinute(max($perMinute, 1))->by((string) $identifier);
        });
    }

    private function resolveRole(mixed $user): Role
    {
        if ($user instanceof TokenUser) {
            return $user->role();
        }

        if (is_object($user) && method_exists($user, 'role')) {
            $roleValue = $user->role();

            if ($roleValue instanceof Role) {
                return $roleValue;
            }

            if (is_string($roleValue)) {
                return Role::tryFrom($roleValue) ?? Role::Viewer;
            }
        }

        if (is_object($user) && method_exists($user, 'getAttribute')) {
            $role = $user->getAttribute('role');

            if ($role instanceof Role) {
                return $role;
            }

            if (is_string($role)) {
                return Role::tryFrom($role) ?? Role::Viewer;
            }
        }

        return Role::Viewer;
    }
}
