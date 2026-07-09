<?php

namespace App\Http\Controllers;

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
        return view('trial.create');
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
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'student_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        TrialRequest::create($data);

        return redirect()->route('trial.thanks');
    }

    public function thanks(): \Illuminate\View\View
    {
        return view('trial.thanks');
    }
}
