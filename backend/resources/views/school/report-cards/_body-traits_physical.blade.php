@if(($show('behavioural_traits') && count($data['traits'])) || $show('physical_stats'))
  <div class="rc-twocol rc-block">
    @if($show('behavioural_traits') && count($data['traits']))
      <div>
        <h3>Behavioural / Psychomotor Traits</h3>
        <table class="rc-traits">
          @foreach($data['traits'] as $trait)
            <tr><td>{{ $trait['name'] }}</td><td class="val">{{ $trait['value'] ?? 'Not yet entered' }}</td></tr>
          @endforeach
        </table>
      </div>
    @endif
    @if($show('physical_stats'))
      <div>
        <h3>Physical &amp; Attendance</h3>
        <table class="rc-traits">
          <tr><td>Height</td><td class="val">{{ $data['physical']['height'] ?? 'N/A' }}</td></tr>
          <tr><td>Weight</td><td class="val">{{ $data['physical']['weight'] ?? 'N/A' }}</td></tr>
          <tr><td>Club</td><td class="val">{{ $data['physical']['club'] ?? 'N/A' }}</td></tr>
          <tr><td>Days school was open</td><td class="val">{{ $data['physical']['total_school_days'] ?? 'N/A' }}</td></tr>
          <tr><td>Times absent</td><td class="val">{{ $data['physical']['times_absent'] ?? 'N/A' }}</td></tr>
        </table>
      </div>
    @endif
  </div>
@endif
