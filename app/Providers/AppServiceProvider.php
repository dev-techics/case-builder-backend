<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
Use App\Models\Document;
use App\Observers\DocumentObserver;
use Illuminate\Auth\Notifications\ResetPassword;


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
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            );
        });
        Document::observe(DocumentObserver::class);
        ResetPassword::createUrlUsing(function ($user, string $token) {
            
        return config('app.frontend_url')
            . '/reset-password'
            . '?token=' . $token
            . '&email=' . urlencode($user->email);
    });
    }
}
