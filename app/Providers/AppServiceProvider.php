<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        // Login attempts are scoped to the email and source IP so one shop's
        // typo does not lock out every person behind a shared connection.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        // Account creation is a higher-risk public endpoint. Keep it modest
        // even when a future environment intentionally enables it.
        RateLimiter::for('registration', fn (Request $request) => Limit::perHour(3)
            ->by($request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perHour(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        // A person asking the AI questions in a burst is fine; a script hammering it is not.
        RateLimiter::for('ask', fn (Request $request) => Limit::perMinute(12)->by($request->user()?->id ?: $request->ip()));

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            return rtrim((string) config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });
    }
}
