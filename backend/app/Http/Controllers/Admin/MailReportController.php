<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailLog;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * How much mail the platform has sent, broken down per school and per
 * audience (2026-08-19, direct instruction: "let's add a report of the
 * email sent by the platform, how many sent so far, then another to
 * see for each schools, how many each school has sent in a particular
 * day, date, year, till date, creation till date, date range... who
 * the mails are sent to - parents, students, staff, applicants...
 * click on each school name to see the further details").
 *
 * Deliberately a separate screen from the existing Admin\MailLogController
 * (Sent Mail): that one browses individual message rows one at a time,
 * this one answers "how much" and "to whom", which is a genuinely
 * different question and would only clutter a row-by-row log if bolted
 * on there.
 */
class MailReportController extends Controller
{
    public function index(Request $request): \Illuminate\View\View
    {
        [$from, $to, $range] = $this->resolveRange($request);

        $inRange = fn () => MailLog::query()->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        // Per-school counts, one query per audience rather than N+1 per
        // school: a school list can run into the hundreds, and this
        // stays 5 queries total regardless of how many schools there are.
        $totalsBySchool = (clone $inRange())->selectRaw('school_id, count(*) as total')
            ->whereNotNull('school_id')->groupBy('school_id')->pluck('total', 'school_id');

        $audienceBySchool = [];
        foreach (MailLog::AUDIENCES as $key => $label) {
            $audienceBySchool[$key] = (clone $inRange())->selectRaw('school_id, count(*) as total')
                ->whereNotNull('school_id')->where('audience', $key)->groupBy('school_id')->pluck('total', 'school_id');
        }

        $schools = School::orderBy('name')->get(['id', 'name', 'slug', 'created_at'])
            ->map(function (School $school) use ($totalsBySchool, $audienceBySchool) {
                // Explicitly cast: a raw selectRaw('count(*)') value's
                // PHP type is driver-dependent (some return it as a
                // string), and this feeds both display and sorting.
                $school->setAttribute('mail_total', (int) ($totalsBySchool[$school->id] ?? 0));
                foreach (MailLog::AUDIENCES as $key => $label) {
                    $school->setAttribute('mail_'.$key, (int) ($audienceBySchool[$key][$school->id] ?? 0));
                }

                return $school;
            })
            // Busiest schools first, so the report reads useful at a
            // glance rather than in arbitrary alphabetical order.
            ->sortByDesc('mail_total')->values();

        return view('admin.mail-report.index', [
            'platformTotal' => MailLog::count(),
            'rangeTotal' => (clone $inRange())->count(),
            'noSchoolTotal' => (clone $inRange())->whereNull('school_id')->count(),
            'schools' => $schools,
            'audiences' => MailLog::AUDIENCES,
            'range' => $range,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
        ]);
    }

    public function show(Request $request, School $school): \Illuminate\View\View
    {
        [$from, $to, $range] = $this->resolveRange($request, $school);

        $inRange = fn () => MailLog::query()->where('school_id', $school->id)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        $byAudience = collect(MailLog::AUDIENCES)->mapWithKeys(
            fn ($label, $key) => [$key => (clone $inRange())->where('audience', $key)->count()]
        );
        $unclassified = (clone $inRange())->whereNull('audience')->count();

        // One row per real sending day, most recent first -- exactly
        // the "how many each school has sent in a particular day, date"
        // ask, without inventing a fixed calendar grid full of empty days.
        $byDay = (clone $inRange())
            ->selectRaw("date(created_at) as day, count(*) as total")
            ->groupBy('day')->orderByDesc('day')->get();

        return view('admin.mail-report.show', [
            'school' => $school,
            'total' => (clone $inRange())->count(),
            'byAudience' => $byAudience,
            'unclassified' => $unclassified,
            'byDay' => $byDay,
            'audiences' => MailLog::AUDIENCES,
            'range' => $range,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
        ]);
    }

    /**
     * A handful of named presets plus an explicit from/to date range,
     * covering the literal list asked for ("a particular day, date,
     * year, till date, creation till date, date range"). 'created' only
     * makes sense with a real $school in hand (a platform-wide "since
     * each school's own creation" has no single date to filter by), so
     * it silently falls back to all-time on the cross-school report.
     *
     * @return array{0: ?Carbon, 1: ?Carbon, 2: string}
     */
    protected function resolveRange(Request $request, ?School $school = null): array
    {
        $range = (string) $request->query('range', 'all');
        $now = Carbon::now();

        return match ($range) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), $range],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay(), $range],
            'created' => $school
                ? [$school->created_at, $now->copy()->endOfDay(), $range]
                : [null, null, 'all'],
            'custom' => [
                $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : null,
                $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : null,
                $range,
            ],
            default => [null, null, 'all'],
        };
    }
}
