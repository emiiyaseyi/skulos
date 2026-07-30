@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $house->name])
@section('title', $house->name)

@php($totalMembers = $house->students_count + $staffMembers->count() + $parentMembers->count())

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $house->name }}</h1>
    <p class="subtitle">{{ $totalMembers }} member(s): {{ $house->students_count }} student(s), {{ $staffMembers->count() }} teacher(s), {{ $parentMembers->count() }} parent(s). <span class="badge {{ $house->is_active ? 'badge-ok' : 'badge-warn' }}">{{ $house->is_active ? 'Active' : 'Retired' }}</span></p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.houses.download-members', ['school' => $school->slug, 'houseId' => $house->id]) }}">Download members</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.houses.index', ['school' => $school->slug]) }}">Back to Houses</a>
  </div>
</div>

@if($canManage)
<div class="card" style="margin-bottom:1.25rem;max-width:32rem">
  <h3 style="margin-bottom:.8rem">Rename or recolour</h3>
  <form method="POST" action="{{ route('school.houses.update', ['school' => $school->slug, 'houseId' => $house->id]) }}" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    @csrf
    <div class="field" style="margin-bottom:0;flex:1;min-width:10rem">
      <label for="h_name">Name</label>
      <input id="h_name" name="name" value="{{ $house->name }}" required />
    </div>
    <div class="field" style="margin-bottom:0;min-width:8rem">
      <label for="h_color">Colour (optional)</label>
      <input id="h_color" name="color" value="{{ $house->color }}" placeholder="e.g. Red" />
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>

  <div style="border-top:1px solid var(--line);margin-top:1.2rem;padding-top:1rem">
    <form method="POST" action="{{ route('school.houses.toggle-active', ['school' => $school->slug, 'houseId' => $house->id]) }}">
      @csrf
      <button class="btn btn-ghost btn-sm" type="submit">{{ $house->is_active ? 'Retire this house' : 'Reactivate this house' }}</button>
    </form>
    <p class="hint" style="margin-top:.4rem">{{ $house->is_active ? 'Retiring keeps its history, its current members stay assigned, but it drops out of every assignment picker.' : 'Reactivating makes it assignable again.' }}</p>
  </div>
</div>
@endif

<div class="grid cols-2" style="align-items:start;margin-bottom:1.25rem">
  <div class="card">
    <h3 style="margin-bottom:.4rem">Leadership</h3>
    <p class="muted" style="margin-bottom:.8rem">House Master and Assistant House Master, chosen from your teachers.</p>
    @if($canManage)
      <form method="POST" action="{{ route('school.houses.leadership', ['school' => $school->slug, 'houseId' => $house->id]) }}">
        @csrf
        <div class="field">
          <label for="h_master">House Master</label>
          <select id="h_master" name="housemaster_staff_id" onchange="this.form.requestSubmit()">
            <option value="">Not set</option>
            @foreach($staffOptions as $staff)
              <option value="{{ $staff->id }}" @selected($house->housemaster_staff_id === $staff->id)>{{ $staff->fullName() }}</option>
            @endforeach
          </select>
        </div>
        <div class="field" style="margin-bottom:0">
          <label for="h_assistant">Assistant House Master</label>
          <select id="h_assistant" name="assistant_housemaster_staff_id" onchange="this.form.requestSubmit()">
            <option value="">Not set</option>
            @foreach($staffOptions as $staff)
              <option value="{{ $staff->id }}" @selected($house->assistant_housemaster_staff_id === $staff->id)>{{ $staff->fullName() }}</option>
            @endforeach
          </select>
        </div>
      </form>
    @else
      <p><strong>House Master:</strong> {{ $house->housemaster?->fullName() ?? 'Not set' }}</p>
      <p><strong>Assistant:</strong> {{ $house->assistantHousemaster?->fullName() ?? 'Not set' }}</p>
    @endif
  </div>

  <div class="card">
    <h3 style="margin-bottom:.4rem">Sponsors</h3>
    <p class="muted" style="margin-bottom:.8rem">One or more sponsors, picked from parents or typed in if the sponsor is not a parent.</p>
    @if($house->sponsors->isEmpty())
      <p class="muted">No sponsors yet.</p>
    @else
      <ul class="stack" style="margin-bottom:1rem">
        @foreach($house->sponsors as $sponsor)
          <li class="row-between" style="gap:.6rem">
            <span>{{ $sponsor->displayName() }} @if($sponsor->guardian)<span class="muted">(parent)</span>@endif</span>
            @if($canManage)
              <form method="POST" action="{{ route('school.houses.sponsors.destroy', ['school' => $school->slug, 'houseId' => $house->id, 'sponsorId' => $sponsor->id]) }}">
                @csrf @method('DELETE')
                <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
              </form>
            @endif
          </li>
        @endforeach
      </ul>
    @endif
    @if($canManage)
      <form method="POST" action="{{ route('school.houses.sponsors.store', ['school' => $school->slug, 'houseId' => $house->id]) }}" style="border-top:1px solid var(--line);padding-top:.8rem">
        @csrf
        <div class="field">
          <label for="sp_guardian">Pick a parent</label>
          <select id="sp_guardian" name="student_guardian_id">
            <option value="">Not a parent, type a name instead</option>
            @foreach($guardianOptions as $guardian)
              <option value="{{ $guardian->id }}">{{ $guardian->name }} ({{ $guardian->student?->fullName() }}'s parent)</option>
            @endforeach
          </select>
        </div>
        <div class="field" style="margin-bottom:.8rem">
          <label for="sp_name">Or type a sponsor name</label>
          <input id="sp_name" name="name" placeholder="e.g. Mr. Adewale Okon" />
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Add sponsor</button>
      </form>
    @endif
  </div>
</div>

<div class="card">
  <h3 style="margin-bottom:.8rem">Members</h3>

  <details class="subject-disclosure" open style="margin-bottom:.8rem">
    <summary style="cursor:pointer;font-weight:700;padding:.3rem 0">Students ({{ $students->count() }})</summary>
    @if($students->isEmpty())
      <p class="muted">No students in this house yet.</p>
    @else
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Admission No.</th><th>Name</th><th>Class</th></tr></thead>
        <tbody>
          @foreach($students as $student)
            <tr>
              <td class="muted" style="font-variant-numeric:tabular-nums">{{ $student->admission_number }}</td>
              <td><strong>{{ $student->fullName() }}</strong></td>
              <td class="muted">{{ $student->currentClassArm?->label() ?? 'Not set' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    @endif
  </details>

  <details class="subject-disclosure" style="margin-bottom:.8rem">
    <summary style="cursor:pointer;font-weight:700;padding:.3rem 0">Teachers ({{ $staffMembers->count() }})</summary>
    @if($staffMembers->isEmpty())
      <p class="muted">No staff in this house yet.</p>
    @else
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Name</th><th>Position</th>@if($canManage)<th>House</th>@endif</tr></thead>
        <tbody>
          @foreach($staffMembers as $staff)
            <tr>
              <td><strong>{{ $staff->fullName() }}</strong></td>
              <td class="muted">{{ $staff->position ?? '' }}</td>
              @if($canManage)
                <td>
                  <form method="POST" action="{{ route('school.houses.members.staff', ['school' => $school->slug, 'staffId' => $staff->id]) }}">
                    @csrf
                    <select name="house_id" onchange="this.form.requestSubmit()">
                      <option value="">Not assigned</option>
                      @foreach($houseOptions as $option)
                        <option value="{{ $option->id }}" @selected($staff->house_id === $option->id)>{{ $option->name }}</option>
                      @endforeach
                    </select>
                  </form>
                </td>
              @endif
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    @endif
    @if($canManage)
      <form method="POST" action="{{ route('school.houses.members.staff', ['school' => $school->slug, 'staffId' => '__id__']) }}" class="add-member-form" data-house="{{ $house->id }}" style="display:flex;gap:.5rem;margin-top:.8rem;flex-wrap:wrap;align-items:flex-end">
        @csrf
        <input type="hidden" name="house_id" value="{{ $house->id }}" />
        <div class="field" style="margin-bottom:0">
          <label for="add_staff_pick">Add a staff member to this house</label>
          <select id="add_staff_pick" required>
            <option value="">Choose staff&hellip;</option>
            @foreach($staffOptions->whereNull('house_id') as $staff)
              <option value="{{ $staff->id }}">{{ $staff->fullName() }}</option>
            @endforeach
          </select>
        </div>
        <button class="btn btn-ghost btn-sm add-member-btn" data-picker="add_staff_pick" type="button">Add</button>
      </form>
    @endif
  </details>

  <details class="subject-disclosure">
    <summary style="cursor:pointer;font-weight:700;padding:.3rem 0">Parents ({{ $parentMembers->count() }})</summary>
    @if($parentMembers->isEmpty())
      <p class="muted">No parents in this house yet.</p>
    @else
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Name</th><th>Parent of</th>@if($canManage)<th>House</th>@endif</tr></thead>
        <tbody>
          @foreach($parentMembers as $guardian)
            <tr>
              <td><strong>{{ $guardian->name }}</strong></td>
              <td class="muted">{{ $guardian->student?->fullName() ?? 'Not set' }}</td>
              @if($canManage)
                <td>
                  <form method="POST" action="{{ route('school.houses.members.parent', ['school' => $school->slug, 'guardianId' => $guardian->id]) }}">
                    @csrf
                    <select name="house_id" onchange="this.form.requestSubmit()">
                      <option value="">Not assigned</option>
                      @foreach($houseOptions as $option)
                        <option value="{{ $option->id }}" @selected($guardian->house_id === $option->id)>{{ $option->name }}</option>
                      @endforeach
                    </select>
                  </form>
                </td>
              @endif
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    @endif
    @if($canManage)
      <form method="POST" action="{{ route('school.houses.members.parent', ['school' => $school->slug, 'guardianId' => '__id__']) }}" class="add-member-form" style="display:flex;gap:.5rem;margin-top:.8rem;flex-wrap:wrap;align-items:flex-end">
        @csrf
        <input type="hidden" name="house_id" value="{{ $house->id }}" />
        <div class="field" style="margin-bottom:0">
          <label for="add_parent_pick">Add a parent to this house</label>
          <select id="add_parent_pick" required>
            <option value="">Choose a parent&hellip;</option>
            @foreach($guardianOptions->whereNull('house_id') as $guardian)
              <option value="{{ $guardian->id }}">{{ $guardian->name }} ({{ $guardian->student?->fullName() }}'s parent)</option>
            @endforeach
          </select>
        </div>
        <button class="btn btn-ghost btn-sm add-member-btn" data-picker="add_parent_pick" type="button">Add</button>
      </form>
    @endif
  </details>
</div>

@if($canManage)
<script>
  (function(){
    document.querySelectorAll('.add-member-btn').forEach(function(btn){
      btn.addEventListener('click', function(){
        var form = btn.closest('form');
        var picker = document.getElementById(btn.getAttribute('data-picker'));
        if (! picker.value) { alert('Choose someone to add first.'); return; }
        form.action = form.action.replace('__id__', picker.value);
        form.submit();
      });
    });
  })();
</script>
@endif
@endsection
