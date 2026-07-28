{{-- One weekly grid table, shared by the single-child/personal view and
     the "all children at once" combined view below, so both stay
     pixel-identical rather than drifting apart. Expects $periods,
     $weekDays, $grid. --}}
<div style="overflow-x:auto">
<table class="tt-grid">
  <thead>
    <tr>
      <th>Period</th>
      @foreach($weekDays as $dayKey => $dayLabel)<th>{{ $dayLabel }}</th>@endforeach
    </tr>
  </thead>
  <tbody>
    @foreach($periods as $period)
      @if(! $period->is_teaching_period)
        <tr class="tt-break-row">
          <td class="tt-period-col">{{ $period->name }}<span class="tt-period-time">{{ $period->timeLabel() }}</span></td>
          <td colspan="{{ count($weekDays) }}">{{ $period->name }}</td>
        </tr>
      @else
        <tr>
          <td class="tt-period-col">{{ $period->name }}<span class="tt-period-time">{{ $period->timeLabel() }}</span></td>
          @foreach($weekDays as $dayKey => $dayLabel)
            @php($entry = $grid[$dayKey.':'.$period->id] ?? null)
            <td class="tt-cell">
              @if($entry && $entry->subject)
                <b>{{ $entry->subject->name }}</b>
                <span>
                  {{ $entry->staff?->fullName() ?? 'No teacher set' }}
                  @if(isset($entry->classArm))&middot; {{ $entry->classArm->label() }}@endif
                </span>
              @else
                <span class="tt-free">Free</span>
              @endif
            </td>
          @endforeach
        </tr>
      @endif
    @endforeach
  </tbody>
</table>
</div>
