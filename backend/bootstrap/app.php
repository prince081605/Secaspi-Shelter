<?php

use Illuminate\Auth\AuthenticationException;
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
    $middleware->use([
        \Illuminate\Http\Middleware\HandleCors::class,
    ]);
    $middleware->alias([
        'admin' => \App\Http\Middleware\EnsureAdmin::class,
    ]);
    // API-only app with no `login` route. Returning null here stops the auth middleware from
    // trying to redirect unauthenticated guests to route('login') (which would throw a 500);
    // with no redirect target the request resolves to a clean 401 JSON instead.
    $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
})
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // This is an API-only app with no `login` route. Without this, an unauthenticated
        // request that doesn't explicitly ask for JSON makes the framework try to redirect to
        // route('login') and throw a 500. Force a clean 401 JSON for any api/* request instead.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return null;
        });
    })->create();