<div class="card rp-card">
  <h3 style="margin-bottom:.6rem">Birthdays</h3>
  <div class="muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.35rem">Today</div>
  @if(empty($today))
    <p class="muted" style="margin-bottom:.8rem">No birthdays today.</p>
  @else
    <ul class="rp-list" style="margin-bottom:.8rem">
      @foreach($today as $b)
        <li><span><strong>{{ $b['name'] }}</strong> <span class="muted">{{ $b['age'] !== null ? $b['age'].' yrs' : '' }} {{ $b['class'] }}</span></span><span>&#127881;</span></li>
      @endforeach
    </ul>
  @endif
  <div class="muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.35rem">Upcoming</div>
  @if(empty($upcoming))
    <p class="muted">No birthdays in the next 30 days.</p>
  @else
    <ul class="rp-list">
      @foreach($upcoming as $b)
        <li><span><strong>{{ $b['name'] }}</strong> <span class="muted">{{ $b['age'] !== null ? $b['age'].' yrs' : '' }} {{ $b['class'] }}</span></span><span class="muted">{{ $b['date'] }}</span></li>
      @endforeach
    </ul>
  @endif
</div>
