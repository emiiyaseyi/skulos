<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AdmissionFormField;
use App\Models\Tenant\AdmissionFormSection;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Admissions module, Phase 1e: the school's own custom application form
 * builder. Same tier/pattern as CustomFieldController (school_info/
 * students.manage_settings), gated on admissions.manage_settings.
 */
class AdmissionFormBuilderController extends Controller
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
        $this->authorizeAction($request, 'admissions.manage_settings');
        $school = $this->school($request);
        AdmissionFormSection::ensureSeeded();

        return view('school.admissions.form-builder', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'sections' => AdmissionFormSection::with(['fields' => fn ($q) => $q->orderBy('sequence')])->orderBy('step')->orderBy('sequence')->get(),
            'types' => AdmissionFormField::TYPES,
        ]);
    }

    public function storeSection(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.manage_settings');

        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'step' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        AdmissionFormSection::create($data + ['sequence' => (AdmissionFormSection::max('sequence') ?? 0) + 1]);

        return back()->with('status', 'Section added.');
    }

    public function destroySection(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.manage_settings');
        $section = AdmissionFormSection::findOrFail($request->route('sectionId'));
        $label = $section->label;
        $section->delete(); // fields.admission_form_section_id nullOnDelete, not cascaded — a field survives its section going away

        return back()->with('status', "\"{$label}\" removed. Its fields were kept, unassigned from any section.");
    }

    protected function fieldRules(): array
    {
        return [
            'label' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', Rule::in(AdmissionFormField::TYPES)],
            'admission_form_section_id' => ['nullable', 'exists:tenant.admission_form_sections,id'],
            'options' => ['nullable', 'string'],
            'is_required' => ['sometimes', 'boolean'],
        ];
    }

    /** "one, two, three" -> ['one','two','three'], blank lines dropped, only meaningful for type=select. */
    protected function parseOptions(?string $raw): ?array
    {
        if (! $raw) {
            return null;
        }

        $options = array_values(array_filter(array_map('trim', explode("\n", $raw)), fn ($o) => $o !== ''));

        return $options ?: null;
    }

    public function storeField(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.manage_settings');
        $data = $request->validate($this->fieldRules());

        if ($data['type'] === 'select' && ! $this->parseOptions($data['options'] ?? null)) {
            return back()->withErrors(['options' => 'List at least one option for a dropdown field.'])->withInput();
        }

        AdmissionFormField::create([
            'label' => $data['label'],
            'field_key' => AdmissionFormField::keyFromLabel($data['label']),
            'type' => $data['type'],
            'admission_form_section_id' => $data['admission_form_section_id'] ?? null,
            'options' => $data['type'] === 'select' ? $this->parseOptions($data['options'] ?? null) : null,
            'is_required' => $request->boolean('is_required'),
            'sequence' => (AdmissionFormField::max('sequence') ?? 0) + 1,
        ]);

        return back()->with('status', 'Field added.');
    }

    public function updateField(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.manage_settings');
        $field = AdmissionFormField::findOrFail($request->route('fieldId'));
        $data = $request->validate($this->fieldRules());

        if ($data['type'] === 'select' && ! $this->parseOptions($data['options'] ?? null)) {
            return back()->withErrors(['options' => 'List at least one option for a dropdown field.'])->withInput();
        }

        $field->update([
            'label' => $data['label'],
            // field_key deliberately left unchanged, same reasoning as
            // CustomFieldController::update(): existing AdmissionFormValue
            // rows are already keyed against it, renaming the label must
            // never orphan them.
            'type' => $data['type'],
            'admission_form_section_id' => $data['admission_form_section_id'] ?? null,
            'options' => $data['type'] === 'select' ? $this->parseOptions($data['options'] ?? null) : null,
            'is_required' => $request->boolean('is_required'),
        ]);

        return back()->with('status', 'Field updated.');
    }

    public function destroyField(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.manage_settings');
        $field = AdmissionFormField::findOrFail($request->route('fieldId'));
        $label = $field->label;
        $field->delete(); // admission_form_values cascade

        return back()->with('status', "\"{$label}\" removed, along with every answer recorded against it.");
    }

    /** Bulk activate/deactivate/delete, the same list-screen pattern every screen in the product uses. */
    public function bulkFieldAction(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.manage_settings');
        $data = $request->validate([
            'field_ids' => ['required', 'array', 'min:1'],
            'field_ids.*' => ['exists:tenant.admission_form_fields,id'],
            'action' => ['required', 'in:activate,deactivate,delete'],
        ]);

        if ($data['action'] === 'delete') {
            $count = AdmissionFormField::whereIn('id', $data['field_ids'])->count();
            AdmissionFormField::whereIn('id', $data['field_ids'])->delete();

            return back()->with('status', "{$count} field(s) removed, along with every answer recorded against them.");
        }

        $count = AdmissionFormField::whereIn('id', $data['field_ids'])
            ->update(['is_active' => $data['action'] === 'activate']);

        return back()->with('status', "{$count} field(s) updated.");
    }
}
