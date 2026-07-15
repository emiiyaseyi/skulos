{{--
  Floating messaging widget (2026-07-15, user directive: "THE MESSAGE
  FEATURE SHOULD ALSO APPEAR AS A WIDGET BY THE BOTTOM RIGHT SIDE OF THE
  USER PORTAL"). A fixed bottom-right button with an unread badge; clicking
  it navigates to whichever messaging screen this user actually has (Chat
  for the two roles that participate in it, Messages otherwise), rather
  than opening an inline panel (scope decision: simplest reliable option,
  reuses the existing Chat/Messages pages instead of a second, parallel
  AJAX-driven UI). Shown for every school-portal role, not only Chat
  participants, per the user's own answer ("Everyone, showing whatever
  messaging they have").
--}}
@php
  $mwUser = auth()->user();
  $mwRbac = app(\App\Services\Rbac\Rbac::class);
  $mwRoleSlug = optional($mwUser->roleIn($school))->slug;
  $mwHasChat = $mwRoleSlug && (
      in_array($mwRoleSlug, config('skulos.teacher_scoped_roles', []), true)
      || $mwRoleSlug === 'parent'
  );

  $mwChatUnread = \App\Models\Tenant\ChatThread::where('user_one_id', $mwUser->id)->orWhere('user_two_id', $mwUser->id)
      ->get()->sum(fn ($t) => $t->unreadCountFor($mwUser->id));
  $mwBroadcastUnread = \App\Models\Tenant\Notification::forUser($mwUser->id)->where('category', 'broadcast')->whereNull('read_at')->count();
  $mwUnread = $mwChatUnread + $mwBroadcastUnread;

  $mwCanViewMessages = $mwRbac->check($mwUser, $school, 'communication.view');
  $mwHref = $mwHasChat
      ? route('school.chat.index', ['school' => $school->slug])
      : ($mwCanViewMessages ? route('school.messages.index', ['school' => $school->slug]) : route('school.dashboard', ['school' => $school->slug]));
@endphp
@if($mwHasChat || $mwCanViewMessages)
<a href="{{ $mwHref }}" class="mw-fab" aria-label="Messages{{ $mwUnread > 0 ? ", {$mwUnread} unread" : '' }}">
  {!! view('layouts.partials.icon', ['name' => 'chat'])->render() !!}
  @if($mwUnread > 0)
    <span class="mw-badge">{{ $mwUnread > 99 ? '99+' : $mwUnread }}</span>
  @endif
</a>
<style>
  .mw-fab{position:fixed;right:1.25rem;bottom:1.25rem;z-index:120;width:3.1rem;height:3.1rem;border-radius:50%;
    background:var(--accent);color:var(--accent-ink);display:grid;place-items:center;box-shadow:var(--shadow);
    text-decoration:none;transition:transform .15s ease}
  .mw-fab:hover{transform:scale(1.06)}
  .mw-fab .ic{width:1.4rem;height:1.4rem}
  .mw-badge{position:absolute;top:-.25rem;right:-.25rem;min-width:1.3rem;height:1.3rem;padding:0 .3rem;border-radius:999px;
    background:var(--bad,#c0392b);color:#fff;font-size:.7rem;font-weight:800;display:grid;place-items:center;
    border:2px solid var(--bg)}
  @media(max-width:640px){.mw-fab{right:1rem;bottom:1rem;width:2.8rem;height:2.8rem}}
</style>
@endif
