@if($show('class_teacher_remark') || $show('principal_remark') || ($show('next_term_begins') && ! $isEval))
  <div class="rc-remarks">
    @if($show('class_teacher_remark'))
      <div><b>Class Teacher's Remark</b>{{ $data['remarks']['class_teacher'] ?: 'Not yet entered.' }}</div>
    @endif
    @if($show('principal_remark'))
      <div><b>Head of School's Remark</b>{{ $data['remarks']['principal'] ?: 'Not yet entered.' }}</div>
    @endif
    @if($show('next_term_begins') && ! $isEval)
      <div><b>Next Term Begins</b>{{ $data['remarks']['next_term_begins_on'] ?: 'To be announced.' }}</div>
    @endif
  </div>
@endif
