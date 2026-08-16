@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Custom Fields'])
@section('title', 'Custom Fields')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Custom Fields</h1>
    <p class="subtitle">Add your own fields and choose whether they show up on Students, Staff, or Parents.</p>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.8rem">Add a field</h3>
  <form method="POST" action="{{ route('school.settings.custom-fields.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="grid cols-2">
      <div class="field">
        <label for="cf_label">Field name</label>
        <input id="cf_label" name="label" placeholder="e.g. Blood Group" value="{{ old('label') }}" required />
      </div>
      <div class="field">
        <label for="cf_type">Field type</label>
        <select id="cf_type" name="type" onchange="document.getElementById('cf_options_wrap').hidden = (this.value !== 'select')">
          @foreach($types as $type)
            <option value="{{ $type }}" @selected(old('type') === $type)>{{ ucfirst($type) }}</option>
          @endforeach
        </select>
      </div>
    </div>

    <div class="field" id="cf_options_wrap" @if(old('type', 'text') !== 'select') hidden @endif>
      <label for="cf_options">Dropdown options (one per line)</label>
      <textarea id="cf_options" name="options" rows="3" placeholder="A+&#10;A-&#10;O+&#10;O-">{{ old('options') }}</textarea>
    </div>

    <div class="field">
      <label>Applies to</label>
      <div class="flagbox">
        @foreach($entityTypes as $entityType)
          <label><input type="checkbox" name="entity_types[]" value="{{ $entityType }}" @checked(in_array($entityType, old('entity_types', []), true)) /> {{ ucfirst($entityType) }}{{ $entityType === 'guardian' ? ' (Parent)' : '' }}</label>
        @endforeach
      </div>
    </div>

    <label style="display:flex;align-items:center;gap:.4rem;margin-bottom:.8rem">
      <input type="checkbox" name="is_required" value="1" @checked(old('is_required')) /> Required
    </label>

    <button class="btn btn-primary btn-sm" type="submit">Add field</button>
  </form>
</div>

<div class="card">
  @if($fields->isEmpty())
    <p class="muted">No custom fields yet. Add one above.</p>
  @else
    <form id="bulkFieldsForm" method="POST" action="{{ route('school.settings.custom-fields.bulk-action', ['school' => $school->slug]) }}">@csrf</form>
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        <th style="width:2.2rem"><input type="checkbox" id="fields-select-all" aria-label="Select all fields" /></th>
        <th>Field</th><th>Type</th><th>Applies to</th><th>Required</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($fields as $field)
          <tr>
            <td><input type="checkbox" form="bulkFieldsForm" class="fields-row-check" name="field_ids[]" value="{{ $field->id }}" aria-label="Select {{ $field->label }}" /></td>
            <td><strong>{{ $field->label }}</strong></td>
            <td class="muted">{{ ucfirst($field->type) }}</td>
            <td class="muted">{{ collect($field->entity_types)->map(fn($t) => ucfirst($t))->implode(', ') }}</td>
            <td>{{ $field->is_required ? 'Yes' : 'No' }}</td>
            <td><span class="badge {{ $field->is_active ? 'badge-ok' : 'badge-warn' }}">{{ $field->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td>
              <details>
                <summary class="btn btn-ghost btn-sm" style="display:inline-block;cursor:pointer">Edit</summary>
                <form method="POST" action="{{ route('school.settings.custom-fields.update', ['school' => $school->slug, 'fieldId' => $field->id]) }}" style="margin-top:.6rem;min-width:16rem">
                  @csrf
                  <div class="field">
                    <label for="cf_label_{{ $field->id }}">Field name</label>
                    <input id="cf_label_{{ $field->id }}" name="label" value="{{ $field->label }}" required />
                  </div>
                  <div class="field">
                    <label for="cf_type_{{ $field->id }}">Field type</label>
                    <select id="cf_type_{{ $field->id }}" name="type" onchange="document.getElementById('cf_options_wrap_{{ $field->id }}').hidden = (this.value !== 'select')">
                      @foreach($types as $type)
                        <option value="{{ $type }}" @selected($field->type === $type)>{{ ucfirst($type) }}</option>
                      @endforeach
                    </select>
                  </div>
                  <div class="field" id="cf_options_wrap_{{ $field->id }}" @if($field->type !== 'select') hidden @endif>
                    <label for="cf_options_{{ $field->id }}">Dropdown options (one per line)</label>
                    <textarea id="cf_options_{{ $field->id }}" name="options" rows="3">{{ implode("\n", $field->options ?? []) }}</textarea>
                  </div>
                  <div class="field">
                    <label>Applies to</label>
                    <div class="flagbox">
                      @foreach($entityTypes as $entityType)
                        <label><input type="checkbox" name="entity_types[]" value="{{ $entityType }}" @checked(in_array($entityType, $field->entity_types ?? [], true)) /> {{ ucfirst($entityType) }}{{ $entityType === 'guardian' ? ' (Parent)' : '' }}</label>
                      @endforeach
                    </div>
                  </div>
                  <label style="display:flex;align-items:center;gap:.4rem;margin-bottom:.8rem">
                    <input type="checkbox" name="is_required" value="1" @checked($field->is_required) /> Required
                  </label>
                  <div style="display:flex;gap:.5rem">
                    <button class="btn btn-primary btn-sm" type="submit">Save</button>
                  </div>
                </form>
              </details>
              <form method="POST" action="{{ route('school.settings.custom-fields.destroy', ['school' => $school->slug, 'fieldId' => $field->id]) }}" style="display:inline" onsubmit="return confirm('Remove this field? Every value recorded against it is removed too.')">
                @csrf @method('DELETE')
                <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
      <span class="muted" style="font-size:.85rem">Tick any number of fields above, then choose an action.</span>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button form="bulkFieldsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="activate">Activate</button>
        <button form="bulkFieldsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="deactivate">Deactivate</button>
        <button form="bulkFieldsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="delete" onclick="return confirm('Remove the selected fields? Every value recorded against them is removed too.')">Delete selected</button>
      </div>
    </div>
    <script>
      (function(){
        var selectAll = document.getElementById('fields-select-all');
        if (! selectAll) return;
        selectAll.addEventListener('change', function(){
          document.querySelectorAll('.fields-row-check').forEach(function(cb){ cb.checked = selectAll.checked; });
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
