@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Notifications'])
@section('title', 'Notifications')

@push('head')
<style>
  .notif-tabs{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:.8rem}
  .notif-tab{padding:.35rem .8rem;border-radius:999px;background:var(--soft);border:1px solid var(--line);font-size:.82rem;font-weight:600;color:var(--muted);text-decoration:none}
  .notif-tab.active{background:var(--accent);color:var(--accent-ink);border-color:var(--accent)}
  .notif-row{display:flex;gap:.7rem;align-items:flex-start;padding:.7rem 0;border-bottom:1px solid var(--line)}
  .notif-row:last-child{border-bottom:none}
  .notif-row.unread b{font-weight:800}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Notifications</h1>
    <p class="subtitle">{{ $unread }} unread.</p>
  </div>
</div>

<div class="notif-tabs">
  <a class="notif-tab {{ $view === 'inbox' ? 'active' : '' }}" href="{{ route('school.notifications.index', ['school' => $school->slug, 'view' => 'inbox', 'type' => $type]) }}">Inbox</a>
  <a class="notif-tab {{ $view === 'pinned' ? 'active' : '' }}" href="{{ route('school.notifications.index', ['school' => $school->slug, 'view' => 'pinned', 'type' => $type]) }}">Pinned</a>
  <a class="notif-tab {{ $view === 'archived' ? 'active' : '' }}" href="{{ route('school.notifications.index', ['school' => $school->slug, 'view' => 'archived', 'type' => $type]) }}">Archived</a>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <div style="display:flex;gap:.8rem;flex-wrap:wrap;align-items:flex-end">
    <form method="GET" style="max-width:22rem;flex:1;min-width:14rem">
      <input type="hidden" name="view" value="{{ $view }}" />
      <input type="hidden" name="type" value="{{ $type }}" />
      <input type="search" name="q" value="{{ $search }}" placeholder="Search notifications" />
    </form>
    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
      <a class="btn btn-sm {{ ! $type ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('school.notifications.index', ['school' => $school->slug, 'view' => $view]) }}">All</a>
      @foreach($types as $key => $label)
        <a class="btn btn-sm {{ $type === $key ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('school.notifications.index', ['school' => $school->slug, 'view' => $view, 'type' => $key]) }}">{{ $label }}</a>
      @endforeach
    </div>
  </div>
</div>

<div class="card">
  @if($notifications->isEmpty())
    <p class="muted">
      @if($view === 'archived') Nothing archived{{ $search ? ' matching your search' : '' }}.
      @elseif($view === 'pinned') Nothing pinned yet. Pin a notification to keep it here.
      @else You're all caught up{{ $search ? ' for this search' : '' }}.
      @endif
    </p>
  @else
    <form id="bulkNotificationsForm" method="POST" action="{{ route('school.notifications.bulk-action', ['school' => $school->slug]) }}">@csrf</form>
    @foreach($notifications as $n)
      @php($link = $n->deepLinkUrl($school))
      <div class="notif-row {{ $n->read_at ? '' : 'unread' }}">
        <input type="checkbox" form="bulkNotificationsForm" class="notif-row-check" name="notification_ids[]" value="{{ $n->id }}" aria-label="Select {{ $n->title }}" style="margin-top:.25rem" />
        <div style="flex:1">
          <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            <b>{{ $n->title }}</b>
            <span class="badge">{{ config("skulos.notification_types.{$n->type}", ucfirst($n->type)) }}</span>
            @if($n->pinned_at)<span class="badge badge-ok">Pinned</span>@endif
          </div>
          @if($n->body)<div class="muted" style="font-size:.87rem;margin-top:.2rem">{{ $n->body }}</div>@endif
          <small class="muted">{{ $n->created_at->diffForHumans() }}</small>
        </div>
        @if($link)
          <a class="btn btn-ghost btn-sm" href="{{ $link }}" style="white-space:nowrap">Take me there</a>
        @endif
      </div>
    @endforeach

    @if($notifications->hasPages())
      <div style="margin-top:1rem">{{ $notifications->links() }}</div>
    @endif

    <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
      <span class="muted" style="font-size:.85rem">Tick any number above, then choose an action.</span>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button form="bulkNotificationsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="read">Mark as read</button>
        @if($view === 'archived')
          <button form="bulkNotificationsForm" class="btn btn-primary btn-sm" type="submit" name="action" value="unarchive">Unarchive selected</button>
        @else
          <button form="bulkNotificationsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="pin">Pin</button>
          <button form="bulkNotificationsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="unpin">Unpin</button>
          <button form="bulkNotificationsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="archive">Archive selected</button>
        @endif
      </div>
    </div>
    <script>
      (function(){
        var boxes = document.querySelectorAll('.notif-row-check');
        document.getElementById('bulkNotificationsForm')?.addEventListener('submit', function(e){
          var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
          if (! anyChecked) { e.preventDefault(); alert('Tick at least one notification first.'); }
        });
      })();
    </script>
  @endif
</div>
@endsection
