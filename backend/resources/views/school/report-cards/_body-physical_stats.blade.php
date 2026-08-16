{{-- Standalone section, split from the old combined "Behavioural Traits
     & Physical Stats" block (user correction 2026-07-24: "Help me
     separate this... in the report card designer to standalone each").
     Class Teacher Contact renders directly under this, in the same
     column/box, not as its own separate row (user correction 2026-07-24:
     "the class teacher contact box/container is too big... it should be
     placed directly under the Physical & Attendance so that its
     length/height can match the Behavioural / Psychomotor Traits
     container on the left"), its own toggle still independent of
     physical_stats above. --}}
@if($show('physical_stats') || $show('class_teacher_contact'))
  <div class="rc-block">
    @if($show('physical_stats'))
      <h3>Physical &amp; Attendance</h3>
      <table class="rc-traits">
        <tr><td>Height</td><td class="val">{{ $data['physical']['height'] ?? 'N/A' }}</td></tr>
        <tr><td>Weight</td><td class="val">{{ $data['physical']['weight'] ?? 'N/A' }}</td></tr>
        {{-- Clubs are optional (2026-08-16): a school that does not run
             them should not see an empty "Club: N/A" line on every
             report card, which is what this printed before. --}}
        @if($data['physical']['clubs_enabled'] ?? false)
          <tr><td>Club</td><td class="val">{{ $data['physical']['club'] ?: 'N/A' }}</td></tr>
        @endif
        <tr><td>Days school was open</td><td class="val">{{ $data['physical']['total_school_days'] ?? 'N/A' }}</td></tr>
        <tr><td>Times absent</td><td class="val">{{ $data['physical']['times_absent'] ?? 'N/A' }}</td></tr>
      </table>
    @endif
    @if($show('class_teacher_contact'))
      <h3 style="{{ $show('physical_stats') ? 'margin-top:.6rem' : '' }}">Class Teacher Contact</h3>
      <table class="rc-traits">
        <tr><td>Class Teacher</td><td class="val">{{ $data['physical']['class_teacher_name'] ?? 'N/A' }}</td></tr>
        <tr><td>Class Teacher's Phone</td><td class="val">{{ $data['physical']['class_teacher_phone'] ?? 'N/A' }}</td></tr>
      </table>
    @endif
  </div>
@endif
