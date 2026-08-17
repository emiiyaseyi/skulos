<?php

namespace App\Providers;

use App\Models\TrialRequest;
use App\Services\Mail\MailSettingsService;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
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

        // 2026-08-13, a real performance finding: Rbac::check() is
        // called dozens of times per page (nav-school.blade.php alone,
        // 16+ times) from many different call sites, each resolving its
        // own app(Rbac::class)/constructor-injected instance. Binding it
        // as a singleton means every one of those calls shares the same
        // instance within a request, so Rbac's own internal memoization
        // (permission/module/module_states/override lookups) actually
        // has something to hit — measured at 153 real SQL queries for
        // 16 nav permission checks before this fix. Safe the same way
        // TenantManager already is: Laravel boots a fresh container (and
        // so a fresh Rbac instance) per request/test, never reused
        // across requests the way a static property would be.
        $this->app->singleton(Rbac::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Laravel's built in pagination views assume Tailwind is loaded
        // (SKULOS is hand rolled CSS, no Tailwind anywhere), the default
        // "tailwind" view's SVG prev/next arrows have no width/height and
        // no Tailwind utility classes to size them, so they render at the
        // browser's SVG fallback size, filling most of the page (found
        // live 2026-07-12: "check this big arrows on the page, fix").
        // Every ->links() call in the app uses this one custom view now.
        Paginator::defaultView('vendor.pagination.custom');
        Paginator::defaultSimpleView('vendor.pagination.custom');

        // Every URL this app generates (signed links especially -- the
        // "sign in as this admin" handoff, applicant status links) is
        // built https:// in production, unconditionally (2026-08-16).
        //
        // bootstrap/app.php's trustProxies(at: '*') already tells
        // Laravel to believe the X-Forwarded-Proto header the host's
        // proxy sends, which is the textbook fix for this -- but it
        // depends on that specific host actually sending that specific
        // header, which is not something this app controls or can
        // verify from the code alone. This line removes that dependency
        // entirely for URL generation specifically: production only
        // ever serves over HTTPS (the wildcard certificate this whole
        // platform relies on), so there is no real scenario where
        // forcing https here generates a wrong link, and it fixes the
        // signed-link failure even if the proxy trust fix alone turns
        // out not to be enough on this particular host.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Register the Microsoft (Outlook) Socialite provider. Google is
        // supported by Socialite natively.
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('microsoft', \SocialiteProviders\Microsoft\Provider::class);
        });

        // Record every outgoing email (2026-08-16). Hooked to the
        // framework's own mail events rather than to each Mailable, so
        // any email added later is logged automatically instead of
        // depending on someone remembering to add a line for it.
        Event::listen(\Illuminate\Mail\Events\MessageSending::class, [\App\Listeners\RecordSentMail::class, 'sending']);
        Event::listen(\Illuminate\Mail\Events\MessageSent::class, [\App\Listeners\RecordSentMail::class, 'sent']);

        // A new trial request is easy to miss otherwise (user request
        // 2026-07-09: an approved lead went unnoticed) — badge the nav.
        View::composer('layouts.partials.nav-admin', function ($view) {
            $view->with('pendingTrialCount', TrialRequest::pending()->count());
            $view->with('pendingSubscriptionPayments', \App\Models\SubscriptionPayment::where('status', 'pending')->count());
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
