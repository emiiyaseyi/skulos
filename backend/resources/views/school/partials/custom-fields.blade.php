{{-- Admin-defined fields (user request 2026-07-22), rendered wherever a
     Student/Staff/Guardian form includes this partial. Expects
     $customFields (active CustomFieldDefinition rows for this entity
     type, from CustomFieldService::forEntity()) and $customValues (an
     array of field_key => current value, empty on a create form).
     $idPrefix is optional and only needed when this partial renders more
     than once on the same page (e.g. the student profile page includes
     it once per guardian's own edit form, plus once for "add guardian"):
     without a distinct prefix per instance every render would emit the
     same "custom_{field_key}" id, invalid duplicate HTML ids that break
     which <input> each <label for="..."> actually points at.

     The prefix expression is inlined at every use below rather than
     computed once via @php(...): a single-line @php() immediately
     followed by @if/@foreach has silently failed to compile in this
     codebase before (see CLAUDE.md), inlining avoids that risk entirely. --}}
@if($customFields->isNotEmpty())
  <div class="field" style="grid-column:1/-1"><h3 style="margin:.4rem 0">Additional fields</h3></div>
  @foreach($customFields as $customField)
    <div class="field">
      <label @class(['req' => $customField->is_required]) for="custom_{{ ($idPrefix ?? null) ? $idPrefix.'_' : '' }}{{ $customField->field_key }}">{{ $customField->label }}</label>
      @if($customField->type === 'select')
        <select id="custom_{{ ($idPrefix ?? null) ? $idPrefix.'_' : '' }}{{ $customField->field_key }}" name="custom[{{ $customField->field_key }}]" @required($customField->is_required)>
          <option value="">Not set</option>
          @foreach($customField->options ?? [] as $option)
            <option value="{{ $option }}" @selected(old('custom.'.$customField->field_key, $customValues[$customField->field_key] ?? null) === $option)>{{ $option }}</option>
          @endforeach
        </select>
      @elseif($customField->type === 'textarea')
        <textarea id="custom_{{ ($idPrefix ?? null) ? $idPrefix.'_' : '' }}{{ $customField->field_key }}" name="custom[{{ $customField->field_key }}]" rows="2" @required($customField->is_required)>{{ old('custom.'.$customField->field_key, $customValues[$customField->field_key] ?? '') }}</textarea>
      @elseif($customField->type === 'checkbox')
        <div><input id="custom_{{ ($idPrefix ?? null) ? $idPrefix.'_' : '' }}{{ $customField->field_key }}" type="checkbox" name="custom[{{ $customField->field_key }}]" value="1" @checked(old('custom.'.$customField->field_key, $customValues[$customField->field_key] ?? false)) /></div>
      @else
        <input id="custom_{{ ($idPrefix ?? null) ? $idPrefix.'_' : '' }}{{ $customField->field_key }}" name="custom[{{ $customField->field_key }}]"
          type="{{ $customField->type === 'number' ? 'number' : ($customField->type === 'date' ? 'date' : 'text') }}"
          value="{{ old('custom.'.$customField->field_key, $customValues[$customField->field_key] ?? '') }}"
          @required($customField->is_required) />
      @endif
      @error('custom.'.$customField->field_key)<div class="err">{{ $message }}</div>@enderror
    </div>
  @endforeach
@endif
