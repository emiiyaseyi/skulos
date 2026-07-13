@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Staff Attendance'])
@section('title', 'Staff Attendance')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Staff Attendance</h1>
    <p class="subtitle">Mark or review who is in today.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.attendance.index', ['school' => $school->slug]) }}">Student attendance</a>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <form method="GET" action="{{ route('school.attendance.staff', ['school' => $school->slug]) }}" style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap">
    <div class="field" style="margin:0">
      <label for="date">Date</label>
      <input id="date" name="date" type="date" value="{{ $date }}" max="{{ now()->toDateString() }}" onchange="this.form.requestSubmit()" />
    </div>
  </form>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem;flex-wrap:wrap;gap:.6rem">
    <h3>{{ \Illuminate\Support\Carbon::parse($date)->format('l, j M Y') }}</h3>
    @if($canMark)
      <button type="button" class="btn btn-ghost btn-sm" onclick="skulosMarkAllStaff('present')">Mark all present</button>
    @endif
  </div>

  @if($staff->isEmpty())
    <p class="muted">No active staff yet.</p>
  @else
    <form method="POST" action="{{ route('school.attendance.staff.mark', ['school' => $school->slug]) }}" id="staffAttendanceForm">
      @csrf
      <input type="hidden" name="date" value="{{ $date }}" />
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Staff No.</th><th>Name</th><th>Status</th></tr></thead>
        <tbody>
          @foreach($staff as $member)
            <tr>
              <td class="muted" style="font-variant-numeric:tabular-nums">{{ $member->staff_id }}</td>
              <td><strong>{{ $member->fullName() }}</strong></td>
              <td>
                @if($canMark)
                  <select name="marks[{{ $member->id }}]" class="attendance-status">
                    @foreach($statuses as $status)
                      <option value="{{ $status }}" @selected(($existingMarks[$member->id] ?? 'present') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                  </select>
                @else
                  {{ ucfirst($existingMarks[$member->id] ?? 'Not marked yet') }}
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
<script>
function skulosMarkAllStaff(status) {
  document.querySelectorAll('#staffAttendanceForm .attendance-status').forEach(function (select) {
    select.value = status;
  });
}
</script>
@endif
@endsection
