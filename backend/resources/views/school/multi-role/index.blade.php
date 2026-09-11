@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Multi-role Accounts'])
@section('title', 'Multi-role Accounts')

@push('head')
<style>
  .role-badge-row{display:flex;flex-wrap:wrap;gap:.35rem}
  .role-badge-form{display:inline-flex}
  .role-badge-btn{display:inline-flex;align-items:center;gap:.35rem;background:var(--soft);border:1px solid var(--line);border-radius:999px;padding:.2rem .3rem .2rem .6rem;font-size:.78rem;font-weight:700;color:var(--ink);cursor:pointer}
  .role-badge-btn:hover{background:var(--bg)}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Multi-role Accounts</h1>
    <p class="subtitle">Let one account act as more than one role, e.g. a teacher who is also a parent, or an admin who is also a class teacher. Each person still switches between them from the button at the top of their own screen.</p>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if(session('error'))
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ session('error') }}</div>
@endif

<div class="card" style="margin-bottom:1.25rem;max-width:40rem">
  <h3 style="margin-bottom:.4rem">Grant an additional role</h3>
  <p class="muted" style="margin-bottom:.8rem">The person's existing role is untouched. If the role you pick needs a class or subject assignment (class teacher, subject teacher, ...) and they have no staff record yet, one is created for them automatically.</p>
  <form method="POST" action="{{ route('school.multi-role.grant', ['school' => $school->slug]) }}">
    @csrf
    <div class="grid cols-2">
      <div class="field">
        <label for="grant_user_id">Person</label>
        <select id="grant_user_id" name="user_id" required>
          <option value="">Choose a member&hellip;</option>
          @foreach($members as $member)
            <option value="{{ $member->id }}">{{ $member->name }} ({{ $member->primaryRole->name ?? 'No role' }})</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="grant_role_slug">Also grant</label>
        <select id="grant_role_slug" name="role_slug" required>
          <option value="">Choose a role&hellip;</option>
          @foreach($roles as $role)
            <option value="{{ $role['slug'] }}">{{ $role['name'] }}</option>
          @endforeach
        </select>
      </div>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Grant</button>
  </form>
</div>

<div class="card">
  <h3 style="margin-bottom:.4rem">Every school member</h3>
  <p class="muted" style="margin-bottom:.8rem">"Primary role" is their real, structural role. Anything shown as an extra badge is an additional role they can switch into.</p>
  @if($members->isEmpty())
    <p class="muted">No members yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Name</th><th>Primary role</th><th>Additional roles</th></tr></thead>
      <tbody>
        @foreach($members as $member)
          <tr>
            <td data-label="Name"><strong>{{ $member->name }}</strong></td>
            <td class="muted" data-label="Primary role">{{ $member->primaryRole->name ?? 'No role' }}</td>
            <td data-label="Additional roles">
              @if($member->additionalRoles->isEmpty())
                <span class="muted" style="font-size:.82rem">None</span>
              @else
                <div class="role-badge-row">
                  @foreach($member->additionalRoles as $grant)
                    <form method="POST" action="{{ route('school.multi-role.revoke', ['school' => $school->slug]) }}" class="role-badge-form" onsubmit="return confirm('Remove {{ $grant->role->name }} access from {{ $member->name }}?')">
                      @csrf
                      <input type="hidden" name="user_id" value="{{ $member->id }}">
                      <input type="hidden" name="role_id" value="{{ $grant->role_id }}">
                      <button type="submit" class="role-badge-btn" title="Remove this role">
                        {{ $grant->role->name ?? 'Unknown role' }}
                        <span aria-hidden="true">&times;</span>
                      </button>
                    </form>
                  @endforeach
                </div>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $members->links() }}</div>
  @endif
</div>
@endsection
