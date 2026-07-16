<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Setting;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A school's own thresholds for the dashboard's "Attendance at risk" and
 * "Performance at risk" cards (user directive 2026-07-16: "add it to what
 * admin can configure threshold for under settings"). Stored as a tenant
 * Setting, config('skulos.at_risk_thresholds') is only ever the seed
 * default now, per the project's standing "nothing hardcoded" rule.
 */
class AtRiskThresholdController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request, string $permission): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), $permission),
            403,
            'You do not have permission for this.'
        );
    }

    public function edit(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'school_info.view');
        $school = $this->school($request);

        return view('school.settings-at-risk-thresholds', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'thresholds' => Setting::grab('at_risk_thresholds', config('skulos.at_risk_thresholds', ['attendance_pct' => 75, 'score_pct' => 40])),
        ]);
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');

        $data = $request->validate([
            'attendance_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'score_pct' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        Setting::put('at_risk_thresholds', [
            'attendance_pct' => $data['attendance_pct'],
            'score_pct' => $data['score_pct'],
        ]);

        return back()->with('status', 'At risk thresholds updated.');
    }
}
