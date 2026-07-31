<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AdmissionDocument;
use App\Models\Tenant\AdmissionFormSection;
use App\Services\Media\ImageProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Admissions module, Phase 1f (user directive 2026-07-31: "ought to have
 * other steps... filling candidate birth certificate, last report card
 * if available etc, all these are optional by default"). The final step
 * of the same application-form wizard (ApplicationFormController chains
 * into this once its own custom-field steps are done, or immediately if
 * a school has none), one file per config('skulos.admission_document_
 * types') entry, every one optional.
 */
class DocumentUploadController extends Controller
{
    /** Mirrors ApplicationFormController::steps()'s own count, so "Step X of Y" stays consistent across both controllers. */
    protected function totalCustomSteps(): int
    {
        return AdmissionFormSection::where('is_active', true)->distinct()->count('step');
    }

    public function show(Request $request): \Illuminate\View\View
    {
        $school = $request->attributes->get('school');
        $applicant = Auth::guard('applicant')->user();

        $existing = AdmissionDocument::where('applicant_id', $applicant->id)->get()->keyBy('document_type');
        $totalSteps = $this->totalCustomSteps() + 1;

        return view('admissions.documents', [
            'school' => $school,
            'accent' => $school?->branding()['color'] ?? null,
            'applicant' => $applicant,
            'documentTypes' => config('skulos.admission_document_types', []),
            'existing' => $existing,
            'stepIndex' => $totalSteps - 1,
            'totalSteps' => $totalSteps,
        ]);
    }

    public function store(Request $request, ImageProcessingService $images): \Illuminate\Http\RedirectResponse
    {
        $school = $request->attributes->get('school');
        $applicant = Auth::guard('applicant')->user();
        $types = array_keys(config('skulos.admission_document_types', []));

        $rules = [];
        foreach ($types as $type) {
            $rules[$type] = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.config('skulos.upload_limits.admission_document_kb')];
        }
        $data = $request->validate($rules);

        foreach ($types as $type) {
            $file = $request->file($type);
            if (! $file) {
                continue;
            }

            $existing = AdmissionDocument::where('applicant_id', $applicant->id)->where('document_type', $type)->first();
            // A verified document is settled; re-uploading is not offered
            // for it on the form (see the view), but guard the write path
            // too rather than trusting the UI alone.
            if ($existing && $existing->status === AdmissionDocument::STATUS_VERIFIED) {
                continue;
            }

            $path = $file->getClientOriginalExtension() === 'pdf'
                ? $file->storeAs("admissions/{$school->slug}/{$applicant->id}/documents", Str::random(24).'.pdf', 'local')
                : $images->toWebp($file, "admissions/{$school->slug}/{$applicant->id}/documents", disk: 'local');

            AdmissionDocument::updateOrCreate(
                ['applicant_id' => $applicant->id, 'document_type' => $type],
                ['file_path' => $path]
            );
            // updateOrCreate() only fills $fillable columns (file_path/
            // applicant_id/document_type), status/verified_* are never
            // touched by it — but a re-upload after a rejection should
            // return to pending for review, not silently stay "rejected".
            AdmissionDocument::where('applicant_id', $applicant->id)->where('document_type', $type)
                ->update(['status' => AdmissionDocument::STATUS_PENDING, 'verified_by_user_id' => null, 'verified_at' => null, 'notes' => null]);
        }

        return redirect()->route('school.admissions.dashboard', ['school' => $school->slug])
            ->with('status', 'Application complete. The school will review your documents shortly.');
    }
}
