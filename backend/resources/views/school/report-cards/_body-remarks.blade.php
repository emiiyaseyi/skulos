@if($show('class_teacher_remark') || $show('principal_remark') || ($show('next_term_begins') && ! $isEval))
  <div class="rc-remarks">
    @if($show('class_teacher_remark'))
      <div><b>Class Teacher's Remark</b>{{ $data['remarks']['class_teacher'] ?: 'Not yet entered.' }}</div>
    @endif
    @if($show('principal_remark'))
      {{-- Same real per-section signer the signature caption already
           uses (user directive 2026-07-27: "the name that appears on
           the card... changes based on the class in question"), Head
           Teacher for Nursery/Primary or Principal for Secondary by
           default, admin-editable under Report Settings > Signature
           signer, rather than the fixed generic "Head of School". --}}
      <div><b>{{ $data['signer_name'] ?? 'Head Teacher' }}'s Remark</b>{{ $data['remarks']['principal'] ?: 'Not yet entered.' }}</div>
    @endif
    @if($show('next_term_begins') && ! $isEval)
      <div><b>Next {{ \App\Support\TermLabel::current() }} Begins</b>{{ $data['remarks']['next_term_begins_on'] ?: 'To be announced.' }}</div>
    @endif
  </div>
@endif
