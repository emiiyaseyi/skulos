<?php

namespace App\Http\Controllers;

use App\Mail\ContactFormMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public marketing-site Contact form (2026-08-14), reachable via
 * routes/api.php (stateless, no CSRF) since the form itself lives on a
 * plain static HTML page with no Blade templating to embed a CSRF
 * token into — the same reasoning routes/api.php's own docblock
 * already documents for the license endpoints. Same honeypot +
 * rate-limit shape as TrialRequestController.
 */
class ContactFormController extends Controller
{
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        // Honeypot: a real visitor never fills this hidden field in.
        if ($request->filled('website')) {
            return redirect('/contact.html?sent=1');
        }

        $key = 'contact-form|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return redirect('/contact.html?error=1');
        }
        RateLimiter::hit($key, 3600);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'school' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        Mail::to(config('skulos.support.email'))->send(new ContactFormMail(
            $data['name'],
            $data['school'],
            $data['email'],
            $data['message'] ?? null,
        ));

        return redirect('/contact.html?sent=1');
    }
}
