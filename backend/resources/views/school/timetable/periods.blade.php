@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Timetable Periods'])
@section('title', 'Timetable Periods')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Timetable Periods</h1>
    <p class="subtitle">The slots every class's weekly timetable is built from. Set these up once, then build each class's grid.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.timetable.index', ['school' => $school->slug]) }}">Back to Timetable</a>
</div>

@if($sections->isNotEmpty())
  <div class="card" style="margin-bottom:1.1rem">
    <form method="GET" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
      <div class="field" style="margin:0;min-width:16rem">
        <label for="section_id">Catalog</label>
        <select id="section_id" name="section_id" onchange="this.form.submit()">
          <option value="" @selected(! $sectionId)>Shared / Default (every section without its own)</option>
          @foreach($sections as $section)
            <option value="{{ $section->id }}" @selected($sectionId === $section->id)>{{ $section->name }}</option>
          @endforeach
        </select>
      </div>
    </form>
    <p class="muted" style="margin-top:.6rem;font-size:.85rem">
      A school's Nursery/Primary periods often run a different length than Secondary's. Every section uses the shared/default catalog until it has periods of its own.
    </p>
  </div>
@endif

@if($sectionId && ! $hasOwnPeriods)
  {{-- Previewing the shared catalog for a section that hasn't customized
       yet: read only, no Edit/Remove, so nothing an admin sees here can
       silently vanish just from clicking Edit on an inherited row. --}}
  <div class="card" style="margin-bottom:1.1rem">
    <h3 style="margin-bottom:.4rem">Following the shared default catalog</h3>
    <p class="muted" style="margin-bottom:.8rem">This section has no periods of its own yet, so it uses the shared catalog shown below. Customize it to give this section its own independent times.</p>
    <form method="POST" action="{{ route('school.timetable.periods.fork-section', ['school' => $school->slug]) }}">
      @csrf
      <input type="hidden" name="section_id" value="{{ $sectionId }}" />
      <button class="btn btn-primary btn-sm" type="submit">Customize periods for this section</button>
    </form>
  </div>

  <div class="card">
    @if($periods->isEmpty())
      <p class="muted">No periods yet.</p>
    @else
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Name</th><th>Time</th><th>Type</th></tr></thead>
        <tbody>
          @foreach($periods as $period)
            <tr>
              <td><strong>{{ $period->name }}</strong></td>
              <td class="muted">{{ $period->timeLabel() }}</td>
              <td>{{ $period->is_teaching_period ? 'Teaching' : 'Break' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    @endif
  </div>
  @php($showEditableCatalog = false)
@else
  @php($showEditableCatalog = true)
@endif

@if($showEditableCatalog)
@if($sectionId && $hasOwnPeriods)
  <div class="card" style="margin-bottom:1.1rem">
    <div class="row-between" style="flex-wrap:wrap;gap:.6rem">
      <p class="muted" style="margin:0">This section has its own period catalog, independent of the shared default.</p>
      <form method="POST" action="{{ route('school.timetable.periods.reset-section', ['school' => $school->slug]) }}" onsubmit="return confirm('Delete this section\'s own periods and follow the shared default catalog again?')">
        @csrf
        <input type="hidden" name="section_id" value="{{ $sectionId }}" />
        <button class="btn btn-ghost btn-sm" type="submit">Follow the shared default instead</button>
      </form>
    </div>
  </div>
@endif

@if($periods->isEmpty())
  <div class="card" style="margin-bottom:1.1rem">
    <h3 style="margin-bottom:.4rem">Load a default day</h3>
    <p class="muted" style="margin-bottom:.8rem">8 periods with a mid morning break and lunch, 8:00am to 2:20pm. You can add, remove, rename, or retime anything afterwards.</p>
    <form method="POST" action="{{ route('school.timetable.periods.apply-defaults', ['school' => $school->slug]) }}">
      @csrf
      <input type="hidden" name="section_id" value="{{ $sectionId }}" />
      <button class="btn btn-primary btn-sm" type="submit">Load default day</button>
    </form>
  </div>
@endif

<div class="card">
  @if($periods->isEmpty())
    <p class="muted">No periods yet.</p>
  @else
    <form id="bulkPeriodsForm" method="POST" action="{{ route('school.timetable.periods.bulk-destroy', ['school' => $school->slug]) }}"></form>
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        <th style="width:2.2rem"><input type="checkbox" id="period-select-all" aria-label="Select all periods" /></th>
        <th>Name</th><th>Time</th><th>Type</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($periods as $period)
          <tr>
            <td><input type="checkbox" form="bulkPeriodsForm" class="period-row-check" name="period_ids[]" value="{{ $period->id }}" aria-label="Select {{ $period->name }}" /></td>
            <td><strong>{{ $period->name }}</strong></td>
            <td class="muted">{{ $period->timeLabel() }}</td>
            <td>{{ $period->is_teaching_period ? 'Teaching' : 'Break' }}</td>
            <td style="white-space:nowrap">
              <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('edit-{{ $period->id }}').classList.toggle('show')">Edit</button>
              <form method="POST" action="{{ route('school.timetable.periods.destroy', ['school' => $school->slug, 'periodId' => $period->id]) }}" style="display:inline" onsubmit="return confirm('Remove {{ $period->name }}? Any class assignments in this slot are removed too.')">
                @csrf @method('DELETE')
                <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove</button>
              </form>
            </td>
          </tr>
          <tr id="edit-{{ $period->id }}" class="period-edit-row">
            <td colspan="5">
              <form method="POST" action="{{ route('school.timetable.periods.update', ['school' => $school->slug, 'periodId' => $period->id]) }}" style="display:flex;gap:.5rem;padding:.6rem 0;flex-wrap:wrap;align-items:flex-end">
                @csrf
                <input name="name" value="{{ $period->name }}" required style="flex:1;min-width:8rem" />
                <div class="field" style="margin:0">
                  <label style="font-size:.72rem">Starts</label>
                  <input type="time" name="starts_at" value="{{ substr($period->starts_at, 0, 5) }}" required />
                </div>
                <div class="field" style="margin:0">
                  <label style="font-size:.72rem">Ends</label>
                  <input type="time" name="ends_at" value="{{ substr($period->ends_at, 0, 5) }}" required />
                </div>
                <label style="display:flex;align-items:center;gap:.4rem;font-weight:400">
                  <input type="checkbox" name="is_teaching_period" value="1" @checked($period->is_teaching_period) />
                  <span>Teaching period</span>
                </label>
                <button class="btn btn-primary btn-sm" type="submit">Save</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    <div class="row-between" style="margin-top:.6rem;flex-wrap:wrap;gap:.6rem">
      <span class="muted" style="font-size:.85rem">Tick any number of periods above, then remove them all at once.</span>
      <button form="bulkPeriodsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" onclick="return confirm('Remove the selected periods? Any class assignments in those slots are removed too.')">Remove selected</button>
    </div>
    <script>
      (function(){
        var all = document.getElementById('period-select-all');
        var boxes = document.querySelectorAll('.period-row-check');
        all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
        document.getElementById('bulkPeriodsForm')?.addEventListener('submit', function(e){
          var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
          if (! anyChecked) { e.preventDefault(); alert('Tick at least one period first.'); }
        });
      })();
    </script>
  @endif

  <form method="POST" action="{{ route('school.timetable.periods.store', ['school' => $school->slug]) }}" style="display:flex;gap:.5rem;margin-top:1rem;flex-wrap:wrap;align-items:flex-end">
    @csrf
    <input type="hidden" name="section_id" value="{{ $sectionId }}" />
    <input name="name" placeholder="e.g. Period 9" required style="flex:1;min-width:8rem" />
    <div class="field" style="margin:0">
      <label style="font-size:.72rem">Starts</label>
      <input type="time" name="starts_at" required />
    </div>
    <div class="field" style="margin:0">
      <label style="font-size:.72rem">Ends</label>
      <input type="time" name="ends_at" required />
    </div>
    <label style="display:flex;align-items:center;gap:.4rem;font-weight:400">
      <input type="checkbox" name="is_teaching_period" value="1" checked />
      <span>Teaching period</span>
    </label>
    <button class="btn btn-primary btn-sm" type="submit">Add</button>
  </form>
</div>
@endif

<style>
  .period-edit-row{display:none}
  .period-edit-row.show{display:table-row}
</style>
@endsection
