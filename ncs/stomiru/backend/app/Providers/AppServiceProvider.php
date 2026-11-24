<?php

namespace App\Providers;

use App\Guards\AccessTokenGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        RateLimiter::for('forgot-password', function (Request $request) {
            return Limit::perHour(3)->by($request->input('email') ?: $request->ip());
        });

        RateLimiter::for('send-mail', function (Request $request) {
            $adminId = $request->route('admin');

            return Limit::perHour(3)->by('admin-resend-'.$adminId);
        });

        RateLimiter::for('request-delete', function (Request $request) {
            return Limit::perHour(3)->by($request->path());
        });

        Auth::extend('access-token', function () {
            return new AccessTokenGuard(new LocalUserProvider);
        });
    }
}
