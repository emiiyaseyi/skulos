<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TrialRequestStatusMail;
use App\Models\TrialRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Platform admin reviews "Start a Trial" leads (user request 2026-07-09):
 * approve or reject, with a note that goes to the applicant by email
 * (setup status, demo, and training arrangements on approval).
 */
class TrialRequestAdminController extends Controller
{
    public function index(Request $request): \Illuminate\View\View
    {
        $status = $request->query('status', 'pending');

        $trials = TrialRequest::query()
            ->when(in_array($status, ['pending', 'approved', 'rejected'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->get();

        return view('admin.trial-requests', [
            'trials' => $trials,
            'status' => $status,
            'counts' => [
                'pending' => TrialRequest::pending()->count(),
                'approved' => TrialRequest::where('status', TrialRequest::STATUS_APPROVED)->count(),
                'rejected' => TrialRequest::where('status', TrialRequest::STATUS_REJECTED)->count(),
            ],
        ]);
    }

    public function approve(Request $request, TrialRequest $trialRequest): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:2000']]);

        $trialRequest->update([
            'status' => TrialRequest::STATUS_APPROVED,
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        Mail::to($trialRequest->email)->send(new TrialRequestStatusMail($trialRequest));

        return back()->with('status', "{$trialRequest->school_name} approved. {$trialRequest->contact_name} has been emailed.");
    }

    public function reject(Request $request, TrialRequest $trialRequest): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:2000']]);

        $trialRequest->update([
            'status' => TrialRequest::STATUS_REJECTED,
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        Mail::to($trialRequest->email)->send(new TrialRequestStatusMail($trialRequest));

        return back()->with('status', "{$trialRequest->school_name} declined. {$trialRequest->contact_name} has been emailed.");
    }
}
