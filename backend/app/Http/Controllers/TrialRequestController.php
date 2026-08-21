<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\TrialRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public "Start a Trial" form (user request 2026-07-09): a brief form the
 * marketing site links to, reviewed by a platform admin before anything
 * is created. No authentication needed to submit.
 */
class TrialRequestController extends Controller
{
    public function create(): \Illuminate\View\View
    {
        return view('trial.create', [
            'countries' => Country::orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
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
            // here; availability is re-checked at approval time, since
            // another school could claim the same one in between.
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
            // 2026-08-19: which features the prospect wants, from the
            // real feature catalog only -- Rule::in against every real
            // key rather than trusting whatever an unauthenticated
            // public form submits, since this list becomes the starting
            // point for a real school's actual access at approval time.
            // Only what is actually shown (2026-08-21, direct correction:
            // the checklist was listing the full aspirational catalog,
            // including tiers/categories with no real screen at all yet
            // -- see Plan::builtFeatureCatalog()). Validated against the
            // same builtFeatureKeys() the form itself was rendered from,
            // so a tampered submission can never claim something the UI
            // never actually offered.
            'requested_features' => ['nullable', 'array'],
            'requested_features.*' => ['string', \Illuminate\Validation\Rule::in(\App\Models\Plan::builtFeatureKeys())],
        ]);

        $data['preferred_slug'] = \Illuminate\Support\Str::slug($data['preferred_slug'], '');

        TrialRequest::create($data);

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
