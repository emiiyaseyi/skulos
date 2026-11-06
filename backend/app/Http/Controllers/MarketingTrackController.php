<?php

namespace App\Http\Controllers;

use App\Models\MarketingPageView;
use Illuminate\Http\Request;

/**
 * Records one marketing-site page view (2026-11-06). Called from a plain
 * <script> beacon on every static site/*.html page -- see
 * routes/api.php's own docblock for why this lives there (stateless, no
 * CSRF, the same reasoning already established for the contact form and
 * license check-in). Never authenticated, never returns anything a
 * caller could use to read someone else's data back.
 */
class MarketingTrackController extends Controller
{
    public function store(Request $request): \Illuminate\Http\Response
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:255'],
            'referrer' => ['nullable', 'string', 'max:500'],
            'utm_source' => ['nullable', 'string', 'max:100'],
            'utm_medium' => ['nullable', 'string', 'max:100'],
            'utm_campaign' => ['nullable', 'string', 'max:100'],
            'session_id' => ['required', 'string', 'max:64'],
        ]);

        $data['user_agent'] = substr((string) $request->userAgent(), 0, 255) ?: null;

        MarketingPageView::create($data);

        return response()->noContent();
    }
}
