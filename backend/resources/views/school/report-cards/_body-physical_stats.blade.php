{{-- Standalone section, split from the old combined "Behavioural Traits
     & Physical Stats" block (user correction 2026-07-24: "Help me
     separate this... in the report card designer to standalone each"). --}}
@if($show('physical_stats'))
  <div class="rc-block">
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
