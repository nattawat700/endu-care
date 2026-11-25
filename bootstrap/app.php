<?php

use App\Http\Middleware\EnsureActiveStaffSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => require base_path('routes/integration-webhooks.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['device_passcode']);
        $middleware->web(append: [EnsureActiveStaffSession::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['device_passcode', 'device_unlock_pattern', 'setup_code', 'mfa_code', 'token', 'webhook_secret', 'connector_token']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
