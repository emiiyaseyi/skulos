<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Mail\AdmissionCredentialsMail;
use App\Mail\AdmissionMessageMail;
use App\Models\School;
use App\Models\Tenant\AdmissionMessage;
use App\Models\Tenant\AdmissionPayment;
use App\Models\Tenant\Applicant;
use App\Models\Tenant\ClassArm;
use App\Models\User;
use App\Services\Admissions\AdmissionOfferService;
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

    public function board(Request $request, \App\Services\Cbt\ScoreReportingService $reporting): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'admissions.decide');
        $school = $this->school($request);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'in_progress';

        $applicants = $this->scopeTab(
            Applicant::with(['schoolClass', 'convertedStudent'])->orderByDesc('created_at'),
            $tab
        )->paginate(30)->withQueryString();

        $counts = collect(self::TABS)->mapWithKeys(fn ($t) => [$t => $this->scopeTab(Applicant::query(), $t)->count()]);

        // Staff reviewing an applicant used to have to leave this board
        // and check the Score Matrix separately just to see how they did
        // on an admission assessment (user directive 2026-08-02). One
        // query for the page's own 30 rows, not per-row, keeping the
        // MOST RECENT graded/submitted attempt only — good enough for a
        // glance column, the full history is still on the Score Matrix.
        $applicantIds = $applicants->pluck('id');
        $assessmentResults = \App\Models\Tenant\CbtSession::with('assessment')
            ->where('takeable_type', Applicant::class)
            ->whereIn('takeable_id', $applicantIds)
            ->whereIn('status', [
                \App\Models\Tenant\CbtSession::STATUS_SUBMITTED,
                \App\Models\Tenant\CbtSession::STATUS_AUTO_SUBMITTED,
                \App\Models\Tenant\CbtSession::STATUS_GRADED,
            ])
            ->orderByDesc('id')
            ->get()
            ->groupBy('takeable_id')
            ->map(function ($sessions) use ($reporting) {
                $latest = $sessions->first();
                $score = $latest->total_score !== null ? (float) $latest->total_score : null;

                return [
                    'assessment' => $latest->assessment,
                    'status' => $latest->status,
                    'score' => $score,
                    'maxScore' => $latest->max_score,
                    'passed' => $latest->status === \App\Models\Tenant\CbtSession::STATUS_GRADED
                        ? $reporting->passed($latest->assessment, $score)
                        : null,
                ];
            });

        // Which of this page's applicants have sent something staff
        // hasn't looked at yet (user directive 2026-09-09), so the
        // board can flag it without anyone needing to open every
        // applicant's thread just to check.
        $unreadFromApplicant = AdmissionMessage::whereIn('applicant_id', $applicantIds)
            ->where('sender', AdmissionMessage::SENDER_APPLICANT)
            ->whereNull('read_at')
            ->pluck('applicant_id')
            ->unique();

        return view('school.admissions.pipeline', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'applicants' => $applicants,
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $counts,
            'assessmentResults' => $assessmentResults,
            'unreadFromApplicant' => $unreadFromApplicant,
        ]);
    }

    /**
     * The message thread with one applicant (user directive 2026-09-09:
     * "add a option for schools admins to send a message to applicants
     * and for applicants to also send back to them"). Deliberately its
     * own small screen rather than squeezed into the pipeline row,
     * which is already crowded with per-row action forms. Opening this
     * marks every unread applicant message read, the same "viewing the
     * thread is what marks it read" behaviour the rest of this app uses
     * for notifications.
     */
    public function messages(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'admissions.decide');
        $school = $this->school($request);
        $applicant = Applicant::findOrFail($request->route('applicantId'));

        $thread = AdmissionMessage::where('applicant_id', $applicant->id)->orderBy('created_at')->get();

        AdmissionMessage::where('applicant_id', $applicant->id)
            ->where('sender', AdmissionMessage::SENDER_APPLICANT)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $senderNames = User::whereIn('id', $thread->pluck('sender_user_id')->filter()->unique())->pluck('name', 'id');

        return view('school.admissions.messages', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'applicant' => $applicant,
            'thread' => $thread,
            'senderNames' => $senderNames,
        ]);
    }

    public function sendMessage(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.decide');
        $school = $this->school($request);
        $applicant = Applicant::findOrFail($request->route('applicantId'));

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        AdmissionMessage::create([
            'applicant_id' => $applicant->id,
            'sender' => AdmissionMessage::SENDER_STAFF,
            'sender_user_id' => Auth::id(),
            'body' => $data['body'],
        ]);

        // Applicants are early in the relationship and easy to lose
        // touch with (no in-app notification exists for them yet, the
        // same reason the nudge/offer/credential mails all go by
        // email), so a real email is the reliable way they will
        // actually see this rather than the message sitting unread on
        // a portal they have no reason to open.
        if ($applicant->email) {
            Mail::to($applicant->email)->send(new AdmissionMessageMail($applicant, $school, $data['body']));
        }

        return redirect()->route('school.admissions.pipeline.messages', ['school' => $school->slug, 'applicantId' => $applicant->id])
            ->with('status', 'Message sent.');
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

    public function offer(Request $request, AdmissionOfferService $offers): \Illuminate\Http\RedirectResponse
    {
        $response = $this->decide($request, Applicant::DECISION_OFFERED);
        // decide() unconditionally sets decision_status to the $decision
        // passed in above (or aborts before returning), so the applicant
        // is guaranteed offered by this point — the notice is a side
        // effect of offer() specifically, not of decide() itself, which
        // waitlist()/reject() below also share and must NOT trigger this.
        $applicant = Applicant::findOrFail($request->route('applicantId'));

        // Admin's optional stream override (2026-08-06, user directive:
        // "the admin determines which class teacher if the class has more
        // than one stream but by default, the class with the lowest
        // number of child get the new student"). Left blank, acceptance
        // falls back to ClassArmAssignmentService::lowestPopulationArm().
        $armData = $request->validate(['preferred_class_arm_id' => ['nullable', 'exists:tenant.class_arms,id']]);
        if (! empty($armData['preferred_class_arm_id'])) {
            $applicant->forceFill(['preferred_class_arm_id' => $armData['preferred_class_arm_id']])->save();
        }

        $offers->sendOfferNotice($this->school($request), $applicant);

        return $response;
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

    /** Staff-facing view/print of the same admission letter the applicant sees (2026-08-06), reusing the one shared view. */
    public function letter(Request $request, \App\Services\Admissions\AdmissionLetterContentService $letterContent): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'admissions.decide');
        $school = $this->school($request);
        $applicant = Applicant::with(['schoolClass', 'session'])->findOrFail($request->route('applicantId'));
        abort_unless(
            in_array($applicant->decision_status, [Applicant::DECISION_OFFERED, Applicant::DECISION_CONVERTED], true),
            404
        );

        return view('admissions.letter', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'applicant' => $applicant,
            'letterContent' => $letterContent->render($school, $applicant),
            'backRoute' => route('school.admissions.pipeline', ['school' => $school->slug]),
        ]);
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
