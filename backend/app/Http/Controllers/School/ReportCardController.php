<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Student;
use App\Models\Tenant\Term;
use App\Services\Rbac\Rbac;
use App\Services\ReportCards\ReportCardPreferences;
use App\Services\ReportCards\ReportCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Report card template/field settings, a synthetic preview so an admin can
 * compare templates before any real scores exist, and the real per student
 * render once they do.
 *
 * Scalar route ids/values are read via $request->route(...) rather than
 * typed method parameters: these routes sit under a domain wildcard
 * ({school}.<domain>), and Laravel resolves untyped scalar controller
 * parameters positionally against ALL route parameters including the
 * domain one, not by name — so a typed parameter would silently receive
 * the school slug instead of the intended value.
 */
class ReportCardController extends Controller
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

    public function edit(Request $request, ReportCardPreferences $prefs): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.view');
        $school = $this->school($request);

        return view('school.report-cards.settings', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'currentTemplate' => $prefs->template(),
            'fields' => $prefs->fields(),
        ]);
    }

    public function update(Request $request, ReportCardPreferences $prefs): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.update');
        $data = $request->validate([
            'template' => ['required', 'string', Rule::in(array_keys(config('skulos.report_card_templates')))],
            'fields' => ['array'],
        ]);

        $prefs->setTemplate($data['template']);
        $prefs->setFields($request->input('fields', []));

        return back()->with('status', 'Your report card look has been updated.');
    }

    public function preview(Request $request, ReportCardService $cards, ReportCardPreferences $prefs): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.view');
        $school = $this->school($request);
        $templates = array_keys(config('skulos.report_card_templates'));
        $requested = $request->route('template');
        $template = in_array($requested, $templates, true) ? $requested : $prefs->template();

        return view('school.report-cards.render', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'template' => $template,
            'fields' => $prefs->fields(),
            'data' => $cards->sample(),
            'isPreview' => true,
        ]);
    }

    public function show(Request $request, ReportCardService $cards, ReportCardPreferences $prefs): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.view');
        $school = $this->school($request);
        $student = Student::findOrFail($request->route('studentId'));
        $term = Term::findOrFail($request->route('termId'));

        return view('school.report-cards.render', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'template' => $prefs->template(),
            'fields' => $prefs->fields(),
            'data' => $cards->forStudent($student, $term),
            'isPreview' => false,
        ]);
    }
}
