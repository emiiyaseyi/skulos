@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $staffMember->fullName()])
@section('title', $staffMember->fullName())

@push('head')
<style>
  .module-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.4rem .8rem}
  .module-check{display:flex;align-items:center;gap:.4rem;font-size:.82rem;font-weight:500}
  .module-check input{width:auto}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $staffMember->fullName() }}</h1>
    <p class="subtitle">{{ $staffMember->staff_id }} &middot; {{ $staffMember->position }}</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    @if($canManage)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.staff.edit', ['school' => $school->slug, 'staffId' => $staffMember->id]) }}">Edit</a>
    @endif
    <a class="btn btn-ghost btn-sm" href="{{ route('school.staff.index', ['school' => $school->slug]) }}">Back to Staff</a>
  </div>
</div>

<div class="grid cols-2" style="align-items:start;margin-bottom:1.1rem">
  <div class="card">
    <h3 style="margin-bottom:.7rem">Profile</h3>
    <div style="overflow-x:auto">
    <table>
      <tbody>
        <tr><td class="muted">Status</td><td><span class="badge {{ $staffMember->status === 'active' ? 'badge-ok' : 'badge-warn' }}">{{ ucwords(str_replace('_', ' ', $staffMember->status)) }}</span></td></tr>
        <tr><td class="muted">Gender</td><td>{{ $staffMember->gender ?: 'Not set' }}</td></tr>
        <tr><td class="muted">Date of birth</td><td>{{ optional($staffMember->date_of_birth)->toFormattedDateString() ?: 'Not set' }}</td></tr>
        <tr><td class="muted">Email</td><td style="word-break:break-word">{{ $staffMember->email ?: 'Not set' }}</td></tr>
        <tr><td class="muted">Phone</td><td>{{ $staffMember->phone ?: 'Not set' }}</td></tr>
        <tr><td class="muted">Employed on</td><td>{{ optional($staffMember->employed_on)->toFormattedDateString() ?: 'Not set' }}</td></tr>
        <tr><td class="muted">Qualification</td><td>{{ $staffMember->qualification ?: 'Not set' }}</td></tr>
        <tr><td class="muted">Address</td><td>{{ $staffMember->address ?: 'Not set' }}</td></tr>
        @foreach($staffCustomFields as $customField)
          <tr>
            <td class="muted">{{ $customField->label }}</td>
            <td>
              @if($customField->type === 'checkbox')
                {{ ($staffCustomValues[$customField->field_key] ?? null) ? 'Yes' : 'No' }}
              @else
                {{ $staffCustomValues[$customField->field_key] ?? 'Not set' }}
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  </div>

  <div class="card">
    <h3 style="margin-bottom:.7rem">Login (Staff Portal)</h3>
    @if(! $login)
      {{-- Auto-provisioning now happens the moment a staff member is
           added (2026-09-11), so this only ever shows for a record that
           predates that change and has not been backfilled yet
           (php artisan accounts:backfill-logins) -- kept as a manual
           fallback, not the normal path anymore. --}}
      <p class="muted" style="margin-bottom:.8rem">This staff member has no login yet.</p>
      @if($canManage)
        <form method="POST" action="{{ route('school.staff.login.create', ['school' => $school->slug, 'staffId' => $staffMember->id]) }}">
          @csrf
          @if($staffIdentifier !== 'phone')
            <div class="field">
              <label for="login_email">Email</label>
              <input id="login_email" name="email" type="email" value="{{ $staffMember->email }}" {{ $staffIdentifier === 'email' ? 'required' : '' }} />
            </div>
          @endif
          @if($staffIdentifier !== 'email')
            <div class="field">
              <label for="login_phone">Phone</label>
              <input id="login_phone" name="phone" value="{{ $staffMember->phone }}" {{ $staffIdentifier === 'phone' ? 'required' : '' }} />
            </div>
          @endif
          <div class="field">
            <label for="login_role">Role</label>
            <select id="login_role" name="role" required>
              <option value="">Choose a role&hellip;</option>
              @foreach($roles as $role)
                <option value="{{ $role['slug'] }}">{{ $role['name'] }}</option>
              @endforeach
            </select>
          </div>
          <button class="btn btn-primary btn-sm" type="submit">Create login</button>
        </form>
      @endif
    @else
      @php($pivotStatus = $school->users()->whereKey($login->id)->first()?->pivot->status)
      {{-- Show what this staff member can actually sign in with, straight
           from their own profile fields (never the login's own User.email,
           which can silently hold a placeholder like "staff-2@staff...
           .skulos.local" for a phone-only account -- user report
           2026-09-11: "the platform should not create a dummy email
           address for them"). Both show together when both are on file,
           since either one alone signs them in (school's own
           parent/staff identifier setting decides which is REQUIRED at
           creation, not which ones WORK). --}}
      <p class="muted" style="margin-bottom:.2rem">Can sign in with:</p>
      <div style="margin-bottom:.6rem">
        @if($staffMember->email)
          <p style="margin:0"><strong style="color:var(--ink)">{{ $staffMember->email }}</strong></p>
        @endif
        @if($staffMember->phone)
          <p style="margin:0"><strong style="color:var(--ink)">{{ $staffMember->phone }}</strong></p>
        @endif
        @if(! $staffMember->email && ! $staffMember->phone)
          <p class="muted" style="margin:0">No email or phone on file yet.</p>
        @endif
      </div>
      <p class="muted" style="margin-bottom:.2rem">
        @if($login->must_change_password)
          Still using the default password (must be changed at next sign in).
        @elseif($login->password_changed_at)
          Password changed {{ $login->password_changed_at->toDayDateTimeString() }}.
        @else
          Password has been changed.
        @endif
      </p>
      <p class="muted" style="margin-bottom:.8rem">Access: <span class="badge {{ $pivotStatus === 'active' ? 'badge-ok' : 'badge-warn' }}">{{ ucfirst($pivotStatus) }}</span></p>
      @if($canManage)
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <form method="POST" action="{{ route('school.staff.login.reset-password', ['school' => $school->slug, 'staffId' => $staffMember->id]) }}">
            @csrf
            <button class="btn btn-ghost btn-sm" type="submit">Reset password</button>
          </form>
          <form method="POST" action="{{ route('school.staff.login.toggle-access', ['school' => $school->slug, 'staffId' => $staffMember->id]) }}">
            @csrf
            <button class="btn btn-ghost btn-sm" type="submit">{{ $pivotStatus === 'active' ? 'Revoke access' : 'Restore access' }}</button>
          </form>
        </div>
      @endif
    @endif
  </div>
</div>

@if($login && $canManage)
<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.4rem">Role &amp; extra access</h3>
  <p class="muted" style="margin-bottom:.8rem">Managed centrally now, not per staff member.</p>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.roles.index', ['school' => $school->slug]) }}">Open User &amp; Role Management</a>
</div>
@endif

<div class="grid cols-2" style="align-items:start;margin-bottom:1.1rem">
  <div class="card">
    <h3 style="margin-bottom:.7rem">Class teacher of</h3>
    @if($classTeacherOf->isEmpty() && $assistantOf->isEmpty())
      <p class="muted">Not assigned as a class or assistant teacher.</p>
    @else
      <ul class="rp-list" style="list-style:none;display:flex;flex-direction:column;gap:.4rem;padding:0">
        @foreach($classTeacherOf as $arm)
          <li>{{ $arm->label() }} <span class="badge">Class teacher</span></li>
        @endforeach
        @foreach($assistantOf as $arm)
          <li>{{ $arm->label() }} <span class="badge">Assistant teacher</span></li>
        @endforeach
      </ul>
    @endif
  </div>

  <div class="card">
    <h3 style="margin-bottom:.7rem">Subjects taught</h3>
    @if($staffMember->teachingAssignments->isEmpty())
      <p class="muted">No teaching assignments yet.</p>
    @else
      <ul class="rp-list" style="list-style:none;display:flex;flex-direction:column;gap:.4rem;padding:0">
        @foreach($staffMember->teachingAssignments as $assignment)
          <li>{{ $assignment->subject?->name }} <span class="muted">{{ $assignment->classArm?->label() }}</span></li>
        @endforeach
      </ul>
    @endif
  </div>
</div>
@endsection
