<?php

use App\Integrations\Exceptions\IntegrationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi();
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (IntegrationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->statusCode >= 400 && $e->statusCode < 600 ? $e->statusCode : 502;

            return response()->json([
                'message' => $e->getMessage(),
            ], $status);
        });
    })->create();
