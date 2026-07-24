@if(($show('behavioural_traits') && count($data['traits'])) || $show('physical_stats') || $show('class_teacher_contact'))
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
    @if($show('physical_stats') || $show('class_teacher_contact'))
      <div>
        <h3>Physical &amp; Attendance</h3>
        <table class="rc-traits">
          @if($show('physical_stats'))
            <tr><td>Height</td><td class="val">{{ $data['physical']['height'] ?? 'N/A' }}</td></tr>
            <tr><td>Weight</td><td class="val">{{ $data['physical']['weight'] ?? 'N/A' }}</td></tr>
            <tr><td>Club</td><td class="val">{{ $data['physical']['club'] ?? 'N/A' }}</td></tr>
            <tr><td>Days school was open</td><td class="val">{{ $data['physical']['total_school_days'] ?? 'N/A' }}</td></tr>
            <tr><td>Times absent</td><td class="val">{{ $data['physical']['times_absent'] ?? 'N/A' }}</td></tr>
          @endif
          @if($show('class_teacher_contact'))
            {{-- Its own toggle, independent of physical_stats above (user
                 directive 2026-07-24), content only, placed here rather
                 than as a header/info zone layout block. --}}
            <tr><td>Class Teacher</td><td class="val">{{ $data['physical']['class_teacher_name'] ?? 'N/A' }}</td></tr>
            <tr><td>Class Teacher's Phone</td><td class="val">{{ $data['physical']['class_teacher_phone'] ?? 'N/A' }}</td></tr>
          @endif
        </table>
      </div>
    @endif
  </div>
@endif
