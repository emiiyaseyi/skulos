<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\TrialRequest;
use App\Models\User;
use App\Services\Schools\TrialProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public "Start a Trial" form. Since 2026-11-06 (direct instruction:
 * "instant access for setup with manual review from the backend"), a
 * valid submission provisions the real school and emails sign in details
 * immediately -- it no longer waits on a platform admin to approve it
 * first. The admin review queue still exists (TrialRequestAdminController)
 * as an after-the-fact audit/intervention point, not a gate: this was a
 * deliberate reversal of the original "reviewed before anything is
 * created" design, made after live signups stalled on this exact gap
 * ("free trial" reading as a lead form with an unknown wait, not real
 * access). No authentication needed to submit.
 */
class TrialRequestController extends Controller
{
    public function create(): \Illuminate\View\View
    {
        return view('trial.create', [
            'countries' => Country::orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request, TrialProvisioningService $provisioning): \Illuminate\Http\RedirectResponse
    {
        // Honeypot: a real visitor never fills this hidden field in.
        if ($request->filled('website')) {
            return redirect()->route('trial.thanks');
        }

        $key = 'trial-request|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->withInput()->with('error', 'Please wait a few minutes before submitting again.');
        }
        RateLimiter::hit($key, 3600);

        $data = $request->validate([
            'school_name' => ['required', 'string', 'max:150'],
            // 2026-08-19, made mandatory (direct instruction): a school
            // no longer leaves this to be assigned later. Shape only
            // here; availability is re-checked at provisioning time,
            // since another school could claim the same one in between.
            'preferred_slug' => ['required', 'string', 'max:60', 'regex:/^[a-zA-Z0-9]+$/'],
            // 2026-08-19: a real country, from the same reference table
            // every other country picker in the app already uses, not a
            // free-text field that could hold anything.
            'country' => ['required', 'string', 'exists:countries,name'],
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'student_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['preferred_slug'] = \Illuminate\Support\Str::slug($data['preferred_slug'], '');

        $trial = TrialRequest::create($data);

        // Security: an email that already has a real login (its own
        // school, or a role on someone else's) does not get a second one
        // auto-created silently -- left pending for a human to look at,
        // rather than risking a surprise duplicate account for someone
        // who may already be using the platform.
        $alreadyHasAccount = User::where('email', \Illuminate\Support\Str::lower($data['email']))->exists();

        // Security: a platform wide circuit breaker, separate from the
        // per IP limit above. Instant provisioning is now genuinely
        // expensive (a real tenant database and its migrations, not just
        // a row), which an IP based limit alone does not bound -- that is
        // trivially bypassed by spreading submissions across many IPs
        // (a botnet, rotating proxies). Past this many real provisions in
        // an hour, every further submission falls back to the same
        // pending-for-manual-review path a duplicate email already takes,
        // rather than erroring out or refusing the submission outright.
        $globalKey = 'trial-instant-provision|global';
        $circuitOpen = RateLimiter::tooManyAttempts($globalKey, 20);

        if ($alreadyHasAccount || $circuitOpen) {
            return redirect()->route('trial.thanks');
        }

        try {
            RateLimiter::hit($globalKey, 3600);
            $provisioning->provision($trial);
        } catch (\Throwable $e) {
            // Provisioning does real I/O (a new tenant database, its
            // migrations, a mail send) -- a transient failure here must
            // never surface as a broken public page. The request stays
            // pending (no school_id was ever set on a throw mid-way) for
            // an admin to retry via the existing manual "Approve" action.
            Log::error('Instant trial provisioning failed, left pending for manual retry.', [
                'trial_request_id' => $trial->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Security: the thank-you page's wording never reveals which of
        // the above branches was taken. A page that said "You're in" only
        // on success and "we'll review it" otherwise would let anyone
        // enumerate which email addresses already have a SKULOS account,
        // just by submitting this public, unauthenticated form with a
        // guessed address and reading back which message appeared.
        return redirect()->route('trial.thanks');
    }

    /**
     * Live "is this subdomain free" check for the trial form. Public on
     * purpose: it only ever reveals whether a subdomain is taken, which
     * is already observable by visiting it, and the form is unusable
     * without it. Throttled at the route.
     */
    public function checkSlug(Request $request): \Illuminate\Http\JsonResponse
    {
        $raw = (string) $request->query('slug', '');
        $slug = \Illuminate\Support\Str::slug($raw, '');

        if ($slug === '') {
            return response()->json(['ok' => false, 'reason' => 'Enter a name using letters and numbers only.']);
        }

        $taken = \App\Models\School::where('slug', $slug)->exists()
            || TrialRequest::where('preferred_slug', $slug)->where('status', TrialRequest::STATUS_PENDING)->exists();

        return response()->json([
            'ok' => ! $taken,
            'slug' => $slug,
            'url' => $slug.'.'.config('app.domain'),
            'suggestions' => $taken ? [$slug.'school', $slug.'academy', $slug.date('y')] : [],
            'reason' => $taken ? 'That address is already taken.' : null,
        ]);
    }

    public function thanks(): \Illuminate\View\View
    {
        return view('trial.thanks');
    }
}
