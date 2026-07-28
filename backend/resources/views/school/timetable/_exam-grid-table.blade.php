{{-- One exam date x paper grid, shared by the single-child and "all
     children at once" views. Expects $papers, $entries, $dates. Reuses the
     plain weekly timetable's own tt-grid/tt-cell/tt-period-col/tt-free CSS
     (user report 2026-07-28: this grid rendered as a bare default table,
     visually inconsistent with the weekly timetable it sits right next to
     in the nav) rather than a second, parallel visual language. --}}
@if($dates->isEmpty())
  <p class="muted">No examination timetable is available yet{{ $term ? ' for '.$term->name : '' }}.</p>
@else
  <div style="overflow-x:auto">
  <table class="tt-grid">
    <thead>
      <tr>
        <th>Date</th>
        @foreach($papers as $paper)
          <th>{{ $paper->name }}<span class="tt-period-time">{{ $paper->timeLabel() }}</span></th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @foreach($dates as $date)
        <tr>
          <td class="tt-period-col">{{ \Illuminate\Support\Carbon::parse($date)->format('D, d M Y') }}</td>
          @foreach($papers as $paper)
            @php($entry = $entries[$date.':'.$paper->id] ?? null)
            <td class="tt-cell">
              @if($entry && $entry->subject)
                <b>{{ $entry->subject->name }}</b>
              @else
                <span class="tt-free">Not set</span>
              @endif
            </td>
          @endforeach
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
@endif
