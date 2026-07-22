<div class="card" data-widget="dashboard-notifications-feed">
  <div class="row-between" style="margin-bottom:.6rem">
    <h3>Notifications</h3>
    <div style="display:flex;align-items:center;gap:.5rem">
      @if($feed['unread'] > 0)<span class="badge badge-warn">{{ $feed['unread'] }} new</span>@endif
      <a class="btn btn-ghost btn-sm" href="{{ route('school.notifications.index', ['school' => $school->slug]) }}">View all</a>
    </div>
  </div>
  @forelse($feed['items'] as $n)
    <div class="notif">
      <span class="dot {{ $n->read_at ? 'read' : '' }}"></span>
      <div style="flex:1">
        <b>{{ $n->title }}</b>
        @if($n->body)<div class="muted" style="font-size:.85rem">{{ \Illuminate\Support\Str::limit($n->body, 120) }}</div>@endif
        <small>{{ $n->created_at->diffForHumans() }}</small>
      </div>
      @unless($n->read_at)
        <button type="button" class="btn btn-ghost btn-sm" data-mark-read-url="{{ route('school.notifications.read', ['school' => $school->slug, 'id' => $n->id]) }}">Mark as read</button>
      @endunless
    </div>
  @empty
    <p class="muted">You have no notifications yet. School announcements will appear here.</p>
  @endforelse
</div>
