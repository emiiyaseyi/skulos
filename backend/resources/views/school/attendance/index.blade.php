@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Attendance'])
@section('title', 'Attendance')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Attendance</h1>
    <p class="subtitle">Pick a class and a date, then mark or review the register.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.attendance.dashboard', ['school' => $school->slug]) }}">Dashboard</a>
    @if(app(\App\Services\Rbac\Rbac::class)->check(auth()->user(), $school, 'staff.view'))
      <a class="btn btn-ghost btn-sm" href="{{ route('school.attendance.staff', ['school' => $school->slug]) }}">Staff attendance</a>
    @endif
  </div>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <form method="GET" action="{{ route('school.attendance.index', ['school' => $school->slug]) }}" style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap">
    <div class="field" style="margin:0">
      <label for="class_arm_id">Class</label>
      <select id="class_arm_id" name="class_arm_id" onchange="this.form.requestSubmit()">
        <option value="">Choose a class</option>
        @foreach($classArms as $arm)
          <option value="{{ $arm->id }}" @selected($selectedArm && $selectedArm->id === $arm->id)>{{ $arm->label() }}</option>
        @endforeach
      </select>
    </div>
    <div class="field" style="margin:0">
      <label for="date">Date</label>
      <input id="date" name="date" type="date" value="{{ $date }}" max="{{ now()->toDateString() }}" onchange="this.form.requestSubmit()" />
      <p id="nonSchoolDateWarning" class="muted" style="color:var(--bad);margin:.35rem 0 0;display:none"></p>
    </div>
  </form>
</div>

@if($classArms->isEmpty())
  <div class="card"><p class="muted">You are not assigned to any class yet.</p></div>
@elseif(! $selectedArm)
  <div class="card"><p class="muted">Choose a class above to see or take its register.</p></div>
@else
  <div class="grid" style="grid-template-columns:repeat(4, 1fr);gap:1rem;margin-bottom:1.25rem">
    @foreach($statuses as $status)
      <div class="card" style="padding:1rem">
        <div class="muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.04em">{{ ucfirst($status) }}</div>
        <div style="font-size:1.6rem;font-weight:700">{{ $summary[$status] ?? 0 }}</div>
      </div>
    @endforeach
  </div>

  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem;flex-wrap:wrap;gap:.6rem">
      <h3>{{ $selectedArm->label() }} &middot; {{ \Illuminate\Support\Carbon::parse($date)->format('l, j M Y') }}</h3>
      @if($canMark)
        <button type="button" class="btn btn-ghost btn-sm" onclick="skulosMarkAll('present')">Mark all present</button>
      @endif
    </div>

    @if($students->isEmpty())
      <p class="muted">No active students in this class yet.</p>
    @else
      <form method="POST" action="{{ route('school.attendance.mark', ['school' => $school->slug]) }}" id="attendanceForm">
        @csrf
        <input type="hidden" name="class_arm_id" value="{{ $selectedArm->id }}" />
        <input type="hidden" name="date" value="{{ $date }}" />
        <div style="overflow-x:auto">
        <table>
          <thead><tr><th>Admission No.</th><th>Name</th><th>Status</th></tr></thead>
          <tbody>
            @foreach($students as $student)
              <tr>
                <td class="muted" style="font-variant-numeric:tabular-nums">{{ $student->admission_number }}</td>
                <td><strong>{{ $student->fullName() }}</strong></td>
                <td>
                  @if($canMark)
                    <select name="marks[{{ $student->id }}]" class="attendance-status">
                      @foreach($statuses as $status)
                        <option value="{{ $status }}" @selected(($existingMarks[$student->id] ?? 'present') === $status)>{{ ucfirst($status) }}</option>
                      @endforeach
                    </select>
                  @else
                    {{ ucfirst($existingMarks[$student->id] ?? 'Not marked yet') }}
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
        </div>
        @if($canMark)
          <button class="btn btn-primary" type="submit" style="margin-top:1.2rem">Save register</button>
        @endif
      </form>
    @endif
  </div>

  @if($canMark)
    <div class="card" style="margin-top:1.25rem">
      <h3 style="margin-bottom:.3rem">Bulk attendance for a date range</h3>
      <p class="muted" style="font-size:.85rem;margin-bottom:.8rem">Download a spreadsheet with every student and one column per date, fill it in, then upload it back to populate the whole range at once. Weekends and holidays are left out automatically.</p>
      <form method="GET" action="{{ route('school.attendance.bulk-template', ['school' => $school->slug]) }}" style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1.2rem">
        <input type="hidden" name="class_arm_id" value="{{ $selectedArm->id }}" />
        <div class="field" style="margin:0">
          <label for="bulk_from">From</label>
          <input id="bulk_from" name="from" type="date" required max="{{ now()->toDateString() }}" />
        </div>
        <div class="field" style="margin:0">
          <label for="bulk_to">To</label>
          <input id="bulk_to" name="to" type="date" required max="{{ now()->toDateString() }}" />
        </div>
        <button class="btn btn-ghost btn-sm" type="submit">Download template</button>
      </form>
      <form method="POST" action="{{ route('school.attendance.bulk-upload', ['school' => $school->slug]) }}" enctype="multipart/form-data" style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <input type="hidden" name="class_arm_id" value="{{ $selectedArm->id }}" />
        <div class="field" style="margin:0">
          <label for="bulk_file">Upload filled template</label>
          <input id="bulk_file" name="file" type="file" accept=".csv,text/csv" required />
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Upload</button>
      </form>
    </div>
  @endif
@endif

<script>
(function () {
  var nonSchoolDates = @json($nonSchoolDates);
  var dateInput = document.getElementById('date');
  var warning = document.getElementById('nonSchoolDateWarning');
  var saveBtn = document.querySelector('#attendanceForm button[type="submit"]');
  var markAllBtn = document.querySelector('[onclick^="skulosMarkAll"]');

  function checkDate() {
    var reason = nonSchoolDates[dateInput.value];
    if (reason) {
      warning.textContent = 'No school on this date: ' + reason + '. Attendance cannot be saved.';
      warning.style.display = 'block';
      if (saveBtn) saveBtn.disabled = true;
      if (markAllBtn) markAllBtn.disabled = true;
    } else {
      warning.style.display = 'none';
      if (saveBtn) saveBtn.disabled = false;
      if (markAllBtn) markAllBtn.disabled = false;
    }
  }

  if (dateInput) {
    dateInput.addEventListener('change', checkDate);
    checkDate();
  }
})();
</script>

@if($canMark)
<script>
function skulosMarkAll(status) {
  document.querySelectorAll('#attendanceForm .attendance-status').forEach(function (select) {
    select.value = status;
  });
}
</script>
@endif
@endsection
