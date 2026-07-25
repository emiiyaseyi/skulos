<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\GradingBand;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Score;
use App\Models\Tenant\Section;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Grading scales (user request 2026-07-11: "the grading structure i gave
 * you earlier... has not been implemented, this should be a sub section
 * under results"). The scale/band model (`GradingScale`/`GradingBand`)
 * and the default catalog (`config('skulos.default_grading_scales')`,
 * sourced from the same "List of subjects and Grading system.docx" the
 * subject catalogs already came from: the real NECO/BECE 5 point Junior
 * scale and the real WAEC 9 point Senior scale) already existed with no
 * admin screen at all to actually create one, only `GradingScale::
 * default()` was ever read, by the student report. Same gated permission
 * as Assessment Structure, `examinations.approve_results`, since choosing
 * a school's grading format is an institution wide policy decision.
 */
class GradingController extends Controller
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
        $this->authorizeAction($request, 'examinations.view');
        $school = $this->school($request);

        return view('school.examinations.grading', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'scales' => GradingScale::with('bands')->orderBy('sequence')->orderBy('name')->get(),
            'sections' => Section::with(['classes' => fn ($q) => $q->orderBy('sequence')])->orderBy('sequence')->get(),
            'defaultCatalog' => config('skulos.default_grading_scales'),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'examinations.approve_results'),
        ]);
    }

    /**
     * Which scale a section's report cards grade against (user request
     * 2026-07-24: "check the section grade structure to get the grade
     * to be placed here... it changes based on the section and grade
     * set"). Left unset (null), a section falls back to whichever scale
     * is the school wide default, exactly today's behaviour.
     */
    public function assignSection(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $section = Section::findOrFail($request->route('sectionId'));

        $data = $request->validate([
            'grading_scale_id' => ['nullable', 'exists:tenant.grading_scales,id'],
        ]);

        $section->update(['grading_scale_id' => $data['grading_scale_id'] ?? null]);

        return back()->with('status', "Grading scale updated for {$section->name}.");
    }

    /**
     * A class's own scale, overriding its section's own scale (real bug
     * found live on greenfield, 2026-07-24: JSS and SSS both sit under
     * one shared "Secondary" section, so the section level assignment
     * above alone cannot tell WAEC graded SSS apart from NECO/BECE
     * graded JSS). Left unset, a class falls back to its section's own
     * scale, then the school wide default.
     */
    public function assignClass(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $class = SchoolClass::findOrFail($request->route('classId'));

        $data = $request->validate([
            'grading_scale_id' => ['nullable', 'exists:tenant.grading_scales,id'],
        ]);

        $class->update(['grading_scale_id' => $data['grading_scale_id'] ?? null]);

        return back()->with('status', "Grading scale updated for {$class->name}.");
    }

    /**
     * Loads whichever of the 3 default scales are not already present by
     * name, so re-running after a school already loaded one (or defined
     * its own scale with a different name) only tops up what is missing.
     * Each scale's sequence is its fixed position in the config catalog
     * (Nursery/Primary, then Junior, then Senior — found live 2026-07-13:
     * the Grading page previously sorted scales alphabetically by name,
     * "Junior Secondary Scale" before "Nursery and Primary Scale"), only
     * ever fills in a still-unset (0) sequence, so re-running this or the
     * retroactive repair never resets a scale an admin already manually
     * reordered.
     */
    public function applyDefaults(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $existingNames = GradingScale::pluck('name')->all();

        $created = 0;
        foreach (array_values(config('skulos.default_grading_scales')) as $index => $catalog) {
            if (in_array($catalog['name'], $existingNames, true)) {
                continue;
            }

            $scale = GradingScale::create(['name' => $catalog['name'], 'sequence' => $index + 1]);
            foreach ($catalog['bands'] as $band) {
                GradingBand::create([
                    'grading_scale_id' => $scale->id,
                    'min_score' => $band['min'],
                    'max_score' => $band['max'],
                    'grade' => $band['grade'],
                    'remark' => $band['remark'],
                ]);
            }
            $created++;
        }

        if (! GradingScale::where('is_default', true)->exists()) {
            GradingScale::first()?->makeDefault();
        }

        return back()->with('status', $created > 0 ? "{$created} default grading scale(s) loaded." : 'The default scales are already on your list.');
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $scale = GradingScale::create($data);
        if (! GradingScale::where('is_default', true)->where('id', '!=', $scale->id)->exists()) {
            $scale->makeDefault();
        }

        return back()->with('status', "\"{$scale->name}\" added.");
    }

    public function makeDefault(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $scale = GradingScale::findOrFail($request->route('scaleId'));
        $scale->makeDefault();

        return back()->with('status', "\"{$scale->name}\" is now the default scale.");
    }

    /**
     * Refuses to delete a scale with any scores already graded against
     * it (a scale has no direct Score foreign key, this is checked via
     * every subject a student was scored in during a term that used this
     * scale... in practice a scale's use is only tracked through
     * ResultService's live lookup, not stored per score, so instead this
     * simply refuses whenever any Score exists at all for the school
     * while this is the only/default scale, a conservative but safe
     * guard against deleting the scale a real report card was built
     * against).
     */
    public function destroy(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $scale = GradingScale::findOrFail($request->route('scaleId'));

        if ($scale->is_default && Score::exists()) {
            return back()->with('error', "\"{$scale->name}\" is the default scale and scores already exist. Make another scale the default first.");
        }

        $name = $scale->name;
        $scale->delete();

        return back()->with('status', "\"{$name}\" removed.");
    }

    public function storeBand(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $scale = GradingScale::findOrFail($request->route('scaleId'));

        $data = $request->validate([
            'min_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_score' => ['required', 'numeric', 'min:0', 'max:100', 'gte:min_score'],
            'grade' => ['required', 'string', 'max:10'],
            'remark' => ['nullable', 'string', 'max:100'],
        ]);

        GradingBand::create($data + ['grading_scale_id' => $scale->id]);

        return back()->with('status', 'Band added.');
    }

    public function updateBand(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $band = GradingBand::findOrFail($request->route('bandId'));

        $data = $request->validate([
            'min_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_score' => ['required', 'numeric', 'min:0', 'max:100', 'gte:min_score'],
            'grade' => ['required', 'string', 'max:10'],
            'remark' => ['nullable', 'string', 'max:100'],
        ]);

        $band->update($data);

        return back()->with('status', 'Band updated.');
    }

    public function destroyBand(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $band = GradingBand::findOrFail($request->route('bandId'));
        $band->delete();

        return back()->with('status', 'Band removed.');
    }

    /** Bulk version of destroyBand(), same list-screen bulk-action pattern used across the app. */
    public function bulkDestroyBands(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $data = $request->validate([
            'band_ids' => ['required', 'array', 'min:1'],
            'band_ids.*' => ['exists:tenant.grading_bands,id'],
        ]);

        GradingBand::whereIn('id', $data['band_ids'])->delete();

        return back()->with('status', count($data['band_ids']).' band(s) removed.');
    }
}
