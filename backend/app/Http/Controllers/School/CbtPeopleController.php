<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtGrader;
use App\Models\Tenant\CbtGradingConfig;
use App\Models\Tenant\CbtProctor;
use App\Models\User;
use App\Services\Cbt\GradingDistributionService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Assigning the two roles the Assessment Hub adds on top of ordinary
 * RBAC: PROCTORS (who may invigilate) and GRADERS (who may mark
 * written answers, at which layer, and whether blind).
 *
 * Both are scoped either school-wide (null assessment_id) or to one
 * assessment. They are separate from SKULOS roles on purpose: being a
 * teacher does not make you an invigilator on someone else's exam, and
 * a school may bring in a moderator who teaches nothing at all.
 */
class CbtPeopleController extends Controller
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

    /** Staff who could plausibly be given either role — school members who are not students or parents. */
    protected function assignableStaff(School $school)
    {
        return $school->users()
            ->wherePivot('status', 'active')
            ->get()
            ->reject(fn (User $user) => in_array($user->roleIn($school)?->slug, ['student', 'parent'], true))
            ->sortBy('name')
            ->values();
    }

    public function proctors(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        return view('school.cbt.people.proctors', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'proctors' => CbtProctor::with('assessment')->orderByRaw('assessment_id is null desc')->orderBy('id')->get(),
            'assessments' => CbtAssessment::orderByDesc('id')->get(),
            'staff' => $this->assignableStaff($school),
            'staffNames' => $school->users()->pluck('name', 'users.id'),
        ]);
    }

    public function storeProctor(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'assessment_id' => ['nullable', 'exists:tenant.cbt_assessments,id'],
        ]);
        abort_unless($school->users()->where('users.id', $data['user_id'])->exists(), 422, 'That person is not a member of this school.');

        CbtProctor::firstOrCreate([
            'assessment_id' => $data['assessment_id'] ?: null,
            'user_id' => $data['user_id'],
        ], ['role' => 'proctor']);

        return back()->with('status', 'Proctor added.');
    }

    public function destroyProctor(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        CbtProctor::findOrFail($request->route('proctorId'))->delete();

        return back()->with('status', 'Proctor removed.');
    }

    public function graders(Request $request, GradingDistributionService $distribution): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        $assessments = CbtAssessment::with('gradingConfig')->orderByDesc('id')->get();

        return view('school.cbt.people.graders', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'graders' => CbtGrader::with('assessment')->orderByRaw('assessment_id is null desc')->orderBy('grade_layer')->orderBy('id')->get(),
            'assessments' => $assessments,
            'staff' => $this->assignableStaff($school),
            'staffNames' => $school->users()->pluck('name', 'users.id'),
            'modes' => CbtGradingConfig::MODES,
        ]);
    }

    public function storeGrader(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'assessment_id' => ['nullable', 'exists:tenant.cbt_assessments,id'],
            'grade_layer' => ['required', Rule::in([CbtGrader::LAYER_FIRST, CbtGrader::LAYER_REVIEW])],
            'deadline' => ['nullable', 'date'],
            'anonymous' => ['sometimes', 'boolean'],
        ]);
        abort_unless($school->users()->where('users.id', $data['user_id'])->exists(), 422, 'That person is not a member of this school.');

        CbtGrader::updateOrCreate([
            'assessment_id' => $data['assessment_id'] ?: null,
            'user_id' => $data['user_id'],
            'grade_layer' => $data['grade_layer'],
        ], [
            'anonymous' => $request->boolean('anonymous'),
            'deadline' => $data['deadline'] ?? null,
        ]);

        return back()->with('status', 'Grader added.');
    }

    public function toggleAnonymous(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $grader = CbtGrader::findOrFail($request->route('graderId'));
        $grader->update(['anonymous' => ! $grader->anonymous]);

        return back()->with('status', $grader->anonymous ? 'This grader now marks blind.' : 'This grader can now see taker names.');
    }

    public function destroyGrader(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        CbtGrader::findOrFail($request->route('graderId'))->delete();

        return back()->with('status', 'Grader removed.');
    }

    /** Sets how one assessment's essays are shared out, then deals any that are waiting. */
    public function updateGradingMode(Request $request, GradingDistributionService $distribution): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $assessment = CbtAssessment::findOrFail($request->route('assessmentId'));

        $data = $request->validate([
            'mode' => ['required', Rule::in(CbtGradingConfig::MODES)],
            'cap' => ['nullable', 'integer', 'min:0'],
        ]);

        $config = $distribution->configFor($assessment);
        $config->update(['mode' => $data['mode'], 'cap' => $data['cap'] ?? 0]);

        $dealt = $distribution->distribute($assessment);

        return back()->with('status', $dealt > 0
            ? "Grading mode saved. {$dealt} attempt(s) shared out."
            : 'Grading mode saved.');
    }
}
