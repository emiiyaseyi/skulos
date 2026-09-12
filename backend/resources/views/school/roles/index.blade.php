@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'User & Role Management'])
@section('title', 'User & Role Management')

@push('head')
<style>
  .module-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.4rem .8rem}
  .module-check{display:flex;align-items:center;gap:.4rem;font-size:.82rem;font-weight:500}
  .module-check input{width:auto}
  .perm-section{margin-bottom:.5rem;border:1px solid var(--line);border-radius:10px;padding:.7rem .9rem}
  .perm-section summary{cursor:pointer;font-size:.86rem;font-weight:700;padding:.2rem 0;list-style:none}
  .perm-section summary::-webkit-details-marker{display:none}
  .perm-section summary::before{content:'\25B8';display:inline-block;margin-right:.5rem;transition:transform .15s}
  .perm-section[open] summary::before{transform:rotate(90deg)}
  .role-name-btn{background:none;border:none;padding:0;font:inherit;color:inherit;font-weight:700;cursor:pointer;text-decoration:underline;text-underline-offset:2px}
  .role-users-list{list-style:none;margin-top:.8rem;max-height:16rem;overflow-y:auto;text-align:left}
  .role-users-list li{padding:.4rem 0;border-bottom:1px solid var(--line)}
  .role-users-list li:last-child{border-bottom:none}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">User & Role Management</h1>
    <p class="subtitle">Create roles by combining permissions across modules, assign a role to a user, and grant extra access beyond a user's base role.</p>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.4rem">Create a role</h3>
  <p class="muted" style="margin-bottom:.8rem">Name it, then open a section below and tick every permission it should have. Select from as many sections as needed.</p>
  <form method="POST" action="{{ route('school.roles.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="field" style="max-width:22rem">
      <label for="role_name">Role name</label>
      <input id="role_name" name="name" required maxlength="80" placeholder="e.g. Admissions Officer" />
    </div>
    @include('school.roles._permission-sections', ['catalog' => $permissionCatalog, 'checked' => collect()])
    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:.8rem">Create role</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.8rem">Roles</h3>
  @if($roles->isEmpty())
    <p class="muted">No roles yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Role</th><th>Permissions</th><th>Users</th><th></th></tr></thead>
      <tbody>
        @foreach($roles as $role)
          <tr>
            <td data-label="Role">
              <button type="button" class="role-name-btn" onclick="skulosOpenRoleUsersFor({{ $role->id }}, {{ Js::from($role->name) }})">{{ $role->name }}</button>
              <div class="role-users-data" data-role-id="{{ $role->id }}" hidden>
                @forelse($usersByRoleId[$role->id] ?? [] as $name)
                  <span>{{ $name }}</span>
                @empty
                @endforelse
              </div>
            </td>
            <td class="muted" data-label="Permissions">{{ $role->permissions_count }}</td>
            <td class="muted" data-label="Users">
              <button type="button" class="role-name-btn" style="font-weight:400" onclick="skulosOpenRoleUsersFor({{ $role->id }}, {{ Js::from($role->name) }})">{{ $userCountsByRole[$role->id] ?? 0 }}</button>
            </td>
            <td>
              <details class="perm-section" style="border:none;padding:0">
                <summary class="btn btn-ghost btn-sm" style="display:inline-block;cursor:pointer">Edit permissions</summary>
                <div style="margin-top:.6rem;min-width:18rem">
                  <form method="POST" action="{{ route('school.roles.permissions.update', ['school' => $school->slug, 'roleId' => $role->id]) }}">
                    @csrf
                    @php($rolePermissionIds = $role->permissions->pluck('id'))
                    @include('school.roles._permission-sections', ['catalog' => $permissionCatalog, 'checked' => $rolePermissionIds])
                    <p class="hint" style="margin:.6rem 0">Ticked permissions are kept, unticked ones are removed. Anything this role has outside these sections is left untouched.</p>
                    <button class="btn btn-primary btn-sm" type="submit">Save "{{ $role->name }}"</button>
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
  <div class="row-between" style="flex-wrap:wrap;gap:.6rem;margin-bottom:.8rem">
    <h3 style="margin:0">Users</h3>
    <form method="GET" action="{{ route('school.roles.index', ['school' => $school->slug]) }}" style="display:flex;gap:.4rem">
      <input type="search" name="q" value="{{ $search }}" placeholder="Search by name or email" aria-label="Search users by name or email" style="min-width:14rem" />
      <button class="btn btn-ghost btn-sm" type="submit">Search</button>
      @if($search)
        <a class="btn btn-ghost btn-sm" href="{{ route('school.roles.index', ['school' => $school->slug]) }}">Clear</a>
      @endif
    </form>
  </div>
  @if($members->isEmpty())
    <p class="muted">{{ $search ? 'No users match "'.$search.'".' : 'No users yet.' }}</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Name</th><th>Role</th><th>Extra access</th></tr></thead>
      <tbody>
        @foreach($members as $row)
          <tr>
            <td data-label="Name"><strong>{{ $row['user']->name }}</strong><div class="muted">{{ $row['user']->email }}</div></td>
            <td data-label="Role">
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
            <td data-label="Extra access">
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
    <div style="margin-top:1rem">{{ $members->links() }}</div>
  @endif
</div>

{{-- Shared popup for "click a role, see who is on it" (user directive
     2026-07-16), reusing the same scrim/card pattern the idle timeout
     modal already established rather than a new one-off style. --}}
<div class="idle-scrim" id="roleUsersScrim" role="dialog" aria-modal="true" aria-labelledby="roleUsersTitle">
  <div class="idle-card" style="max-width:26rem;text-align:left">
    <h3 id="roleUsersTitle" style="text-align:center"></h3>
    <ul class="role-users-list" id="roleUsersList"></ul>
    <button type="button" class="btn btn-ghost btn-sm" style="margin-top:1rem;width:100%" onclick="document.getElementById('roleUsersScrim').classList.remove('show')">Close</button>
  </div>
</div>

<script>
function skulosOpenRoleUsersFor(roleId, roleName) {
  var dataEl = document.querySelector('.role-users-data[data-role-id="' + roleId + '"]');
  var names = dataEl ? Array.prototype.map.call(dataEl.querySelectorAll('span'), function (s) { return s.textContent; }) : [];
  var list = document.getElementById('roleUsersList');
  list.innerHTML = '';
  if (names.length === 0) {
    var li = document.createElement('li');
    li.textContent = 'No one is on this role yet.';
    li.className = 'muted';
    list.appendChild(li);
  } else {
    names.forEach(function (name) {
      var li = document.createElement('li');
      li.textContent = name;
      list.appendChild(li);
    });
  }
  document.getElementById('roleUsersTitle').textContent = roleName;
  document.getElementById('roleUsersScrim').classList.add('show');
}
document.getElementById('roleUsersScrim')?.addEventListener('click', function (e) {
  if (e.target === this) this.classList.remove('show');
});
</script>
@endsection
