<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\AssessmentWindow;
use App\Models\Tenant\AssessmentWindowExtension;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Term;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Assessment score-entry windows and their extension requests (user
 * request 2026-07-18: "only admin can set this, it can grant extension
 * which the teacher will have to request within the window period...
 * the admin can review, adjust their window, reject or approve"). Setting
 * a window itself is gated the same as Assessment Structure
 * (examinations.approve_results, an institution-wide policy decision);
 * requesting an extension only needs the same permission entering scores
 * itself needs (examinations.create).
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class AssessmentWindowController extends Controller
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

    /** Admin sets (or clears) one component's window for one term, from the Assessment Structure screen. */
    public function updateWindow(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $school = $this->school($request);

        $data = $request->validate([
            'assessment_component_id' => ['required', 'exists:tenant.assessment_components,id'],
            'term_id' => ['required', 'exists:tenant.terms,id'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after_or_equal:opens_at'],
        ]);

        AssessmentWindow::updateOrCreate(
            ['assessment_component_id' => $data['assessment_component_id'], 'term_id' => $data['term_id']],
            ['opens_at' => $data['opens_at'] ?? null, 'closes_at' => $data['closes_at'] ?? null]
        );

        return redirect()->route('school.examinations.structure', ['school' => $school->slug, 'term_id' => $data['term_id']])
            ->with('status', 'Score entry window saved.');
    }

    /**
     * A teacher's own request for more time on a component that is
     * currently closed to them. The window must already exist and
     * already be closed, there is nothing to "extend" on a component that
     * was never restricted or is still open.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.create');
        $school = $this->school($request);
        $user = Auth::user();

        $data = $request->validate([
            'assessment_component_id' => ['required', 'exists:tenant.assessment_components,id'],
            'term_id' => ['required', 'exists:tenant.terms,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'requested_close_at' => ['required', 'date', 'after:now'],
        ]);

        $staff = Staff::where('user_id', $user->id)->first();
        abort_unless($staff, 403, 'Only a staff member can request a score entry extension.');

        $window = AssessmentWindow::where('assessment_component_id', $data['assessment_component_id'])
            ->where('term_id', $data['term_id'])->first();
        abort_unless($window, 404, 'This component has no entry window set, there is nothing to request an extension on.');

        AssessmentWindowExtension::create([
            'assessment_window_id' => $window->id,
            'staff_id' => $staff->id,
            'reason' => $data['reason'],
            'requested_close_at' => $data['requested_close_at'],
        ]);

        return back()->with('status', 'Your extension request has been sent to the admin for review.');
    }

    /**
     * Admin review queue (user directive 2026-07-18: "the admin can
     * review, adjust their window, reject or approve"). Every request,
     * not only pending, so an admin can see the outcome of past ones too;
     * the bulk actions below only ever apply to pending rows.
     */
    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $school = $this->school($request);

        $requests = AssessmentWindowExtension::with(['staff', 'window.component', 'window.term.session'])
            ->orderByRaw("status = 'pending' desc")
            ->orderByDesc('created_at')
            ->get();

        return view('school.examinations.window-extensions', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'requests' => $requests,
        ]);
    }

    /**
     * Approve, optionally adjusting the requested close time first
     * ("adjust their window"): once approved, requested_close_at is what
     * AssessmentWindowService actually checks against for this teacher.
     */
    public function approve(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $school = $this->school($request);
        $extension = AssessmentWindowExtension::findOrFail($request->route('extensionId'));

        $data = $request->validate([
            'requested_close_at' => ['nullable', 'date'],
            'admin_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $extension->forceFill([
            'requested_close_at' => $data['requested_close_at'] ?? $extension->requested_close_at,
            'status' => AssessmentWindowExtension::STATUS_APPROVED,
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
            'admin_note' => $data['admin_note'] ?? null,
        ])->save();

        return redirect()->route('school.examinations.window-extensions.index', ['school' => $school->slug])
            ->with('status', "Extension approved for {$extension->staff?->fullName()}.");
    }

    /** Reject: the window's own default opens_at/closes_at keeps applying to this teacher, nothing else changes. */
    public function reject(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $school = $this->school($request);
        $extension = AssessmentWindowExtension::findOrFail($request->route('extensionId'));

        $data = $request->validate(['admin_note' => ['nullable', 'string', 'max:1000']]);

        $extension->forceFill([
            'status' => AssessmentWindowExtension::STATUS_REJECTED,
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
            'admin_note' => $data['admin_note'] ?? null,
        ])->save();

        return redirect()->route('school.examinations.window-extensions.index', ['school' => $school->slug])
            ->with('status', "Extension rejected for {$extension->staff?->fullName()}. The default window applies to them.");
    }

    /** Bulk approve/reject ticked pending requests, matching the standing bulk-actions convention every list screen follows. */
    public function bulkAction(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $school = $this->school($request);

        $data = $request->validate([
            'extension_ids' => ['required', 'array', 'min:1'],
            'extension_ids.*' => ['exists:tenant.assessment_window_extensions,id'],
            'action' => ['required', Rule::in(['approve', 'reject'])],
        ]);

        $status = $data['action'] === 'approve' ? AssessmentWindowExtension::STATUS_APPROVED : AssessmentWindowExtension::STATUS_REJECTED;

        $count = AssessmentWindowExtension::whereIn('id', $data['extension_ids'])
            ->where('status', AssessmentWindowExtension::STATUS_PENDING)
            ->update(['status' => $status, 'reviewed_by_user_id' => Auth::id(), 'reviewed_at' => now()]);

        return redirect()->route('school.examinations.window-extensions.index', ['school' => $school->slug])
            ->with('status', "{$count} request(s) {$data['action']}d.");
    }
}
