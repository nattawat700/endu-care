<?php

namespace App\Providers;

use App\Http\Controllers\StaffMfaController;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Auth\Notifications\ResetPassword;
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
        foreach (AuditObserver::models() as $model) {
            $model::observe(AuditObserver::class);
        }

        ResetPassword::createUrlUsing(fn (User $user, string $token): string => rtrim(config('app.url'), '/')
            .route('password.reset', ['token' => $token, 'email' => $user->email], absolute: false));
        RateLimiter::for('staff-mfa', function (Request $request): array {
            return [
                Limit::perMinute(40)->by('ip:'.$request->ip()),
                Limit::perMinute(8)->by('pending:'.($request->session()->get(StaffMfaController::PENDING)['id'] ?? $request->ip())),
            ];
        });
        RateLimiter::for('staff-recovery', fn (Request $request): array => [
            Limit::perMinute(30)->by('ip:'.$request->ip()),
            Limit::perMinute(6)->by('account:'.Str::lower($request->string('email')->limit(254, '')->toString()).'|'.$request->ip()),
        ]);
        RateLimiter::for('login', function (Request $request): array {
            $email = Str::lower($request->string('email')->limit(254, '')->toString());

            return [
                Limit::perMinute(50)->by('ip:'.$request->ip()),
                Limit::perMinute(8)->by('account:'.$email.'|'.$request->ip()),
            ];
        });
    }
}
