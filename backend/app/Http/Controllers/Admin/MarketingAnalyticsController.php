<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingPageView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Who's coming to the platform, how long they spent" inside SKULOS's
 * own admin backend, not an external GA4/Clarity dashboard (direct
 * instruction, 2026-11-06). Reads MarketingPageView, written by the
 * first-party beacon every static site/*.html page calls on load.
 *
 * "Average session span" here is an honest approximation
 * (MAX(created_at) - MIN(created_at) per session_id), not a precise
 * time-on-page figure -- a one-page visit has nothing to measure a span
 * against by construction, so those are reported separately as the
 * single-page share rather than silently dragging the average toward
 * zero.
 */
class MarketingAnalyticsController extends Controller
{
    public function index(Request $request): \Illuminate\View\View
    {
        $days = (int) $request->query('days', 30);
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $since = now()->subDays($days);

        $base = MarketingPageView::where('created_at', '>=', $since);

        $totalViews = (clone $base)->count();
        $uniqueSessions = (clone $base)->distinct('session_id')->count('session_id');

        $sessionSpans = (clone $base)
            ->select('session_id')
            ->selectRaw('MIN(created_at) as first_seen, MAX(created_at) as last_seen, COUNT(*) as views')
            ->groupBy('session_id')
            ->get();

        $multiView = $sessionSpans->where('views', '>', 1);
        $avgSpanSeconds = $multiView->isNotEmpty()
            ? (int) $multiView->avg(fn ($s) => \Carbon\Carbon::parse($s->last_seen)->diffInSeconds(\Carbon\Carbon::parse($s->first_seen)))
            : null;
        $singlePageShare = $sessionSpans->isNotEmpty()
            ? round($sessionSpans->where('views', 1)->count() / $sessionSpans->count() * 100)
            : 0;

        $topPages = (clone $base)
            ->select('path')->selectRaw('COUNT(*) as views')
            ->groupBy('path')->orderByDesc('views')->limit(10)->get();

        // "Which campaign/link is actually working" -- the direct answer
        // to "use it to run targeted campaign": a link shared with a
        // ?utm_campaign= tag (e.g. the WhatsApp group shares) groups by
        // that name; anything else groups by where it was referred from,
        // falling back to Direct/Unknown (a typed URL, a bookmark, or a
        // link-preview crawler that never sent a referrer at all).
        $driver = DB::connection()->getDriverName();
        $hostExpr = $driver === 'sqlite'
            ? "trim(replace(replace(substr(referrer, 1, instr(referrer || '/', '/') - 1), 'https://', ''), 'http://', ''))"
            : "TRIM(REPLACE(REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '://', -1), '/', 1), 'https://', ''), 'http://', ''))";

        $sources = (clone $base)
            ->selectRaw("COALESCE(utm_campaign, CASE WHEN referrer IS NULL OR referrer = '' THEN 'Direct / Unknown' ELSE {$hostExpr} END) as source")
            ->selectRaw('COUNT(*) as views')
            ->selectRaw('COUNT(DISTINCT session_id) as sessions')
            ->groupBy('source')->orderByDesc('views')->limit(15)->get();

        return view('admin.visitors', [
            'days' => $days,
            'totalViews' => $totalViews,
            'uniqueSessions' => $uniqueSessions,
            'avgSpanSeconds' => $avgSpanSeconds,
            'singlePageShare' => $singlePageShare,
            'topPages' => $topPages,
            'sources' => $sources,
            'hasAnyData' => MarketingPageView::query()->exists(),
        ]);
    }
}
