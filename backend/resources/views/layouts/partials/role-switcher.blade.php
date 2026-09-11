{{--
  Top-bar role switcher (2026-09-11, multi-role accounts -- "a switch
  account button at the top to switch from one user role to another").
  Only renders when this user genuinely holds more than one role at this
  school: a normal single-role user (the overwhelming majority) sees
  zero new UI, no empty dropdown, no layout shift. Follows the exact
  <details>/<summary class="iconbtn"> shape notification-bell.blade.php
  already establishes as this app's own low-JS disclosure pattern -- no
  JS of its own beyond the shared outside-click-closes-it listener that
  partial's script already applies (extended in app.blade.php to also
  match this class).
--}}
@php
  $rsUser = auth()->user();
  $rsPrimaryRoleId = $school->users()->where('users.id', $rsUser->id)->value('school_user.role_id');
  $rsAdditional = \App\Models\UserAdditionalRole::with('role')
      ->where('school_id', $school->id)->where('user_id', $rsUser->id)->get()
      ->pluck('role')->filter()->values();
  $rsActiveRole = $rsUser->roleIn($school);
@endphp
@if($rsAdditional->isNotEmpty())
<details class="role-switch">
  <summary class="iconbtn" title="Switch role, currently {{ $rsActiveRole?->name }}" aria-label="Switch role, currently {{ $rsActiveRole?->name }}">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
  </summary>
  <div class="role-switch-panel">
    <div style="font-size:.85rem;font-weight:800;margin-bottom:.5rem">Switch role</div>
    @if($rsPrimaryRoleId)
      @php($rsPrimaryRole = \App\Models\Role::find($rsPrimaryRoleId))
      @if($rsPrimaryRole && $rsPrimaryRole->id !== $rsActiveRole?->id)
        <form method="POST" action="{{ route('session.switch-role', ['school' => $school->slug]) }}">
          @csrf
          <input type="hidden" name="role_id" value="{{ $rsPrimaryRole->id }}">
          <button type="submit" class="role-switch-option">{{ $rsPrimaryRole->name }} <span class="muted">(primary)</span></button>
        </form>
      @endif
    @endif
    @foreach($rsAdditional as $rsRole)
      @continue($rsRole->id === $rsActiveRole?->id)
      <form method="POST" action="{{ route('session.switch-role', ['school' => $school->slug]) }}">
        @csrf
        <input type="hidden" name="role_id" value="{{ $rsRole->id }}">
        <button type="submit" class="role-switch-option">{{ $rsRole->name }}</button>
      </form>
    @endforeach
    <div class="muted" style="font-size:.75rem;margin-top:.4rem">Currently: {{ $rsActiveRole?->name }}</div>
  </div>
</details>
@endif
