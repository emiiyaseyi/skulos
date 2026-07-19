@if($show('grade_legend') && $template !== 'narrative' && ! empty($gradeBands))
  <div class="rc-block">
    <h3>Grade Key</h3>
    <div class="rc-legend">
      @foreach($gradeBands as $band)
        <span><strong>{{ $band['grade'] }}</strong> {{ $band['min_score'] }}&ndash;{{ $band['max_score'] }}: {{ $band['remark'] }}</span>
      @endforeach
    </div>
  </div>
@endif
