<?php

namespace App\Providers;

use App\Enums\Role;
use App\Support\ResolvesRoles;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    use ResolvesRoles;

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
            $role = $this->resolveRole($request->user());
            $limitKey = sprintf('api.rate_limits.%s', $role->value);
            $perMinute = (int) config($limitKey, config('api.rate_limits.' . Role::Viewer->value, 60));
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown';

            return Limit::perMinute(max($perMinute, 1))->by((string) $identifier);
        });
    }
}
