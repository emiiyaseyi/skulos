@extends('layouts.app', ['portal' => 'admin'])
@section('title', $school->name)
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $school->name }}</h1>
    <p class="subtitle">{{ $school->slug }}.{{ config('app.domain','localhost') }}</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Back</a>
    <a class="btn btn-primary btn-sm" href="{{ $school->portalUrl() }}" target="_blank" rel="noopener">Open portal</a>
  </div>
</div>
<div class="card" style="margin-bottom:1.25rem">
  <table>
    <tbody>
      <tr><th>Status</th><td>{{ ucfirst($school->status) }}</td></tr>
      <tr><th>Database</th><td class="muted">{{ $school->database }}</td></tr>
      <tr><th>Contact email</th><td>{{ $school->email ?: 'Not set' }}</td></tr>
      <tr><th>Contact phone</th><td>{{ $school->phone ?: 'Not set' }}</td></tr>
      <tr><th>Provisioned</th><td>{{ $school->provisioned_at?->toDayDateTimeString() ?: 'No' }}</td></tr>
    </tbody>
  </table>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.8rem">Administrators</h3>
  <p class="muted" style="margin-bottom:.8rem">You can reset a school administrator's password, revoke their access without touching the school itself, or add another administrator.</p>
  @if($admins->isEmpty())
    <p class="muted">No administrator on record. Add one below.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Access</th><th></th></tr></thead>
      <tbody>
        @foreach($admins as $admin)
          <tr>
            <td><strong>{{ $admin->name }}</strong></td>
            <td class="muted">{{ $admin->email }}</td>
            <td><span class="badge {{ $admin->pivot->status === 'active' ? 'badge-ok' : 'badge-warn' }}">{{ ucfirst($admin->pivot->status) }}</span></td>
            <td>
              <div class="stack" style="flex-direction:row;gap:.4rem;flex-wrap:wrap">
                <form method="POST" action="{{ route('admin.schools.admins.reset-password', ['school' => $school->id, 'user' => $admin->id]) }}" onsubmit="return confirm('Reset the password for {{ $admin->email }}? A new one will be generated.')">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">Reset password</button>
                </form>
                <form method="POST" action="{{ route('admin.schools.admins.toggle-access', ['school' => $school->id, 'user' => $admin->id]) }}">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">{{ $admin->pivot->status === 'active' ? 'Revoke access' : 'Restore access' }}</button>
                </form>
              </div>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>

<div class="card" style="max-width:32rem">
  <h3 style="margin-bottom:.8rem">Add another administrator</h3>
  <form method="POST" action="{{ route('admin.schools.admins.store', ['school' => $school->id]) }}">
    @csrf
    <div class="field">
      <label for="a_name">Name</label>
      <input id="a_name" name="name" required />
    </div>
    <div class="field">
      <label for="a_email">Email</label>
      <input id="a_email" name="email" type="email" required />
    </div>
    <div class="field">
      <label for="a_password">Password</label>
      <input id="a_password" name="password" type="password" minlength="8" required />
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Add administrator</button>
  </form>
</div>
@endsection
