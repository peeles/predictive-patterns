<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsureApiTokenIsValid;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\HorizonServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        AppServiceProvider::class,
        AuthServiceProvider::class,
        HorizonServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->use([
            HandleCors::class,
        ]);

        $middleware->group('api', [
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'auth.api' => EnsureApiTokenIsValid::class,
            'throttle' => ThrottleRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(static function (Request $request): bool {
            return $request->is('api/*');
        });

        $exceptions->render(static function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiExceptionRenderer::render($exception, $request);
        });
    })->create();
