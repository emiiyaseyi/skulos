<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtFaceApproval;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * SKULOS Assessment Hub, Phase 2e: staff review queue for a live face
 * match that couldn't reach the 50% threshold after 3 client-side
 * attempts. Gated on assessment_hub.create (the same admin tier as the
 * question bank/paper builder), not .view — this screen shows a
 * taker's uploaded ID and live capture side by side.
 */
class CbtFaceApprovalController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), 'assessment_hub.create'),
            403,
            'You do not have permission for this.'
        );
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        $approvals = CbtFaceApproval::with('precheckSession.assessment', 'precheckSession.takeable')
            ->where('status', CbtFaceApproval::STATUS_PENDING)
            ->orderByDesc('id')
            ->get();

        return view('school.cbt.face-approvals.index', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'approvals' => $approvals,
        ]);
    }

    public function decide(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $approval = CbtFaceApproval::findOrFail($request->route('approvalId'));

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = CbtFaceApproval::where('id', $approval->id)->where('status', CbtFaceApproval::STATUS_PENDING)->update([
            'status' => $data['decision'] === 'approve' ? CbtFaceApproval::STATUS_APPROVED : CbtFaceApproval::STATUS_REJECTED,
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);
        abort_if($updated === 0, 422, 'This request has already been decided.');

        return redirect()->route('school.cbt.face-approvals.index', ['school' => $school->slug])
            ->with('status', $data['decision'] === 'approve' ? 'Approved. The taker can now continue.' : 'Rejected.');
    }

    public function photoIdImage(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeAction($request);
        $approval = CbtFaceApproval::with('precheckSession')->findOrFail($request->route('approvalId'));
        abort_unless($approval->precheckSession?->photo_id_path, 404);

        return Storage::disk('local')->response($approval->precheckSession->photo_id_path);
    }

    public function faceImage(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeAction($request);
        $approval = CbtFaceApproval::with('precheckSession')->findOrFail($request->route('approvalId'));
        abort_unless($approval->precheckSession?->face_capture_path, 404);

        return Storage::disk('local')->response($approval->precheckSession->face_capture_path);
    }
}
