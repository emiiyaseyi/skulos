@unless($template === 'narrative')
<div class="rc-summary">
  <div class="box"><b>{{ $data['overall']['total'] }}</b><span>Total Score</span></div>
  <div class="box"><b>{{ $data['overall']['average'] }}%</b><span>Average</span></div>
  @if($show('overall_position'))
    <div class="box"><b>{{ $data['overall']['position'] }}</b><span>Position</span></div>
    <div class="box"><b>{{ $data['overall']['class_size'] }}</b><span>Class Size</span></div>
  @endif
</div>
@endunless
