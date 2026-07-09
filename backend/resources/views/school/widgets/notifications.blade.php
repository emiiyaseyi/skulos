<div class="card">
  <div class="row-between" style="margin-bottom:.6rem">
    <h3>Notifications</h3>
    @if($feed['unread'] > 0)<span class="badge badge-warn">{{ $feed['unread'] }} new</span>@endif
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
        <form method="POST" action="{{ route('school.notifications.read', ['school' => $school->slug, 'id' => $n->id]) }}">
          @csrf<button class="btn btn-ghost btn-sm">Mark read</button>
        </form>
      @endunless
    </div>
  @empty
    <p class="muted">You have no notifications yet. School announcements will appear here.</p>
  @endforelse
</div>
