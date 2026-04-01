<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Exclude logout routes from CSRF validation.
        // Logout only destroys a session — no sensitive state change — so CSRF is unnecessary.
        // This eliminates the 419 "Page Expired" error when the same session is shared
        // between /chat and /admin tabs (e.g. logging out of one invalidates the other's token).
        $middleware->validateCsrfTokens(except: [
            'logout',        // main app logout
            'admin/logout',  // Filament admin logout
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Safety net: any remaining CSRF mismatch (stale form on non-logout routes).
        // Redirect admin requests → Filament login, regular requests → login page.
        $exceptions->render(function (TokenMismatchException $e, $request) {
            if ($request->is('admin*')) {
                return redirect('/admin');
            }
            return redirect()->route('login')
                ->withErrors(['error' => 'Your session has expired. Please log in again.']);
        });

        // 405 MethodNotAllowed on GET /logout or GET /admin/logout
        $exceptions->render(function (MethodNotAllowedHttpException $e, $request) {
            if ($request->is('logout')) {
                return redirect()->route('login');
            }
            if ($request->is('admin/logout')) {
                return redirect('/admin');
            }
        });
    })->create();
