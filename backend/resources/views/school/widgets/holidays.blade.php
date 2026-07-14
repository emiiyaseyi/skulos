<div class="card">
  <h3>Public holidays</h3>
  <p style="margin-bottom:.8rem">Upcoming holidays on your school calendar.</p>
  @forelse($upcomingHolidays as $holiday)
    <div class="notif">
      <span class="dot"></span>
      <div style="flex:1"><b>{{ $holiday->title }}</b><small> {{ $holiday->starts_on?->toFormattedDateString() }}</small></div>
    </div>
  @empty
    <p class="muted">No dated holidays yet. Date them from your calendar settings.</p>
  @endforelse
</div>
