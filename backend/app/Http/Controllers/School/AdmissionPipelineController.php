<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Mail\AdmissionCredentialsMail;
use App\Models\School;
use App\Models\Tenant\AdmissionPayment;
use App\Models\Tenant\Applicant;
use App\Models\Tenant\ClassArm;
use App\Services\Admissions\ApplicantConversionService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Admissions module, Phase 1g: the applicant status board (user
 * directive 2026-07-31: "showing all current applicants, those
 * undergoing admission process and yet to complete, those completed,
 * those given admission... admin can trigger reminder"), the admission
 * decision (offer/reject/waitlist), and converting an offered, fee-paid
 * applicant into a real enrolled student.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class AdmissionPipelineController extends Controller
{
    /** Every status tab this board offers, in display order. */
    public const TABS = ['in_progress', 'offered', 'waitlisted', 'rejected', 'enrolled'];

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

    /**
     * Whether this applicant's own admission window actually charges an
     * acceptance fee (live feedback 2026-07-31: "Acceptance fee is not
     * mandatory as some schools dont use it") — if not, conversion never
     * waits on a payment that was never going to exist.
     */
    protected function requiresAcceptanceFee(Applicant $applicant): bool
    {
        $window = \App\Models\Tenant\AdmissionWindow::where('session_id', $applicant->session_id)
            ->where('school_class_id', $applicant->school_class_id)
            ->where('campus_id', $applicant->campus_id)
            ->first();

        return $window?->requiresAcceptanceFee() ?? false;
    }

    protected function scopeTab($query, string $tab)
    {
        return match ($tab) {
            'offered' => $query->where('decision_status', Applicant::DECISION_OFFERED),
            'waitlisted' => $query->where('decision_status', Applicant::DECISION_WAITLISTED),
            'rejected' => $query->where('decision_status', Applicant::DECISION_REJECTED),
            'enrolled' => $query->where('decision_status', Applicant::DECISION_CONVERTED),
            // "in progress": no decision yet, still moving through the
            // pipeline (includes an applicant still on pending_fee,
            // never yet activated, as well as an already-active one).
            default => $query->whereNull('decision_status'),
        };
    }

    public function board(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'admissions.decide');
        $school = $this->school($request);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'in_progress';

        $applicants = $this->scopeTab(
            Applicant::with(['schoolClass', 'convertedStudent'])->orderByDesc('created_at'),
            $tab
        )->paginate(30)->withQueryString();

        $counts = collect(self::TABS)->mapWithKeys(fn ($t) => [$t => $this->scopeTab(Applicant::query(), $t)->count()]);

        return view('school.admissions.pipeline', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'applicants' => $applicants,
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $counts,
            'pipelineChain' => Applicant::pipelineChain(),
            'stageCatalog' => config('skulos.admission_pipeline_stages', []),
        ]);
    }

    public function advance(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.decide');
        $applicant = Applicant::findOrFail($request->route('applicantId'));
        $applicant->advanceStage();

        return back()->with('status', "{$applicant->name} moved to the next stage.");
    }

    protected function decide(Request $request, string $decision): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.decide');
        $applicant = Applicant::findOrFail($request->route('applicantId'));
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        $applicant->forceFill([
            'decision_status' => $decision,
            'decided_by_user_id' => Auth::id(),
            'decided_at' => now(),
            'decision_notes' => $data['notes'] ?? null,
        ])->save();

        $verb = match ($decision) {
            Applicant::DECISION_OFFERED => 'offered admission',
            Applicant::DECISION_WAITLISTED => 'waitlisted',
            default => 'rejected',
        };

        return back()->with('status', "{$applicant->name} {$verb}.");
    }

    public function offer(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->decide($request, Applicant::DECISION_OFFERED);
    }

    public function waitlist(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->decide($request, Applicant::DECISION_WAITLISTED);
    }

    public function reject(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->decide($request, Applicant::DECISION_REJECTED);
    }

    /**
     * A plain status-appropriate email nudge (user directive 2026-07-31:
     * "admin can trigger reminder to those who are yet to complete...
     * that they can either continue, make payment, or move to the next
     * stage"), reusing the same applicant-login-link shape
     * AdmissionCredentialsMail already sends, not a new template per
     * status.
     */
    public function nudge(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.decide');
        $school = $this->school($request);
        $applicant = Applicant::findOrFail($request->route('applicantId'));

        if (! $applicant->email) {
            return back()->with('error', "{$applicant->name} has no email on file to send a reminder to.");
        }

        $message = match (true) {
            $applicant->account_status === Applicant::STATUS_PENDING_FEE => 'complete your application fee payment',
            $applicant->decision_status === Applicant::DECISION_OFFERED => 'complete your acceptance fee payment to secure your place',
            default => 'continue your application',
        };

        Mail::to($applicant->email)->send(new \App\Mail\AdmissionReminderMail($applicant, $school, $message));

        return back()->with('status', "Reminder sent to {$applicant->name}.");
    }

    public function convertForm(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'admissions.decide');
        $school = $this->school($request);
        $applicant = Applicant::with('schoolClass')->findOrFail($request->route('applicantId'));

        abort_unless($applicant->decision_status === Applicant::DECISION_OFFERED, 422, 'This applicant has not been offered admission.');
        $acceptanceVerified = ! $this->requiresAcceptanceFee($applicant) || AdmissionPayment::where('applicant_id', $applicant->id)
            ->where('fee_type', AdmissionPayment::TYPE_ACCEPTANCE)
            ->where('status', AdmissionPayment::STATUS_VERIFIED)
            ->exists();

        return view('school.admissions.convert', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'applicant' => $applicant,
            'acceptanceVerified' => $acceptanceVerified,
            'classArms' => $applicant->school_class_id
                ? ClassArm::with('arm')->where('school_class_id', $applicant->school_class_id)->get()
                : collect(),
        ]);
    }

    public function convert(Request $request, ApplicantConversionService $conversion): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.decide');
        $school = $this->school($request);
        $applicant = Applicant::findOrFail($request->route('applicantId'));

        abort_unless($applicant->decision_status === Applicant::DECISION_OFFERED, 422, 'This applicant has not been offered admission.');
        $acceptanceVerified = ! $this->requiresAcceptanceFee($applicant) || AdmissionPayment::where('applicant_id', $applicant->id)
            ->where('fee_type', AdmissionPayment::TYPE_ACCEPTANCE)
            ->where('status', AdmissionPayment::STATUS_VERIFIED)
            ->exists();
        abort_unless($acceptanceVerified, 422, 'The acceptance fee has not been verified yet.');

        $data = $request->validate([
            'admission_number' => ['required', 'string', 'max:40', Rule::unique('tenant.students', 'admission_number')],
            'class_arm_id' => ['required', 'exists:tenant.class_arms,id'],
        ]);

        $student = $conversion->convert($school, $applicant, $data['admission_number'], (int) $data['class_arm_id']);

        return redirect()->route('school.admissions.pipeline', ['school' => $school->slug, 'tab' => 'enrolled'])
            ->with('status', "{$student->fullName()} enrolled, admission number {$student->admission_number}.");
    }
}
