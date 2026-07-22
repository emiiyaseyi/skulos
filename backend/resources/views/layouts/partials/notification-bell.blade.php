{{-- Header notification bell (user report 2026-07-21: "THIS NOTIFICATION
     BUTTON IS NOT WORKING, IT OUGHT TO SHOW A FEW NOTIFICAITONS AND SHOW
     THE MORE BUTTON THAT TAKES ON TO THE NOTIFICAITON PAGE"). Previously
     just a static, unclickable SVG that only even appeared on the
     dashboard (it read $notifCount, a variable only that one view ever
     passed in). Queries its own small feed here, the same inline-query-in-
     a-shared-partial pattern broadcast-popup.blade.php already uses, so it
     works identically on every page rather than depending on each
     controller remembering to pass notification data in. --}}
@php
  $bellFeed = \App\Models\Tenant\Notification::feedFor(auth()->id(), 5);
@endphp
<details class="notif-bell">
  <summary class="iconbtn" title="Notifications" aria-label="Notifications">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
    @if($bellFeed['unread'] > 0)<span class="badge-dot"></span>@endif
  </summary>
  <div class="notif-bell-panel">
    <div class="row-between" style="margin-bottom:.4rem">
      <strong style="font-size:.85rem">Notifications</strong>
      @if($bellFeed['unread'] > 0)<span class="badge badge-warn">{{ $bellFeed['unread'] }} new</span>@endif
    </div>
    @forelse($bellFeed['items'] as $n)
      <div class="notif" data-notification-id="{{ $n->id }}">
        <span class="dot {{ $n->read_at ? 'read' : '' }}"></span>
        <div style="flex:1">
          <b>{{ $n->title }}</b>
          @if($n->body)<div class="muted" style="font-size:.8rem">{{ \Illuminate\Support\Str::limit($n->body, 90) }}</div>@endif
          <small>{{ $n->created_at->diffForHumans() }}</small>
        </div>
        @unless($n->read_at)
          <button type="button" class="btn btn-ghost btn-sm" data-mark-read-url="{{ route('school.notifications.read', ['school' => $school->slug, 'id' => $n->id]) }}">Mark as read</button>
        @endunless
      </div>
    @empty
      <p class="muted" style="font-size:.85rem">No notifications yet.</p>
    @endforelse
    <a class="btn btn-ghost btn-sm" style="width:100%;text-align:center;margin-top:.5rem" href="{{ route('school.notifications.index', ['school' => $school->slug]) }}">More</a>
  </div>
</details>
