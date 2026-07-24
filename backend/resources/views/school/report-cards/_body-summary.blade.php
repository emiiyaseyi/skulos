{{-- Each of the 5 boxes independently toggleable (user request
     2026-07-24: "let have the admin report setting to include this
     section where admin can toggle any of the 5 items under summary on
     or off so that it affects the appearance on the report card for all
     learners"). Grade added the same day, resolved from the student's
     own section grading scale (ReportCardService::forStudent()). --}}
@unless($template === 'narrative')
@if($show('summary_total') || $show('summary_average') || $show('summary_grade') || $show('overall_position') || $show('summary_class_size'))
<div class="rc-summary">
  @if($show('summary_total'))
    <div class="box"><b>{{ $data['overall']['total'] }}</b><span>Total Score</span></div>
  @endif
  @if($show('summary_average'))
    <div class="box"><b>{{ $data['overall']['average'] }}%</b><span>Average</span></div>
  @endif
  @if($show('summary_grade') && ($data['overall']['grade'] ?? null))
    <div class="box"><b>{{ $data['overall']['grade'] }}</b><span>Grade</span></div>
  @endif
  @if($show('overall_position'))
    <div class="box"><b>{{ $data['overall']['position'] }}</b><span>Position</span></div>
  @endif
  @if($show('summary_class_size'))
    <div class="box"><b>{{ $data['overall']['class_size'] }}</b><span>Class Size</span></div>
  @endif
</div>
@endif
@endunless
