<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Brute-force protection: 5 attempts/min per account, 30/min per IP.
        // The key is built before validation, so the email may be any JSON type: only a string names an account.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.Str::lower(is_string($email = $request->input('email')) ? $email : '')),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
