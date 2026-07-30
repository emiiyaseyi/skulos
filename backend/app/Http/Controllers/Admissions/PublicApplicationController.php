<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AdmissionWindow;
use App\Models\Tenant\Applicant;
use Illuminate\Http\Request;

/**
 * Admissions module, Phase 1c: "Seek Admission" public entry point and
 * self-registration. Deliberately a single combined screen for now, not
 * a multi-page wizard: the only real information to collect at this
 * stage is which open window and basic contact details, the rich
 * per-school custom application form (sections/multi-step/file uploads)
 * lands in Phase 1e, filled in from the applicant's own dashboard after
 * they register here, not before — building a hollow multi-step shell
 * now with nothing real in most of the steps would be exactly the kind
 * of half-finished implementation this project avoids.
 */
class PublicApplicationController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    public function start(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);

        $windows = AdmissionWindow::with(['session', 'schoolClass', 'campus'])
            ->where('is_active', true)
            ->get()
            ->filter(fn (AdmissionWindow $w) => $w->isOpen())
            ->values();

        return view('admissions.apply', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'windows' => $windows,
        ]);
    }

    public function register(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);

        $windowIds = AdmissionWindow::where('is_active', true)->pluck('id')->all();
        $data = $request->validate([
            'admission_window_id' => ['required', 'integer', \Illuminate\Validation\Rule::in($windowIds)],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $window = AdmissionWindow::findOrFail($data['admission_window_id']);
        abort_unless($window->isOpen(), 422, 'This admission window is no longer open.');

        $chain = Applicant::pipelineChain();
        $applicant = Applicant::forceCreate([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'],
            'session_id' => $window->session_id,
            'campus_id' => $window->campus_id,
            'school_class_id' => $window->school_class_id,
            // The staff-numbering sequence pattern used elsewhere (Staff::
            // withTrashed()->count()+1, sprintf('STF/%04d', ...)); Applicant
            // is never soft-deleted, so a plain count() is enough. This
            // reference doubles as the applicant's own sign-in identifier
            // (admission number or parent phone, see ApplicantAuthController)
            // and, if the application is later accepted, becomes the
            // student's real admission number at conversion (Phase 1g).
            'admission_number' => sprintf('APP/%04d', Applicant::count() + 1),
            'pipeline_stage' => array_search('application_started', $chain, true) ?: 0,
            'account_status' => Applicant::STATUS_PENDING_FEE,
        ]);

        return redirect()->route('school.admissions.apply.submitted', [
            'school' => $school->slug,
            'applicant' => $applicant->id,
        ]);
    }

    public function submitted(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        // Read via $request->route(), not a typed method parameter: this
        // route sits under the {school} domain wildcard, so an untyped
        // scalar controller parameter resolves positionally against the
        // full route parameter list (domain param included), not by
        // name — the recurring gotcha documented in CLAUDE.md.
        $applicant = Applicant::findOrFail($request->route('applicant'));

        return view('admissions.submitted', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'applicant' => $applicant,
        ]);
    }
}
