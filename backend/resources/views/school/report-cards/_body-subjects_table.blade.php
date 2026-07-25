{{-- The subject scores table (and the "formal" template's grade tally above it). Skipped entirely for the narrative template, which has no scores. --}}
@if($template === 'formal' && $data['subjects'])
  <div class="rc-tally">
    @foreach(collect($data['subjects'])->countBy('grade') as $grade => $count)
      <span>{{ $grade }}: {{ $count }}</span>
    @endforeach
  </div>
@endif

@php
  $firstSubject = $data['subjects'][0] ?? null;
  $priorTermNames = $firstSubject ? array_keys($firstSubject['previous_terms']) : [];
  // Set by ReportCardService from Examinations > Term Calculation
  // (user request 2026-07-24): 'average' shows earlier term columns
  // plus a real average, 'comparison' shows the same columns with no
  // average, 'off' shows this term alone.
  $cumulativeMode = $data['cumulative_mode'] ?? 'off';
@endphp

@unless($template === 'narrative')
<div style="overflow-x:auto">
<table class="rc-table">
  <thead>
    <tr>
      <th>Subject</th>
      @if($template !== 'compact')
        @foreach(($firstSubject['components'] ?? []) as $componentName => $ignored)
          <th class="num">{{ $componentName }}</th>
        @endforeach
      @endif
      @if($cumulativeMode !== 'off')
        @foreach($priorTermNames as $termName)
          <th class="num">{{ $termName }}</th>
        @endforeach
      @endif
      <th class="num">Total</th>
      @if($cumulativeMode === 'average' && count($priorTermNames))<th class="num">Average</th>@endif
      <th class="num">Grade</th>
      @if($template === 'modern' && $show('subject_position'))<th class="num">Position</th>@endif
      @if($template === 'modern' && $show('class_average'))<th class="num">Class Avg</th>@endif
      @if($template === 'modern' && $show('subject_highest'))<th class="num">Highest</th>@endif
      @if($template === 'modern' && $show('subject_lowest'))<th class="num">Lowest</th>@endif
      @if($template !== 'compact')<th>Remark</th>@endif
    </tr>
  </thead>
  <tbody>
    @foreach($data['subjects'] as $subject)
      <tr>
        <td>{{ $subject['name'] }}</td>
        @if($template !== 'compact')
          @foreach($subject['components'] as $score)
            <td class="num">{{ $score }}</td>
          @endforeach
        @endif
        @if($cumulativeMode !== 'off')
          @foreach($priorTermNames as $termName)
            <td class="num">{{ $subject['previous_terms'][$termName] ?? 'N/A' }}</td>
          @endforeach
        @endif
        <td class="num"><strong>{{ $subject['total'] }}</strong></td>
        @if($cumulativeMode === 'average' && count($priorTermNames))<td class="num">{{ $subject['cumulative_average'] }}</td>@endif
        <td class="num"><strong>{{ $subject['grade'] }}</strong></td>
        @if($template === 'modern' && $show('subject_position'))<td class="num">{{ $subject['position'] }}</td>@endif
        @if($template === 'modern' && $show('class_average'))<td class="num">{{ $subject['class_average'] }}</td>@endif
        @if($template === 'modern' && $show('subject_highest'))<td class="num">{{ $subject['highest'] }}</td>@endif
        @if($template === 'modern' && $show('subject_lowest'))<td class="num">{{ $subject['lowest'] }}</td>@endif
        @if($template !== 'compact')<td>{{ $subject['remark'] }}</td>@endif
      </tr>
    @endforeach
  </tbody>
</table>
</div>
{{--
  The subject table routinely has more columns than fit a phone screen
  (component breakdown, prior term comparison columns, position/average
  extras), scrollable inside its own overflow-x:auto wrapper above, but
  nothing on screen ever hinted that a swipe reveals the rest, so a real
  parent on a real phone read the cut-off right edge as the report card
  simply missing data (user report 2026-07-25, screenshot of a subject
  row ending abruptly at the screen edge). Shown unconditionally at phone
  width rather than only when the table truly overflows, since a report
  card table this size very nearly always does and a plain CSS rule
  cannot know for certain without JavaScript.
--}}
<p class="rc-table-hint">Swipe the table to see every column &rarr;</p>
@endunless
