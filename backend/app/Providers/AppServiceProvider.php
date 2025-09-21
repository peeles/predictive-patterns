<?php

namespace App\Providers;

use App\Enums\Role;
use App\Support\ResolvesRoles;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Random\RandomException;

class AppServiceProvider extends ServiceProvider
{
    use ResolvesRoles;

    /**
     * Register any application services.
     *
     * @throws RandomException
     */
    public function register(): void
    {
        $this->ensureEncryptionKey();
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

    /**
     * @throws RandomException
     */
    private function ensureEncryptionKey(): void
    {
        if (filled(config('app.key'))) {
            return;
        }

        $keyPath = storage_path('app/app.key');

        if (File::exists($keyPath)) {
            $storedKey = trim((string) File::get($keyPath));

            if ($storedKey !== '') {
                config(['app.key' => $storedKey]);

                return;
            }
        }

        File::ensureDirectoryExists(dirname($keyPath));

        $generatedKey = 'base64:' . base64_encode(random_bytes(32));

        File::put($keyPath, $generatedKey . PHP_EOL, true);
        @chmod($keyPath, 0600);

        config(['app.key' => $generatedKey]);
    }
}
