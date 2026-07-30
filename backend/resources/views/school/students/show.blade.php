@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $student->fullName()])
@section('title', $student->fullName())

@section('content')
<div class="page-head">
  <div style="display:flex;align-items:center;gap:.9rem">
    {{-- Shown on the right of the report card header when present (user
         request 2026-07-23/24), nothing here or there if never uploaded. --}}
    @if($student->passport_path)
      <img src="{{ \Illuminate\Support\Facades\Storage::url($student->passport_path) }}" alt="" style="width:3.4rem;height:3.4rem;border-radius:10px;object-fit:cover;border:1px solid var(--line);flex:none" />
    @endif
    <div>
      <h1 class="title">{{ $student->fullName() }}</h1>
      <p class="subtitle">{{ $student->admission_number }} &middot; {{ $student->currentClassArm?->label() ?? 'No class set' }} &middot; <span class="badge {{ $student->status === 'active' ? 'badge-ok' : 'badge-warn' }}">{{ ucfirst($student->status) }}</span></p>
      @if($canManage)
        <form method="POST" action="{{ route('school.students.photo.update', ['school' => $school->slug, 'studentId' => $student->id]) }}" enctype="multipart/form-data" style="margin-top:.3rem">
          @csrf
          <label class="btn btn-ghost btn-sm" style="cursor:pointer">
            {{ $student->passport_path ? 'Change photo' : 'Upload photo' }}
            <input type="file" name="photo" accept="image/*" style="display:none" onchange="this.form.requestSubmit()" />
          </label>
        </form>
      @endif
    </div>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    {{-- User request 2026-07-26: "Academic report should be removed
         from the student profile, it should have a stand alone submenu
         under student portal." Moved to its own page; this is just the
         way in from here. --}}
    <a class="btn btn-ghost btn-sm" href="{{ route('school.students.academic-report', ['school' => $school->slug, 'studentId' => $student->id]) }}">Academic report</a>
    @if($canViewTimetable)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.timetable.mine', ['school' => $school->slug, 'student_id' => $student->id]) }}">Timetable</a>
      <a class="btn btn-ghost btn-sm" href="{{ route('school.timetable.exam.mine', ['school' => $school->slug, 'student_id' => $student->id]) }}">Exam Timetable</a>
    @endif
    @if($isParent)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.students.profile-change.create', ['school' => $school->slug, 'studentId' => $student->id]) }}">Request changes</a>
    @endif
    @if($canManage)
      <a class="btn btn-primary btn-sm" href="{{ route('school.students.edit', ['school' => $school->slug, 'studentId' => $student->id]) }}">Edit</a>
    @endif
    <a class="btn btn-ghost btn-sm" href="{{ route('school.students', ['school' => $school->slug]) }}">Back to Students</a>
  </div>
</div>

@if($siblings->count() > 1)
  <div style="margin-bottom:1.25rem;max-width:16rem">
    <select aria-label="Switch to another child" onchange="if(this.value) location.href=this.value">
      @foreach($siblings as $sibling)
        <option value="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $sibling->id]) }}" @selected($sibling->id === $student->id)>{{ $sibling->fullName() }} &middot; {{ $sibling->currentClassArm?->label() ?? 'No class set' }}</option>
      @endforeach
    </select>
  </div>
@endif

<div class="grid cols-2" style="align-items:start;margin-bottom:1.25rem">
  <div class="card">
    <h3 style="margin-bottom:.8rem">Details</h3>
    <table>
      <tbody>
        <tr><th>Admission Number</th><td>{{ $student->admission_number }}</td></tr>
        <tr><th>Other Names</th><td>{{ $student->other_names ?: 'Not set' }}</td></tr>
        <tr><th>Gender</th><td>{{ $student->gender ? ucfirst($student->gender) : 'Not set' }}</td></tr>
        <tr><th>Date of Birth</th><td>{{ optional($student->date_of_birth)->toFormattedDateString() ?? 'Not set' }}</td></tr>
        <tr><th>Email</th><td>{{ $student->email ?: 'Not set' }}</td></tr>
        <tr><th>Parent Mobile</th><td>{{ $canSeeGuardianDetails ? ($student->parent_mobile ?: 'Not set') : 'Restricted' }}</td></tr>
        <tr><th>Class</th><td>{{ $student->currentClassArm?->label() ?? 'Not set' }}</td></tr>
        <tr><th>House</th><td>{{ $student->house?->name ?? 'Not set' }}</td></tr>
        <tr><th>State of Origin</th><td>{{ $student->state_of_origin ?: 'Not set' }}</td></tr>
        <tr><th>LGA</th><td>{{ $student->lga ?: 'Not set' }}</td></tr>
        <tr><th>Address</th><td>{{ $student->address ?: 'Not set' }}</td></tr>
        @foreach($studentCustomFields as $customField)
          <tr>
            <th>{{ $customField->label }}</th>
            <td>
              @if($customField->type === 'checkbox')
                {{ ($studentCustomValues[$customField->field_key] ?? null) ? 'Yes' : 'No' }}
              @else
                {{ $studentCustomValues[$customField->field_key] ?? 'Not set' }}
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="card">
    <h3 style="margin-bottom:.8rem">Guardians</h3>
    @if(! $canSeeGuardianDetails)
      <p class="muted" style="margin-bottom:0">Guardian details are restricted for your role. Ask the school admin if you need access.</p>
    @elseif($student->guardians->isEmpty())
      <p class="muted" style="margin-bottom:.8rem">No guardian on record yet.</p>
    @else
      <div style="overflow-x:auto;margin-bottom:.8rem">
      <table>
        <thead><tr><th>Name</th><th>Relationship</th><th>Phone</th><th>Email</th><th>Login (Parent Portal)</th>@if($canMessageGuardians)<th>Message</th>@endif @if($canManage)<th>Edit</th>@endif</tr></thead>
        <tbody>
          @foreach($student->guardians as $guardian)
            <tr>
              <td>
                <strong>{{ $guardian->name }}</strong>@if($guardian->is_primary)<span class="badge badge-ok" style="margin-left:.4rem">Primary</span>@endif
                @foreach($guardianCustomFields as $customField)
                  <div class="muted">{{ $customField->label }}: {{ ($guardianCustomValues[$guardian->id][$customField->field_key] ?? null) ?: 'Not set' }}</div>
                @endforeach
              </td>
              <td class="muted">{{ $guardian->relationship ?: 'Not set' }}</td>
              <td class="muted">{{ $guardian->phone ?: 'Not set' }}</td>
              <td class="muted">{{ $guardian->email ?: 'Not set' }}</td>
              <td>
                @if(! $guardian->user)
                  @if($canManageLogins)
                    <form method="POST" action="{{ route('school.students.guardians.login.create', ['school' => $school->slug, 'guardianId' => $guardian->id]) }}" style="display:flex;gap:.3rem;align-items:center">
                      @csrf
                      <input type="email" name="email" value="{{ $guardian->email }}" placeholder="Email for login" required style="max-width:11rem" />
                      <button class="btn btn-ghost btn-sm" type="submit">Create login</button>
                    </form>
                  @else
                    <span class="muted">No login</span>
                  @endif
                @else
                  @php($gPivotStatus = $school->users()->whereKey($guardian->user->id)->first()?->pivot->status)
                  <span class="badge {{ $gPivotStatus === 'active' ? 'badge-ok' : 'badge-warn' }}" style="margin-bottom:.3rem;display:inline-block">{{ ucfirst($gPivotStatus) }}</span>
                  @if($canManage)
                    <div style="display:flex;gap:.4rem;margin-top:.3rem">
                      <form method="POST" action="{{ route('school.students.guardians.login.reset-password', ['school' => $school->slug, 'guardianId' => $guardian->id]) }}">
                        @csrf
                        <button class="btn btn-ghost btn-sm" type="submit">Reset password</button>
                      </form>
                      <form method="POST" action="{{ route('school.students.guardians.login.toggle-access', ['school' => $school->slug, 'guardianId' => $guardian->id]) }}">
                        @csrf
                        <button class="btn btn-ghost btn-sm" type="submit">{{ $gPivotStatus === 'active' ? 'Revoke' : 'Restore' }}</button>
                      </form>
                    </div>
                  @endif
                @endif
              </td>
              @if($canMessageGuardians)
                <td>
                  @if($guardian->user && $teacherParentMessagingEnabled)
                    <a class="btn btn-ghost btn-sm" href="{{ route('school.chat.start', ['school' => $school->slug, 'student_id' => $student->id, 'user_id' => $guardian->user->id]) }}">Message</a>
                  @elseif($guardian->user)
                    <span class="muted">Messaging is off</span>
                  @else
                    <span class="muted">No login yet</span>
                  @endif
                </td>
              @endif
              @if($canManage)
                <td>
                  <details>
                    <summary class="btn btn-ghost btn-sm" style="display:inline-block;cursor:pointer">Edit</summary>
                    <div style="margin-top:.6rem;min-width:16rem">
                      <form method="POST" action="{{ route('school.students.guardians.update', ['school' => $school->slug, 'guardianId' => $guardian->id]) }}" style="margin-bottom:.5rem">
                        @csrf
                        <div class="field" style="margin-bottom:.4rem">
                          <label>Name</label>
                          <input name="name" value="{{ $guardian->name }}" required />
                        </div>
                        <div class="field" style="margin-bottom:.4rem">
                          <label>Relationship</label>
                          <input name="relationship" value="{{ $guardian->relationship }}" />
                        </div>
                        <div class="field" style="margin-bottom:.4rem">
                          <label>Email</label>
                          <input name="email" type="email" value="{{ $guardian->email }}" @required($parentIdentifier === 'email') />
                        </div>
                        <div class="field" style="margin-bottom:.4rem">
                          <label>Phone</label>
                          <input name="phone" value="{{ $guardian->phone }}" @required($parentIdentifier === 'phone') />
                        </div>
                        <label style="display:flex;gap:.4rem;align-items:center;margin-bottom:.6rem;font-weight:500">
                          <input type="checkbox" name="is_primary" value="1" @checked($guardian->is_primary) /> Primary guardian
                        </label>
                        @include('school.partials.custom-fields', ['customFields' => $guardianCustomFields, 'customValues' => $guardianCustomValues[$guardian->id] ?? [], 'idPrefix' => 'g'.$guardian->id])
                        <button class="btn btn-primary btn-sm" type="submit">Save</button>
                      </form>
                      <form method="POST" action="{{ route('school.students.guardians.destroy', ['school' => $school->slug, 'guardianId' => $guardian->id]) }}" onsubmit="return confirm('Remove {{ $guardian->name }} as a guardian? Their login, if any, is not deleted.')">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--bad)">Remove guardian</button>
                      </form>
                    </div>
                  </details>
                </td>
              @endif
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    @endif

    @if($teacherTargetsForFamily->isNotEmpty())
      <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--line)">
        <h4 style="margin-bottom:.6rem;font-size:.9rem">Message a teacher</h4>
        <div class="stack" style="flex-direction:row;flex-wrap:wrap;gap:.5rem">
          @foreach($teacherTargetsForFamily as $teacherUser)
            <a class="btn btn-ghost btn-sm" href="{{ route('school.chat.start', ['school' => $school->slug, 'student_id' => $student->id, 'user_id' => $teacherUser->id]) }}">Message {{ $teacherUser->name }}</a>
          @endforeach
        </div>
      </div>
    @endif

    @if($canManage)
      <form method="POST" action="{{ route('school.students.guardians.store', ['school' => $school->slug, 'studentId' => $student->id]) }}">
        @csrf
        <div class="grid cols-2">
          <div class="field">
            <label for="g_name">Name</label>
            <input id="g_name" name="name" required />
          </div>
          <div class="field">
            <label for="g_relationship">Relationship</label>
            <input id="g_relationship" name="relationship" placeholder="e.g. Father" />
          </div>
          <div class="field">
            <label for="g_phone">Phone{{ $parentIdentifier === 'phone' ? ' (required, this school\'s parent identifier)' : '' }}</label>
            <input id="g_phone" name="phone" @required($parentIdentifier === 'phone') />
          </div>
          <div class="field">
            <label for="g_email">Email{{ $parentIdentifier === 'email' ? ' (required, this school\'s parent identifier)' : '' }}</label>
            <input id="g_email" name="email" type="email" @required($parentIdentifier === 'email') />
          </div>
        </div>
        <label style="font-weight:500;display:flex;gap:.4rem;align-items:center;margin-bottom:.8rem">
          <input type="checkbox" name="is_primary" value="1" /> Primary guardian
        </label>
        @include('school.partials.custom-fields', ['customFields' => $guardianCustomFields, 'customValues' => [], 'idPrefix' => 'new'])
        <button class="btn btn-ghost btn-sm" type="submit">Add guardian</button>
      </form>
    @endif
  </div>
</div>

@if($canManage)
<div class="card" style="margin-bottom:1.25rem;max-width:32rem">
  <h3 style="margin-bottom:.4rem">Student Portal login</h3>
  <p class="muted" style="margin-bottom:.8rem">Lets {{ $student->first_name }} sign in directly to view their own report card.</p>
  @if(! $student->user)
    @if($canManageLogins)
      <form method="POST" action="{{ route('school.students.login.create', ['school' => $school->slug, 'studentId' => $student->id]) }}" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        @csrf
        <input type="email" name="email" value="{{ $student->email }}" placeholder="Email for login" required style="max-width:14rem" />
        <button class="btn btn-ghost btn-sm" type="submit">Create login</button>
      </form>
    @else
      <p class="muted">No login yet. Ask a school admin to create one.</p>
    @endif
  @else
    @php($sPivotStatus = $school->users()->whereKey($student->user->id)->first()?->pivot->status)
    <span class="badge {{ $sPivotStatus === 'active' ? 'badge-ok' : 'badge-warn' }}" style="margin-bottom:.5rem;display:inline-block">{{ ucfirst($sPivotStatus) }}</span>
    <div style="display:flex;gap:.4rem">
      <form method="POST" action="{{ route('school.students.login.reset-password', ['school' => $school->slug, 'studentId' => $student->id]) }}">
        @csrf
        <button class="btn btn-ghost btn-sm" type="submit">Reset password</button>
      </form>
      <form method="POST" action="{{ route('school.students.login.toggle-access', ['school' => $school->slug, 'studentId' => $student->id]) }}">
        @csrf
        <button class="btn btn-ghost btn-sm" type="submit">{{ $sPivotStatus === 'active' ? 'Revoke' : 'Restore' }}</button>
      </form>
    </div>
  @endif
</div>
@endif
@endsection
