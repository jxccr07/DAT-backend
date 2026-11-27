<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [SetLocaleFromHeader::class]);
        $middleware->alias(['role' => EnsureRole::class]);

        // The Next.js server sits in front of the API and forwards the real client IP.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Expired sign-in tokens are useless; delete them daily (needs the server cron: schedule:run every minute).
        $schedule->command('sanctum:prune-expired --hours=24')->daily();
        // Public demo: visitor-made messages, notes and alerts disappear after 10 minutes.
        $schedule->command('demo:tidy')->everyMinute()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always answer API routes with JSON, never HTML error pages.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
