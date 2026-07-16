@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'User & Role Management'])
@section('title', 'User & Role Management')

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
    <h1 class="title">User & Role Management</h1>
    <p class="subtitle">Create roles by combining modules, assign a role to a user, and grant extra access beyond a user's base role.</p>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.4rem">Create a role</h3>
  <p class="muted" style="margin-bottom:.8rem">Name it, then tick every module it should have full access to.</p>
  <form method="POST" action="{{ route('school.roles.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="field" style="max-width:22rem">
      <label for="role_name">Role name</label>
      <input id="role_name" name="name" required maxlength="80" placeholder="e.g. Admissions Officer" />
    </div>
    <div class="module-grid" style="margin-bottom:.8rem">
      @foreach($moduleOptions as $code => $label)
        <label class="module-check"><input type="checkbox" name="modules[]" value="{{ $code }}" /> {{ $label }}</label>
      @endforeach
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Create role</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.8rem">Roles</h3>
  @if($roles->isEmpty())
    <p class="muted">No roles yet.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Role</th><th>Permissions</th><th>Users</th><th></th></tr></thead>
      <tbody>
        @foreach($roles as $role)
          <tr>
            <td><strong>{{ $role->name }}</strong></td>
            <td class="muted">{{ $role->permissions_count }}</td>
            <td class="muted">{{ $userCountsByRole[$role->id] ?? 0 }}</td>
            <td>
              <details>
                <summary class="btn btn-ghost btn-sm" style="display:inline-block;cursor:pointer">Add modules</summary>
                <div style="margin-top:.6rem;min-width:16rem">
                  <form method="POST" action="{{ route('school.roles.modules.add', ['school' => $school->slug, 'roleId' => $role->id]) }}">
                    @csrf
                    <div class="module-grid" style="margin-bottom:.6rem">
                      @foreach($moduleOptions as $code => $label)
                        <label class="module-check"><input type="checkbox" name="modules[]" value="{{ $code }}" /> {{ $label }}</label>
                      @endforeach
                    </div>
                    <p class="hint" style="margin-bottom:.6rem">Adds full access to whatever is ticked, on top of what this role already has. Nothing is removed here.</p>
                    <button class="btn btn-primary btn-sm" type="submit">Add to role</button>
                  </form>
                </div>
              </details>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>

<div class="card">
  <h3 style="margin-bottom:.8rem">Users</h3>
  @if($members->isEmpty())
    <p class="muted">No users yet.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Name</th><th>Role</th><th>Extra access</th></tr></thead>
      <tbody>
        @foreach($members as $row)
          <tr>
            <td><strong>{{ $row['user']->name }}</strong><div class="muted" style="font-size:.8rem">{{ $row['user']->email }}</div></td>
            <td>
              <form method="POST" action="{{ route('school.roles.users.assign', ['school' => $school->slug, 'userId' => $row['user']->id]) }}" style="display:flex;gap:.4rem;align-items:center">
                @csrf
                <select name="role_id">
                  @foreach($roles as $role)
                    <option value="{{ $role->id }}" @selected($row['role']?->id === $role->id)>{{ $role->name }}</option>
                  @endforeach
                </select>
                <button class="btn btn-ghost btn-sm" type="submit">Save</button>
              </form>
            </td>
            <td>
              <details>
                <summary class="btn btn-ghost btn-sm" style="display:inline-block;cursor:pointer">
                  Extra access
                  @if($row['extraModules']->isNotEmpty()) ({{ $row['extraModules']->count() }})@endif
                </summary>
                <div style="margin-top:.6rem;min-width:16rem">
                  <form method="POST" action="{{ route('school.roles.users.extra-access', ['school' => $school->slug, 'userId' => $row['user']->id]) }}">
                    @csrf
                    <div class="module-grid" style="margin-bottom:.6rem">
                      @foreach($moduleOptions as $code => $label)
                        <label class="module-check"><input type="checkbox" name="modules[]" value="{{ $code }}" @checked($row['extraModules']->contains($code)) /> {{ $label }}</label>
                      @endforeach
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">Save extra access</button>
                  </form>
                </div>
              </details>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
