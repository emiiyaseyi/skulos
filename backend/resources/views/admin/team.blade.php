@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Platform Team'])
@section('title', 'Platform Team')
@section('heading', 'Platform Team')
@section('crumb', 'Everyone with full platform admin access')

@section('content')
<div class="card" style="margin-bottom:1.25rem;max-width:32rem">
  <h3 style="margin-bottom:.6rem">Add a platform admin</h3>
  <p class="muted" style="margin-bottom:.8rem">They'll have full access to every school, billing, and platform setting, the same as your own account. There are no lesser tiers yet, every platform admin can do everything.</p>
  <form method="POST" action="{{ route('admin.team.store') }}">
    @csrf
    <div class="field">
      <label for="name">Name</label>
      <input id="name" name="name" required maxlength="120" value="{{ old('name') }}" />
      @error('name')<p class="err">{{ $message }}</p>@enderror
    </div>
    <div class="field">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" required maxlength="150" value="{{ old('email') }}" />
      @error('email')<p class="err">{{ $message }}</p>@enderror
    </div>
    <div class="field">
      <label for="password">Temporary password</label>
      <input id="password" name="password" type="password" required minlength="8" />
      <p class="hint">They'll be asked to change this the first time they sign in.</p>
      @error('password')<p class="err">{{ $message }}</p>@enderror
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Add platform admin</button>
  </form>
</div>

<div class="card">
  <h3 style="margin-bottom:.8rem">Current platform admins</h3>
  <div style="overflow-x:auto">
  <table>
    <thead><tr><th>Name</th><th>Email</th><th></th></tr></thead>
    <tbody>
      @foreach($admins as $admin)
        <tr>
          <td><strong>{{ $admin->name }}</strong>{{ $admin->id === auth()->id() ? ' (you)' : '' }}</td>
          <td class="muted">{{ $admin->email }}</td>
          <td>
            @if($admin->id !== auth()->id())
              <form method="POST" action="{{ route('admin.team.revoke', ['user' => $admin->id]) }}" onsubmit="return confirm('Revoke platform admin access for {{ $admin->email }}?')">
                @csrf
                <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Revoke access</button>
              </form>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</div>
@endsection
