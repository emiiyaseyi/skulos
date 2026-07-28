{{-- One exam date x paper grid, shared by the single-child and "all
     children at once" views. Expects $papers, $entries, $dates. --}}
@if($dates->isEmpty())
  <p class="muted">No examination timetable is available yet{{ $term ? ' for '.$term->name : '' }}.</p>
@else
  <div style="overflow-x:auto">
  <table>
    <thead>
      <tr>
        <th>Date</th>
        @foreach($papers as $paper)
          <th>{{ $paper->name }}<div class="muted" style="font-weight:400;font-size:.72rem">{{ $paper->timeLabel() }}</div></th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @foreach($dates as $date)
        <tr>
          <td class="muted" style="white-space:nowrap">{{ \Illuminate\Support\Carbon::parse($date)->format('D, d M Y') }}</td>
          @foreach($papers as $paper)
            @php($entry = $entries[$date.':'.$paper->id] ?? null)
            <td class="muted">{{ $entry?->subject?->name ?? 'Not set' }}</td>
          @endforeach
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
@endif
