<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind the TLS-terminating proxy the request must read the forwarded scheme, or a signed link (made for
        // https) fails its own signature check. Host and prefix are not trusted: the proxy passes Host through.
        // A guest on a panel path goes to the panel's own login page; on any other path to the storefront's.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('admin', 'admin/*') ? route('filament.admin.auth.login') : route('login'),
        );
        $middleware->redirectUsersTo(fn () => route('account'));

        // A password reset changes the stored hash, which ends every other session signed in with the old password.
        $middleware->web(append: [AuthenticateSession::class]);

        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
