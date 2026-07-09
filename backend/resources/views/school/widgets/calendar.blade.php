<div class="card">
  <h3>Upcoming on the calendar</h3>
  <p style="margin-bottom:.8rem">Notable days and events for your school.</p>
  @forelse($upcoming as $event)
    <div class="notif">
      <span class="dot"></span>
      <div style="flex:1"><b>{{ $event->title }}</b><small> {{ $event->starts_on?->toFormattedDateString() }}</small></div>
      <span class="badge badge-ok">{{ ucfirst($event->category) }}</span>
    </div>
  @empty
    <p class="muted">No dated events yet. Your calendar was seeded with default notable days you can date in settings.</p>
  @endforelse
</div>
