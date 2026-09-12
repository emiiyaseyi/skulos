@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Staff who are also Parents'])
@section('title', 'Staff who are also Parents')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Staff who are also Parents</h1>
    <p class="subtitle">Every staff member who is also a real guardian of a student at this school.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.staff.index', ['school' => $school->slug]) }}">Back to Staff</a>
</div>

<div class="card">
  @if($rows->isEmpty())
    <p class="muted">No staff member here is also a parent of a student.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Staff ID</th><th>Name</th><th>Position</th><th>Child(ren)</th><th></th></tr></thead>
      <tbody>
        @foreach($rows as $row)
          <tr>
            <td data-label="Staff ID" class="muted">{{ $row['staff']->staff_id }}</td>
            <td data-label="Name"><strong>{{ $row['staff']->fullName() }}</strong></td>
            <td class="muted" data-label="Position">{{ $row['staff']->position ?: '—' }}</td>
            <td data-label="Child(ren)">
              @foreach($row['children'] as $child)
                <div>{{ $child?->fullName() }}<span class="muted">@if($child?->currentClassArm) &middot; {{ $child->currentClassArm->label() }}@endif</span></div>
              @endforeach
            </td>
            <td><a class="btn btn-ghost btn-sm" href="{{ route('school.staff.show', ['school' => $school->slug, 'staffId' => $row['staff']->id]) }}">View</a></td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
