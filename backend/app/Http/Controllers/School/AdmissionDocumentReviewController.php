<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AdmissionDocument;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Admissions module, Phase 1f: admin reviews an applicant's uploaded
 * supporting documents (birth certificate, last report card, etc). Same
 * atomic decide() shape AdmissionFeeReviewController/PaymentController
 * already established, no side effect beyond the status flip itself —
 * unlike fee verification, a verified document doesn't unlock anything
 * else in the pipeline on its own.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class AdmissionDocumentReviewController extends Controller
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

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'admissions.review_documents');
        $school = $this->school($request);

        $documents = AdmissionDocument::with('applicant')
            ->orderByRaw("status = 'pending' desc")
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('school.admissions.document-review', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'documents' => $documents,
            'documentTypes' => config('skulos.admission_document_types', []),
        ]);
    }

    public function file(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeAction($request, 'admissions.review_documents');
        $document = AdmissionDocument::findOrFail($request->route('documentId'));

        return Storage::disk('local')->response($document->file_path);
    }

    public function verify(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->decide($request, AdmissionDocument::STATUS_VERIFIED);
    }

    public function reject(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->decide($request, AdmissionDocument::STATUS_REJECTED);
    }

    protected function decide(Request $request, string $status): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.review_documents');
        $document = AdmissionDocument::with('applicant')->findOrFail($request->route('documentId'));

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        $updated = AdmissionDocument::where('id', $document->id)
            ->where('status', AdmissionDocument::STATUS_PENDING)
            ->update([
                'status' => $status,
                'verified_by_user_id' => Auth::id(),
                'verified_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

        if ($updated === 0) {
            return back()->with('error', 'This document has already been processed.');
        }

        $label = config('skulos.admission_document_types.'.$document->document_type, $document->document_type);
        $verb = $status === AdmissionDocument::STATUS_VERIFIED ? 'verified' : 'rejected';

        return back()->with('status', "{$label} for {$document->applicant->name} marked as {$verb}.");
    }
}
