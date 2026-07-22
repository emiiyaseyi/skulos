<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CustomFieldDefinition;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Admin-defined fields (user request 2026-07-22: "I WANT THE ADMIN TO BE
 * ABLE TO CREATE A NEW FIELD THAT IS NOT THERE BEFORE AND ASSIGN TO
 * STUDENTS, STAFF AND PARENTS"). Management only, same tier as Student
 * Field Settings: gated on school_info.manage_settings throughout,
 * including the index itself, not just mutations.
 */
class CustomFieldController extends Controller
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

    protected function rules(?CustomFieldDefinition $definition = null): array
    {
        return [
            'label' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', Rule::in(CustomFieldDefinition::TYPES)],
            'entity_types' => ['required', 'array', 'min:1'],
            'entity_types.*' => [Rule::in(CustomFieldDefinition::ENTITY_TYPES)],
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

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        $school = $this->school($request);

        return view('school.custom-fields.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'fields' => CustomFieldDefinition::orderBy('sequence')->orderBy('label')->get(),
            'types' => CustomFieldDefinition::TYPES,
            'entityTypes' => CustomFieldDefinition::ENTITY_TYPES,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        $data = $request->validate($this->rules());

        if ($data['type'] === 'select' && ! $this->parseOptions($data['options'] ?? null)) {
            return back()->withErrors(['options' => 'List at least one option for a dropdown field.'])->withInput();
        }

        CustomFieldDefinition::create([
            'label' => $data['label'],
            'field_key' => CustomFieldDefinition::keyFromLabel($data['label']),
            'type' => $data['type'],
            'options' => $data['type'] === 'select' ? $this->parseOptions($data['options'] ?? null) : null,
            'entity_types' => $data['entity_types'],
            'is_required' => $request->boolean('is_required'),
            'sequence' => (CustomFieldDefinition::max('sequence') ?? 0) + 1,
        ]);

        return back()->with('status', 'Field added.');
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        $field = CustomFieldDefinition::findOrFail($request->route('fieldId'));
        $data = $request->validate($this->rules($field));

        if ($data['type'] === 'select' && ! $this->parseOptions($data['options'] ?? null)) {
            return back()->withErrors(['options' => 'List at least one option for a dropdown field.'])->withInput();
        }

        $field->update([
            'label' => $data['label'],
            // field_key deliberately left unchanged: it is the stable
            // lookup key existing CustomFieldValue rows are already
            // keyed against, renaming the label must never orphan them.
            'type' => $data['type'],
            'options' => $data['type'] === 'select' ? $this->parseOptions($data['options'] ?? null) : null,
            'entity_types' => $data['entity_types'],
            'is_required' => $request->boolean('is_required'),
        ]);

        return back()->with('status', 'Field updated.');
    }

    public function destroy(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        $field = CustomFieldDefinition::findOrFail($request->route('fieldId'));
        $label = $field->label;
        $field->delete(); // custom_field_values cascade

        return back()->with('status', "\"{$label}\" removed, along with every value recorded against it.");
    }

    /** Bulk activate/deactivate/delete, the same list-screen pattern every screen in the product uses. */
    public function bulkAction(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        $data = $request->validate([
            'field_ids' => ['required', 'array', 'min:1'],
            'field_ids.*' => ['exists:tenant.custom_field_definitions,id'],
            'action' => ['required', 'in:activate,deactivate,delete'],
        ]);

        if ($data['action'] === 'delete') {
            $count = CustomFieldDefinition::whereIn('id', $data['field_ids'])->count();
            CustomFieldDefinition::whereIn('id', $data['field_ids'])->delete();

            return back()->with('status', "{$count} field(s) removed, along with every value recorded against them.");
        }

        $count = CustomFieldDefinition::whereIn('id', $data['field_ids'])
            ->update(['is_active' => $data['action'] === 'activate']);

        return back()->with('status', "{$count} field(s) updated.");
    }
}
