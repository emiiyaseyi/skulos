@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Subjects'])
@section('title', $class->name.' Subjects')

@push('head')
<style>
  .flagbox{display:flex;gap:1rem;align-items:center;flex-wrap:wrap}
  .flagbox label{display:flex;align-items:center;gap:.35rem;font-weight:500;font-size:.85rem}
  .flagbox input{width:auto}
  .flagbox input.seq{width:3.5rem}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $class->name }} Subjects</h1>
    <p class="subtitle">{{ $class->section?->name }}. Choose which subjects this class offers, and whether each is mandatory and examinable.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.academics.index', ['school' => $school->slug]) }}">Back to Classes &amp; Subjects</a>
</div>

@if($canManage && $suggestedCatalog)
  <div class="card" style="margin-bottom:1.1rem;border-color:var(--accent)">
    <div class="row-between">
      <div>
        <strong>{{ $catalogs[$suggestedCatalog]['label'] }} default subject list</strong>
        <p class="muted" style="margin-top:.2rem">Load the standard subject list for this section as a starting point. You can edit, add, or remove any of them afterwards.</p>
      </div>
      <form method="POST" action="{{ route('school.academics.subjects.apply-defaults', ['school' => $school->slug, 'classId' => $class->id]) }}">
        @csrf
        <input type="hidden" name="catalog" value="{{ $suggestedCatalog }}" />
        <button class="btn btn-primary btn-sm" type="submit">Load default subjects</button>
      </form>
    </div>
  </div>
@elseif($canManage)
  <div class="card" style="margin-bottom:1.1rem">
    <div class="row-between">
      <strong>Load a default subject list</strong>
      <form method="POST" action="{{ route('school.academics.subjects.apply-defaults', ['school' => $school->slug, 'classId' => $class->id]) }}" style="display:flex;gap:.5rem">
        @csrf
        <select name="catalog">
          @foreach($catalogs as $key => $catalog)
            <option value="{{ $key }}">{{ $catalog['label'] }}</option>
          @endforeach
        </select>
        <button class="btn btn-ghost btn-sm" type="submit">Load</button>
      </form>
    </div>
  </div>
@endif

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.8rem">Subjects offered</h3>
  @if($offered->isEmpty())
    <p class="muted">No subjects added to this class yet.</p>
  @else
    @if($canManage)
      <form id="bulkSubjectsForm" method="POST" action="{{ route('school.academics.subjects.bulk-detach', ['school' => $school->slug, 'classId' => $class->id]) }}"></form>
    @endif
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        @if($canManage)<th style="width:2.2rem"><input type="checkbox" id="subject-select-all" aria-label="Select all subjects" /></th>@endif
        <th>Subject</th><th>Mandatory</th><th>Examinable</th>@if($canManage)<th></th>@endif</tr></thead>
      <tbody>
        @foreach($offered as $subject)
          <tr>
            @if($canManage)<td><input type="checkbox" form="bulkSubjectsForm" class="subject-row-check" name="subject_ids[]" value="{{ $subject->id }}" aria-label="Select {{ $subject->name }}" /></td>@endif
            <td><strong>{{ $subject->name }}</strong></td>
            @if($canManage)
              <td colspan="2">
                <form method="POST" action="{{ route('school.academics.subjects.update', ['school' => $school->slug, 'classId' => $class->id, 'subjectId' => $subject->id]) }}" class="flagbox">
                  @csrf
                  <label><input type="checkbox" name="is_mandatory" value="1" @checked($subject->pivot->is_mandatory) onchange="this.form.requestSubmit()" /> Mandatory</label>
                  <label><input type="checkbox" name="is_examinable" value="1" @checked($subject->pivot->is_examinable) onchange="this.form.requestSubmit()" /> Examinable</label>
                  <label>Position <input class="seq" type="number" name="sequence" min="1" value="{{ $subject->sequence }}" onchange="this.form.requestSubmit()" aria-label="Position of {{ $subject->name }} in the list" /></label>
                </form>
              </td>
              <td>
                <form method="POST" action="{{ route('school.academics.subjects.detach', ['school' => $school->slug, 'classId' => $class->id, 'subjectId' => $subject->id]) }}" onsubmit="return confirm('Remove {{ $subject->name }} from this class?')">
                  @csrf @method('DELETE')
                  <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
                </form>
              </td>
            @else
              <td>{{ $subject->pivot->is_mandatory ? 'Yes' : 'Optional' }}</td>
              <td>{{ $subject->pivot->is_examinable ? 'Yes' : 'No' }}</td>
            @endif
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    @if($canManage)
      <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
        <span class="muted" style="font-size:.85rem">Tick any number of subjects above, then remove them all at once.</span>
        <button form="bulkSubjectsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" onclick="return confirm('Remove the selected subjects from this class?')">Remove selected</button>
      </div>
      <script>
        (function(){
          var all = document.getElementById('subject-select-all');
          var boxes = document.querySelectorAll('.subject-row-check');
          all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
          document.getElementById('bulkSubjectsForm')?.addEventListener('submit', function(e){
            var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
            if (! anyChecked) { e.preventDefault(); alert('Tick at least one subject first.'); }
          });
        })();
      </script>
    @endif
  @endif
</div>

@if($canManage)
<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <h3 style="margin-bottom:.8rem">Add an existing subject</h3>
    @if($available->isEmpty())
      <p class="muted">Every subject in your school's catalog is already on this class.</p>
    @else
      <form method="POST" action="{{ route('school.academics.subjects.attach', ['school' => $school->slug, 'classId' => $class->id]) }}">
        @csrf
        <div class="field">
          <label for="subject_id">Subject</label>
          <select id="subject_id" name="subject_id" required>
            @foreach($available as $subject)
              <option value="{{ $subject->id }}">{{ $subject->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="flagbox" style="margin-bottom:1rem">
          <label><input type="checkbox" name="is_mandatory" value="1" checked /> Mandatory</label>
          <label><input type="checkbox" name="is_examinable" value="1" checked /> Examinable</label>
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Add to class</button>
      </form>
    @endif
  </div>

  <div class="card">
    <h3 style="margin-bottom:.8rem">Add a brand new subject</h3>
    <form method="POST" action="{{ route('school.academics.subjects.new', ['school' => $school->slug, 'classId' => $class->id]) }}">
      @csrf
      <div class="field">
        <label for="new_name">Subject name</label>
        <input id="new_name" name="name" placeholder="e.g. Robotics" required />
      </div>
      <div class="field">
        <label for="new_code">Code (optional)</label>
        <input id="new_code" name="code" placeholder="e.g. ROB" />
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Create &amp; add to class</button>
    </form>
  </div>
</div>
@endif
@endsection
