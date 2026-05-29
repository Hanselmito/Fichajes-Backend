<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
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
        RateLimiter::for('auth-login', function (Request $request): Limit {
            $username = Str::lower($request->input('username', 'guest'));
            $maxAttempts = max(1, (int) config('auth.legacy_api.login_max_attempts', 5));
            $decaySeconds = max(1, (int) config('auth.legacy_api.login_decay_seconds', 60));

            return Limit::perMinutes($decaySeconds / 60, $maxAttempts)
                ->by($username.'|'.$request->ip())
                ->response(static function () {
                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiados intentos de login. Intentalo de nuevo en unos instantes.',
                    ], 429);
                });
        });
    }
}
