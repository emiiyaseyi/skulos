@if($show('overall_remark') || $show('class_teacher_remark') || $show('principal_remark') || $show('next_term_begins'))
  <div class="rc-remarks">
    {{-- "OVERALL remarks", its own distinct line above the class
         teacher's and head of school's comments (user reference report
         card, 2026-09-21), not the same field as Class Teacher's Remark. --}}
    @if($show('overall_remark'))
      <div><b>Overall Remarks</b>{{ $data['remarks']['overall'] ?? null ?: 'Not yet entered.' }}</div>
    @endif
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
    {{-- "Next Term Begins" now renders here for EVERY template,
         including narrative (2026-09-21) -- moved out of the top
         identity strip to match the reference report card's own
         placement, "THIRD TRIMESTER BEGINS" near the stamp, not in
         the header. --}}
    @if($show('next_term_begins'))
      <div><b>Next {{ \App\Support\TermLabel::current() }} Begins</b>{{ $data['remarks']['next_term_begins_on'] ?: 'To be announced.' }}</div>
    @endif
  </div>
@endif
