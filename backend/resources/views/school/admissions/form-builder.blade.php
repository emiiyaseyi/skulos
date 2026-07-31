@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Admission Form Builder'])
@section('title', 'Admission Form Builder')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Admission Form Builder</h1>
    <p class="subtitle">Build your own application form, grouped into sections and steps. An applicant fills this in from their own dashboard.</p>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.8rem">Add a section</h3>
  <form method="POST" action="{{ route('school.admissions.form-builder.sections.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="grid cols-2">
      <div class="field">
        <label for="section_label">Section name</label>
        <input id="section_label" name="label" placeholder="e.g. Guardian Information" value="{{ old('label') }}" required />
      </div>
      <div class="field">
        <label for="section_step">Step</label>
        <input id="section_step" name="step" type="number" min="1" max="20" value="{{ old('step', 1) }}" required />
        <div class="hint">Sections sharing a step number appear together on one screen of the application wizard.</div>
      </div>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Add section</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.8rem">Add a field</h3>
  <form method="POST" action="{{ route('school.admissions.form-builder.fields.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="grid cols-2">
      <div class="field">
        <label for="field_label">Field name</label>
        <input id="field_label" name="label" placeholder="e.g. Previous School" value="{{ old('label') }}" required />
      </div>
      <div class="field">
        <label for="field_type">Field type</label>
        <select id="field_type" name="type" onchange="document.getElementById('field_options_wrap').hidden = (this.value !== 'select')">
          @foreach($types as $type)
            <option value="{{ $type }}" @selected(old('type') === $type)>{{ ucfirst($type) }}</option>
          @endforeach
        </select>
      </div>
    </div>
    <div class="field" id="field_options_wrap" @if(old('type', 'text') !== 'select') hidden @endif>
      <label for="field_options">Dropdown options (one per line)</label>
      <textarea id="field_options" name="options" rows="3">{{ old('options') }}</textarea>
    </div>
    <div class="field">
      <label for="field_section">Section</label>
      <select id="field_section" name="admission_form_section_id">
        <option value="">No section (not shown on the applicant form yet)</option>
        @foreach($sections as $section)
          <option value="{{ $section->id }}" @selected((string) old('admission_form_section_id') === (string) $section->id)>{{ $section->label }} (Step {{ $section->step }})</option>
        @endforeach
      </select>
    </div>
    <label style="display:flex;align-items:center;gap:.4rem;margin-bottom:.8rem">
      <input type="checkbox" name="is_required" value="1" @checked(old('is_required')) /> Required
    </label>
    <button class="btn btn-primary btn-sm" type="submit">Add field</button>
  </form>
</div>

<div class="card">
  <h3 style="margin-bottom:.8rem">Sections and fields</h3>
  @if($sections->isEmpty())
    <p class="muted">No sections yet. Add one above, then add fields to it.</p>
  @else
    <form id="bulkFieldsForm" method="POST" action="{{ route('school.admissions.form-builder.fields.bulk-action', ['school' => $school->slug]) }}"></form>
    @foreach($sections as $section)
      <div style="border:1px solid var(--line);border-radius:10px;padding:.8rem;margin-bottom:.8rem">
        <div class="row-between" style="margin-bottom:.6rem">
          <strong>{{ $section->label }} &middot; Step {{ $section->step }}</strong>
          <form method="POST" action="{{ route('school.admissions.form-builder.sections.destroy', ['school' => $school->slug, 'sectionId' => $section->id]) }}" onsubmit="return confirm('Remove this section? Its fields are kept, unassigned.')">
            @csrf @method('DELETE')
            <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove section</button>
          </form>
        </div>
        @if($section->fields->isEmpty())
          <p class="muted" style="font-size:.85rem">No fields in this section yet.</p>
        @else
          <div style="overflow-x:auto">
          <table>
            <thead><tr><th style="width:2.2rem"><input type="checkbox" class="section-select-all" aria-label="Select all in this section" /></th><th>Field</th><th>Type</th><th>Required</th><th>Status</th><th></th></tr></thead>
            <tbody>
              @foreach($section->fields as $field)
                <tr>
                  <td><input type="checkbox" form="bulkFieldsForm" class="fields-row-check" name="field_ids[]" value="{{ $field->id }}" aria-label="Select {{ $field->label }}" /></td>
                  <td><strong>{{ $field->label }}</strong></td>
                  <td class="muted">{{ ucfirst($field->type) }}</td>
                  <td>{{ $field->is_required ? 'Yes' : 'No' }}</td>
                  <td><span class="badge {{ $field->is_active ? 'badge-ok' : 'badge-warn' }}">{{ $field->is_active ? 'Active' : 'Inactive' }}</span></td>
                  <td style="white-space:nowrap">
                    <details style="display:inline-block">
                      <summary class="btn btn-ghost btn-sm" style="display:inline-block;cursor:pointer">Edit</summary>
                      <form method="POST" action="{{ route('school.admissions.form-builder.fields.update', ['school' => $school->slug, 'fieldId' => $field->id]) }}" style="margin-top:.6rem;min-width:16rem;text-align:left">
                        @csrf
                        <div class="field">
                          <label for="fl_{{ $field->id }}">Field name</label>
                          <input id="fl_{{ $field->id }}" name="label" value="{{ $field->label }}" required />
                        </div>
                        <div class="field">
                          <label for="ft_{{ $field->id }}">Type</label>
                          <select id="ft_{{ $field->id }}" name="type" onchange="document.getElementById('fo_{{ $field->id }}').hidden = (this.value !== 'select')">
                            @foreach($types as $type)
                              <option value="{{ $type }}" @selected($field->type === $type)>{{ ucfirst($type) }}</option>
                            @endforeach
                          </select>
                        </div>
                        <div class="field" id="fo_{{ $field->id }}" @if($field->type !== 'select') hidden @endif>
                          <label for="fov_{{ $field->id }}">Dropdown options (one per line)</label>
                          <textarea id="fov_{{ $field->id }}" name="options" rows="3">{{ implode("\n", $field->options ?? []) }}</textarea>
                        </div>
                        <div class="field">
                          <label for="fs_{{ $field->id }}">Section</label>
                          <select id="fs_{{ $field->id }}" name="admission_form_section_id">
                            <option value="">No section</option>
                            @foreach($sections as $s)
                              <option value="{{ $s->id }}" @selected($field->admission_form_section_id === $s->id)>{{ $s->label }} (Step {{ $s->step }})</option>
                            @endforeach
                          </select>
                        </div>
                        <label style="display:flex;align-items:center;gap:.4rem;margin-bottom:.6rem">
                          <input type="checkbox" name="is_required" value="1" @checked($field->is_required) /> Required
                        </label>
                        <button class="btn btn-primary btn-sm" type="submit">Save</button>
                      </form>
                    </details>
                    <form method="POST" action="{{ route('school.admissions.form-builder.fields.destroy', ['school' => $school->slug, 'fieldId' => $field->id]) }}" style="display:inline" onsubmit="return confirm('Remove this field? Every answer recorded against it is removed too.')">
                      @csrf @method('DELETE')
                      <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Delete</button>
                    </form>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
          </div>
        @endif
      </div>
    @endforeach
    <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
      <span class="muted" style="font-size:.85rem">Tick any number of fields above, then choose an action.</span>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button form="bulkFieldsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="activate">Activate</button>
        <button form="bulkFieldsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="deactivate">Deactivate</button>
        <button form="bulkFieldsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="delete" onclick="return confirm('Remove the selected fields? Every answer recorded against them is removed too.')">Delete selected</button>
      </div>
    </div>
    <script>
      (function(){
        document.querySelectorAll('.section-select-all').forEach(function(all){
          all.addEventListener('change', function(){
            var table = all.closest('table');
            table.querySelectorAll('.fields-row-check').forEach(function(cb){ cb.checked = all.checked; });
          });
        });
        document.getElementById('bulkFieldsForm').addEventListener('submit', function(e){
          var any = document.querySelectorAll('.fields-row-check:checked').length > 0;
          if (! any) { e.preventDefault(); alert('Tick at least one field first.'); }
        });
      })();
    </script>
  @endif
</div>
@endsection
