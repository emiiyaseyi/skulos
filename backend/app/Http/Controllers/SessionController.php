<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Backs the idle timeout warning modal (user request 2026-07-09, security):
 * clicking "Resume" on the warning hits this so the session's activity
 * timestamp is touched (any authenticated request does that automatically)
 * without navigating away from the page the user was on.
 */
class SessionController extends Controller
{
    public function keepAlive(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
