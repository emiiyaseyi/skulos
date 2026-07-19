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
      @if($show('cumulative_terms'))
        @foreach($priorTermNames as $termName)
          <th class="num">{{ $termName }}</th>
        @endforeach
      @endif
      <th class="num">Total</th>
      @if($show('cumulative_terms') && count($priorTermNames))<th class="num">Average</th>@endif
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
        @if($show('cumulative_terms'))
          @foreach($priorTermNames as $termName)
            <td class="num">{{ $subject['previous_terms'][$termName] ?? 'N/A' }}</td>
          @endforeach
        @endif
        <td class="num"><strong>{{ $subject['total'] }}</strong></td>
        @if($show('cumulative_terms') && count($priorTermNames))<td class="num">{{ $subject['cumulative_average'] }}</td>@endif
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
@endunless
