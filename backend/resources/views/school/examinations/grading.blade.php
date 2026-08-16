@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Grading'])
@section('title', 'Grading')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Grading</h1>
    <p class="subtitle">The grading scales report cards and results grade against. A school can have more than one, e.g. a different scale for Junior and Senior Secondary.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.index', ['school' => $school->slug]) }}">Back to Results</a>
</div>

@if($sections->isNotEmpty() && $scales->isNotEmpty())
  <div class="card" style="margin-bottom:1.1rem">
    <h3 style="margin-bottom:.4rem">Which scale each section and class grades against</h3>
    {{--
      A class's own override beats its section's own scale, which beats
      the school wide default (real bug found live on greenfield,
      2026-07-24: "i am viewing a report card in sss and it is showing
      grade as excellent, good etc which is not what we have under the
      grading structure for the section... sss falls under secondary
      school"). JSS and SSS both sit under one shared "Secondary"
      section, so a section level choice alone cannot tell WAEC graded
      SSS apart from NECO/BECE graded JSS, only a per class override can.
    --}}
    <p class="muted" style="margin-bottom:.8rem">A section left on "School default" uses whichever scale above is marked Default. A class left on "Section default" uses its own section's choice instead. Report cards (Grade Key, and any overall Grade shown on the summary) always use the scale for the student's own class first, then their section, then the school default.</p>
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Section / Class</th><th>Grading scale</th></tr></thead>
      <tbody>
        @foreach($sections as $section)
          <tr>
            <td><strong>{{ $section->name }}</strong></td>
            <td>
              @if($canManage)
                <form method="POST" action="{{ route('school.examinations.grading.sections.assign', ['school' => $school->slug, 'sectionId' => $section->id]) }}">
                  @csrf
                  <select name="grading_scale_id" onchange="this.form.submit()">
                    <option value="">School default</option>
                    @foreach($scales as $scale)
                      <option value="{{ $scale->id }}" @selected($section->grading_scale_id === $scale->id)>{{ $scale->name }}</option>
                    @endforeach
                  </select>
                </form>
              @else
                {{ $section->gradingScale?->name ?? 'School default' }}
              @endif
            </td>
          </tr>
          @foreach($section->classes as $class)
            <tr>
              <td class="muted" style="padding-left:1.5rem">{{ $class->name }}</td>
              <td>
                @if($canManage)
                  <form method="POST" action="{{ route('school.examinations.grading.classes.assign', ['school' => $school->slug, 'classId' => $class->id]) }}">
                    @csrf
                    <select name="grading_scale_id" onchange="this.form.submit()">
                      <option value="">Section default</option>
                      @foreach($scales as $scale)
                        <option value="{{ $scale->id }}" @selected($class->grading_scale_id === $scale->id)>{{ $scale->name }}</option>
                      @endforeach
                    </select>
                  </form>
                @else
                  {{ $class->gradingScale?->name ?? 'Section default' }}
                @endif
              </td>
            </tr>
          @endforeach
        @endforeach
      </tbody>
    </table>
    </div>
  </div>
@endif

@if($canManage && $scales->isEmpty())
  <div class="card" style="margin-bottom:1.1rem">
    <h3 style="margin-bottom:.4rem">Load default grading scales</h3>
    <p class="muted" style="margin-bottom:.8rem">Nursery and Primary, Junior Secondary (NECO/BECE 5 point), and Senior Secondary (WAEC 9 point). Add, edit, or remove any of them afterwards.</p>
    <form method="POST" action="{{ route('school.examinations.grading.apply-defaults', ['school' => $school->slug]) }}">
      @csrf
      <button class="btn btn-primary btn-sm" type="submit">Load default scales</button>
    </form>
  </div>
@endif

@foreach($scales as $scale)
  <div class="card" style="margin-bottom:1.1rem">
    <div class="row-between" style="margin-bottom:.7rem;flex-wrap:wrap;gap:.5rem">
      <h3>{{ $scale->name }} @if($scale->is_default)<span class="badge badge-ok" style="margin-left:.4rem">Default</span>@endif</h3>
      @if($canManage)
        <div style="display:flex;gap:.5rem">
          @unless($scale->is_default)
            <form method="POST" action="{{ route('school.examinations.grading.make-default', ['school' => $school->slug, 'scaleId' => $scale->id]) }}">
              @csrf
              <button class="btn btn-ghost btn-sm" type="submit">Make default</button>
            </form>
          @endunless
          <form method="POST" action="{{ route('school.examinations.grading.destroy', ['school' => $school->slug, 'scaleId' => $scale->id]) }}" onsubmit="return confirm('Remove {{ $scale->name }}?')">
            @csrf @method('DELETE')
            <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove</button>
          </form>
        </div>
      @endif
    </div>

    @if($scale->bands->isEmpty())
      <p class="muted">No bands yet.</p>
    @else
      @if($canManage)
        <form id="bulkBandsForm{{ $scale->id }}" method="POST" action="{{ route('school.examinations.grading.bands.bulk-destroy', ['school' => $school->slug]) }}">@csrf</form>
      @endif
      <div style="overflow-x:auto">
      <table class="table-stack">
        <thead><tr>
          @if($canManage)<th style="width:2.2rem"><input type="checkbox" class="band-select-all" data-scale="{{ $scale->id }}" aria-label="Select all bands" /></th>@endif
          <th>Grade</th><th>Score range</th><th>Remark</th>@if($canManage)<th></th>@endif</tr></thead>
        <tbody>
          @foreach($scale->bands->sortByDesc('min_score') as $band)
            <tr>
              @if($canManage)<td><input type="checkbox" form="bulkBandsForm{{ $scale->id }}" class="band-row-check band-row-check-{{ $scale->id }}" name="band_ids[]" value="{{ $band->id }}" aria-label="Select {{ $band->grade }}" /></td>@endif
              <td data-label="Grade"><strong>{{ $band->grade }}</strong></td>
              <td class="muted" data-label="Score range">{{ rtrim(rtrim(number_format($band->min_score, 2), '0'), '.') }} to {{ rtrim(rtrim(number_format($band->max_score, 2), '0'), '.') }}</td>
              <td class="muted" data-label="Remark">{{ $band->remark ?: 'Not set' }}</td>
              @if($canManage)
                <td style="white-space:nowrap">
                  <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('edit-band-{{ $band->id }}').classList.toggle('show')">Edit</button>
                  <form method="POST" action="{{ route('school.examinations.grading.bands.destroy', ['school' => $school->slug, 'bandId' => $band->id]) }}" style="display:inline" onsubmit="return confirm('Remove the {{ $band->grade }} band?')">
                    @csrf @method('DELETE')
                    <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove</button>
                  </form>
                </td>
              @endif
            </tr>
            @if($canManage)
            <tr id="edit-band-{{ $band->id }}" class="component-edit-row">
              <td colspan="5">
                <form method="POST" action="{{ route('school.examinations.grading.bands.update', ['school' => $school->slug, 'bandId' => $band->id]) }}" style="display:flex;gap:.5rem;padding:.6rem 0;flex-wrap:wrap">
                  @csrf
                  <input name="grade" value="{{ $band->grade }}" placeholder="Grade" required style="max-width:6rem" />
                  <input name="min_score" type="number" step="0.01" min="0" max="100" value="{{ $band->min_score }}" placeholder="Min" required style="max-width:6rem" />
                  <input name="max_score" type="number" step="0.01" min="0" max="100" value="{{ $band->max_score }}" placeholder="Max" required style="max-width:6rem" />
                  <input name="remark" value="{{ $band->remark }}" placeholder="Remark" style="flex:1;min-width:8rem" />
                  <button class="btn btn-primary btn-sm" type="submit">Save</button>
                </form>
              </td>
            </tr>
            @endif
          @endforeach
        </tbody>
      </table>
      </div>

      @if($canManage)
        <div class="row-between" style="margin-top:.6rem;flex-wrap:wrap;gap:.6rem">
          <span class="muted" style="font-size:.85rem">Tick any number of bands above, then remove them all at once.</span>
          <button form="bulkBandsForm{{ $scale->id }}" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" onclick="return confirm('Remove the selected bands?')">Remove selected</button>
        </div>
        <script>
          (function(){
            var scaleId = '{{ $scale->id }}';
            var all = document.querySelector('.band-select-all[data-scale="'+scaleId+'"]');
            var boxes = document.querySelectorAll('.band-row-check-'+scaleId);
            all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
            document.getElementById('bulkBandsForm'+scaleId)?.addEventListener('submit', function(e){
              var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
              if (! anyChecked) { e.preventDefault(); alert('Tick at least one band first.'); }
            });
          })();
        </script>
      @endif
    @endif

    @if($canManage)
      <form method="POST" action="{{ route('school.examinations.grading.bands.store', ['school' => $school->slug, 'scaleId' => $scale->id]) }}" style="display:flex;gap:.5rem;margin-top:1rem;flex-wrap:wrap">
        @csrf
        <input name="grade" placeholder="e.g. A1" required style="max-width:6rem" />
        <input name="min_score" type="number" step="0.01" min="0" max="100" placeholder="Min" required style="max-width:6rem" />
        <input name="max_score" type="number" step="0.01" min="0" max="100" placeholder="Max" required style="max-width:6rem" />
        <input name="remark" placeholder="Remark, e.g. Excellent" style="flex:1;min-width:8rem" />
        <button class="btn btn-primary btn-sm" type="submit">Add band</button>
      </form>
    @endif
  </div>
@endforeach

@if($canManage)
  <div class="card">
    <h3 style="margin-bottom:.8rem">Add a scale</h3>
    <form method="POST" action="{{ route('school.examinations.grading.store', ['school' => $school->slug]) }}" style="display:flex;gap:.5rem;align-items:flex-end">
      @csrf
      <div class="field" style="margin-bottom:0;flex:1">
        <label for="scale_name">Scale name</label>
        <input id="scale_name" name="name" placeholder="e.g. Senior Secondary Scale" required />
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Add scale</button>
    </form>
  </div>
@endif
@endsection
