<?php

namespace App\Providers;

use App\Models\TrialRequest;
use App\Services\Mail\MailSettingsService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register the Microsoft (Outlook) Socialite provider. Google is
        // supported by Socialite natively.
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('microsoft', \SocialiteProviders\Microsoft\Provider::class);
        });

        // A new trial request is easy to miss otherwise (user request
        // 2026-07-09: an approved lead went unnoticed) — badge the nav.
        View::composer('layouts.partials.nav-admin', function ($view) {
            $view->with('pendingTrialCount', TrialRequest::pending()->count());
        });

        // Admin configured SMTP (user request 2026-07-09), applied on top
        // of .env before any mail is sent. Skipped for console commands
        // (migrations etc. run before the settings table can exist) and
        // guarded because this runs on every web request.
        if (! $this->app->runningInConsole()) {
            try {
                app(MailSettingsService::class)->applyPlatformDefaults();
            } catch (\Throwable $e) {
                // platform_settings not migrated yet, or DB unreachable —
                // fall back to whatever .env already configured.
            }
        }
    }
}
