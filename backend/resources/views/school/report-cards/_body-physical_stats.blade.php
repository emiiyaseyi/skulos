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
{{--
  A real bordered table (user directive 2026-09-22, reference report
  card screenshots: "first image is how the section should look
  like"), replacing the old plain label/value list, for every template
  (confirmed via AskUserQuestion: "Every template" over narrative
  only). Beginning/End of Term tracking is now class/section
  resolved (ReportCardPreferences::tracksPhysicalStatsStartFor(),
  2026-09-22 -- see $data['physical']['tracks_start'], already resolved
  per class by the time it reaches here), so PPMS's Nursery can show
  the Beginning/End columns without Primary/Secondary's report cards
  also switching over.
--}}
@php($tracksStart = $data['physical']['tracks_start'] ?? false)
@if($show('physical_stats') || $show('class_teacher_contact'))
  <div class="rc-block">
    @if($show('physical_stats'))
      <h3>Physical &amp; Attendance</h3>
      <table class="rc-bordered-table rc-attend-physical">
        <tr>
          <th colspan="2">Attendance Record</th>
          <th colspan="{{ $tracksStart ? 3 : 2 }}">Physical &amp; Health Records</th>
        </tr>
        <tr>
          <th>Days school opened</th>
          <th>Days present</th>
          <th></th>
          @if($tracksStart)
            <th>Beginning of {{ \App\Support\TermLabel::current() }}</th>
            <th>End of {{ \App\Support\TermLabel::current() }}</th>
          @else
            <th>Value</th>
          @endif
        </tr>
        <tr>
          <td rowspan="2">{{ $data['physical']['total_school_days'] ?? 'N/A' }}</td>
          <td rowspan="2">{{ $data['attendance']['present'] ?? 'N/A' }}</td>
          <td>Weight:</td>
          @if($tracksStart)
            <td>{{ $data['physical']['weight_start'] ?? 'N/A' }}</td>
          @endif
          <td>{{ $data['physical']['weight'] ?? 'N/A' }}</td>
        </tr>
        <tr>
          <td>Height:</td>
          @if($tracksStart)
            <td>{{ $data['physical']['height_start'] ?? 'N/A' }}</td>
          @endif
          <td>{{ $data['physical']['height'] ?? 'N/A' }}</td>
        </tr>
      </table>
      {{-- Clubs are optional (2026-08-16): a school that does not run
           them should not see an empty "Club: N/A" line on every
           report card, which is what this printed before. --}}
      @if($data['physical']['clubs_enabled'] ?? false)
        <p style="text-align:center;margin-top:.4rem"><b>Club:</b> {{ $data['physical']['club'] ?: 'N/A' }}</p>
      @endif
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
