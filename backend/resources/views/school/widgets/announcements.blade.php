{{--
  Announcement ticker (rebuilt 2026-07-16, user directive: "THE
  ANNOUNCEMENT SHOULD NOT BE SHOWING ALL THE ANNOUNCEMENTS, IT SHOULD BE
  SCROLLING FROM RIGHT TO LEFT ONE AFTER THE OTHER AND THE USER CAN
  DECIDE TO DISMISS IT BUT IT RELOADS WHEN USER LOGIN AGAIN AND THE USER
  ONLY SEE NEW ONE WHICH CAN ALSO BE DISMISSED - THE LOOP CONTINUE LIKE
  THAT"). $announcementNotifications is the signed-in user's own unread
  (not yet dismissed) 'announcement'-category Notification rows, the
  exact same per-user Notification.read_at tracking the broadcast pop-up
  already uses, so "dismiss" here really does persist across logins and
  a user only ever sees what is genuinely new to them.
--}}
@php($isRow = isset($isRow) ? $isRow : true)
@if($announcementNotifications->isNotEmpty())
<div class="ann-card ann-ticker-card" style="{{ $isRow ? '' : 'margin-bottom:1.1rem' }}" data-ann-ticker>
  <div class="row-between" style="margin-bottom:.5rem">
    <h3 style="margin:0">Announcements</h3>
    <span class="muted ann-ticker-count" style="font-size:.72rem">1 / {{ $announcementNotifications->count() }}</span>
  </div>
  <div class="ann-ticker-viewport">
    @foreach($announcementNotifications as $i => $n)
      <div class="ann-ticker-item{{ $i === 0 ? ' is-active' : '' }}" data-notification-id="{{ $n->id }}">
        <div class="ann-ticker-body">
          <strong>{{ $n->announcement?->title ?? $n->title }}</strong>
          <div class="when">{{ $n->created_at->diffForHumans() }}</div>
        </div>
        <button type="button" class="ann-ticker-dismiss" aria-label="Dismiss">&times;</button>
      </div>
    @endforeach
  </div>
</div>

@once
@push('head')
<style>
  .ann-ticker-viewport{position:relative;overflow:hidden;height:2.9rem}
  .ann-ticker-item{position:absolute;inset:0;display:flex;align-items:center;justify-content:space-between;gap:.6rem;
    transform:translateX(100%);opacity:0;transition:transform .45s ease,opacity .45s ease;pointer-events:none}
  .ann-ticker-item.is-active{transform:translateX(0);opacity:1;pointer-events:auto}
  .ann-ticker-item.is-leaving{transform:translateX(-100%);opacity:0}
  .ann-ticker-body strong{font-size:.85rem;display:block;line-height:1.3}
  .ann-ticker-body .when{color:var(--mut-dim);font-size:.72rem}
  .ann-ticker-dismiss{flex-shrink:0;width:1.6rem;height:1.6rem;border-radius:50%;border:1px solid var(--line);background:var(--bg);
    color:var(--mut);cursor:pointer;font-size:1rem;line-height:1;display:grid;place-items:center}
  .ann-ticker-dismiss:hover{background:var(--soft)}
</style>
@endpush
@push('scripts')
<script>
(function(){
  var AUTO_ADVANCE_MS = 6000;

  document.querySelectorAll('[data-ann-ticker]').forEach(function(card){
    var viewport = card.querySelector('.ann-ticker-viewport');
    var countLabel = card.querySelector('.ann-ticker-count');
    var timer = null;

    function items() { return Array.prototype.slice.call(viewport.querySelectorAll('.ann-ticker-item')); }

    function updateCount() {
      var list = items();
      if (! list.length) { card.remove(); return; }
      var activeIndex = list.findIndex(function(el){ return el.classList.contains('is-active'); });
      if (countLabel) countLabel.textContent = (activeIndex + 1) + ' / ' + list.length;
    }

    function advance() {
      var list = items();
      if (list.length < 2) return;
      var current = list.find(function(el){ return el.classList.contains('is-active'); });
      var next = list[(list.indexOf(current) + 1) % list.length];
      current.classList.add('is-leaving');
      current.classList.remove('is-active');
      next.classList.add('is-active');
      setTimeout(function(){ current.classList.remove('is-leaving'); }, 450);
      updateCount();
    }

    function scheduleAdvance() {
      clearInterval(timer);
      timer = setInterval(advance, AUTO_ADVANCE_MS);
    }

    // Dismiss: persists via the existing per-user notification-read
    // route (same one the broadcast pop-up already uses), so it never
    // reappears, then advances to whatever is next, or removes the
    // whole widget once nothing is left.
    viewport.addEventListener('click', function(e){
      var btn = e.target.closest('.ann-ticker-dismiss');
      if (! btn) return;
      var item = btn.closest('.ann-ticker-item');
      var id = item.getAttribute('data-notification-id');
      var wasActive = item.classList.contains('is-active');

      fetch('{{ url("/notifications") }}/' + id + '/read', {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
          'Accept': 'application/json',
        },
      }).catch(function(){});

      var list = items();
      var idx = list.indexOf(item);
      item.remove();
      var remaining = items();
      if (! remaining.length) { card.remove(); return; }
      if (wasActive) {
        var next = remaining[idx % remaining.length];
        next.classList.add('is-active');
      }
      updateCount();
      scheduleAdvance();
    });

    updateCount();
    scheduleAdvance();
  });
})();
</script>
@endpush
@endonce
@endif
