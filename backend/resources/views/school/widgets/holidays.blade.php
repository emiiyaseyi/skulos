<div class="card">
  <h3>This term's key dates</h3>
  @if($termKeyDates['term'])
    <p style="margin-bottom:.8rem">{{ $termKeyDates['term']->name }} milestones.</p>
    @foreach($termKeyDates['items'] as $item)
      <div class="notif">
        <span class="dot"></span>
        <div style="flex:1">
          <b>{{ $item['title'] }}</b>
          <small>
            {{ $item['starts_on']?->toFormattedDateString() ?? 'Not set yet' }}
            @if($item['ends_on'] && ! $item['ends_on']->equalTo($item['starts_on']))
              to {{ $item['ends_on']->toFormattedDateString() }}
            @endif
          </small>
        </div>
        @switch($item['status'])
          @case('not_set')
            <span class="badge">Not set</span>
            @break
          @case('done')
            <span class="badge badge-ok">Done</span>
            @break
          @case('today')
            <span class="badge badge-warn">Today</span>
            @break
          @default
            <span class="badge">{{ ucfirst($item['status']) }}</span>
        @endswitch
      </div>
    @endforeach
  @else
    <p class="muted">No current term set yet.</p>
  @endif
</div>
