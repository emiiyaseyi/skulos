@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Examination Timetable'])
@section('title', 'Examination Timetable')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Examination Timetable</h1>
    <p class="subtitle">Which subject a section or class sits, for each paper, on each exam date.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.timetable.exam.papers', ['school' => $school->slug]) }}">Exam day structure</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.timetable.index', ['school' => $school->slug]) }}">Back to Timetable</a>
  </div>
</div>

@if($teachingPapers->isEmpty())
  <div class="card"><p class="muted">Set up the exam day structure first (papers and breaks with their durations), then come back here to assign subjects.</p></div>
@else

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    <div class="field" style="margin:0">
      <label for="scope_type">Scope</label>
      <select id="scope_type" name="scope_type">
        <option value="section" @selected($scopeType === 'section')>A whole section</option>
        <option value="class" @selected($scopeType === 'class')>One specific class</option>
      </select>
    </div>
    <div class="field" style="margin:0;min-width:12rem">
      <label for="scope_id">Which one</label>
      <select id="scope_id" name="scope_id">
        <optgroup label="Sections">
          @foreach($scopeOptions['sections'] as $section)
            <option value="{{ $section->id }}" data-type="section" @selected($scopeType === 'section' && $scopeId === $section->id)>{{ $section->name }}</option>
          @endforeach
        </optgroup>
        <optgroup label="Classes">
          @foreach($scopeOptions['classes'] as $class)
            <option value="{{ $class->id }}" data-type="class" @selected($scopeType === 'class' && $scopeId === $class->id)>{{ $class->section?->name }} &middot; {{ $class->name }}</option>
          @endforeach
        </optgroup>
      </select>
    </div>
    <div class="field" style="margin:0">
      <label for="start_date">First exam date</label>
      <input id="start_date" type="date" name="start_date" value="{{ $startDate }}" required />
    </div>
    <div class="field" style="margin:0;width:8rem">
      <label for="days">Exam days</label>
      <input id="days" type="number" name="days" value="{{ $days }}" min="1" max="30" />
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Show</button>
  </form>
</div>

@if(! empty($dates))
  <div class="card" style="margin-bottom:1.1rem">
    <div class="row-between" style="margin-bottom:.7rem;flex-wrap:wrap;gap:.6rem">
      <h3 style="margin:0">Upload a filled template instead</h3>
      <a class="btn btn-ghost btn-sm" href="{{ route('school.timetable.exam.template', ['school' => $school->slug, 'scope_type' => $scopeType, 'scope_id' => $scopeId, 'start_date' => $startDate, 'days' => $days]) }}">Download blank template</a>
    </div>
    <form method="POST" action="{{ route('school.timetable.exam.upload', ['school' => $school->slug]) }}" enctype="multipart/form-data" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
      @csrf
      <input type="hidden" name="scope_type" value="{{ $scopeType }}" />
      <input type="hidden" name="scope_id" value="{{ $scopeId }}" />
      <input type="file" name="file" accept=".csv" required />
      <button class="btn btn-ghost btn-sm" type="submit">Upload</button>
    </form>
  </div>

  <div class="card">
    <form method="POST" action="{{ route('school.timetable.exam.save', ['school' => $school->slug]) }}">
      @csrf
      <input type="hidden" name="scope_type" value="{{ $scopeType }}" />
      <input type="hidden" name="scope_id" value="{{ $scopeId }}" />
      <div style="overflow-x:auto">
      <table>
        <thead><tr>
          <th>Date</th>
          @foreach($teachingPapers as $paper)
            <th>{{ $paper->name }}<div class="muted" style="font-weight:400;font-size:.72rem">{{ $paper->timeLabel() }}</div></th>
          @endforeach
        </tr></thead>
        <tbody>
          @foreach($dates as $date)
            <tr>
              <td class="muted" style="white-space:nowrap">{{ \Illuminate\Support\Carbon::parse($date)->format('D, d M Y') }}</td>
              @foreach($teachingPapers as $paper)
                @php($existing = $entries[$date.':'.$paper->id]->subject_id ?? null)
                <td>
                  <select name="rows[{{ $date }}][{{ $paper->id }}]">
                    <option value="">Not set</option>
                    @foreach($subjects as $subject)
                      <option value="{{ $subject->id }}" @selected($existing === $subject->id)>{{ $subject->name }}</option>
                    @endforeach
                  </select>
                </td>
              @endforeach
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
      <button class="btn btn-primary btn-sm" type="submit" style="margin-top:1rem">Save examination timetable</button>
    </form>
  </div>
@endif
@endif

<script>
  (function(){
    var scopeType = document.getElementById('scope_type');
    var scopeId = document.getElementById('scope_id');
    function syncOptions(){
      Array.prototype.forEach.call(scopeId.options, function(opt){
        if (! opt.dataset.type) return;
        opt.hidden = opt.dataset.type !== scopeType.value;
      });
    }
    scopeType.addEventListener('change', syncOptions);
    syncOptions();
  })();
</script>
@endsection
