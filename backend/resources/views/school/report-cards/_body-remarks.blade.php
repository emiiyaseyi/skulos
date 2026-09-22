{{--
  A real bordered table (user directive 2026-09-22, reference report
  card screenshots: "how the bottom of the report card should look
  like"), for every template (confirmed via AskUserQuestion: "Every
  template" over narrative only), replacing the old centered stacked
  list. Wording matches the reference exactly: "Comment", not "Remark".
--}}
@if($show('overall_remark') || $show('class_teacher_remark') || $show('principal_remark') || $show('next_term_begins'))
  @php($signoffAlsoPlaced = in_array('signoff', $bodySections ?? [], true))
  <table class="rc-bordered-table rc-remarks-table">
    {{-- "Overall Remarks", its own distinct line above the class
         teacher's and head of school's comments (user reference report
         card, 2026-09-21), not the same field as Class Teacher's Comment. --}}
    @if($show('overall_remark'))
      <tr><td colspan="2"><b>Overall Remarks:</b> {{ $data['remarks']['overall'] ?? null ?: 'Not yet entered.' }}</td></tr>
    @endif
    @if($show('class_teacher_remark'))
      <tr><td colspan="2"><b>Class Teacher's Comment:</b> {{ $data['remarks']['class_teacher'] ?: 'Not yet entered.' }}</td></tr>
    @endif
    @if($show('principal_remark'))
      {{-- Same real per-section signer the signature caption already
           uses (user directive 2026-07-27: "the name that appears on
           the card... changes based on the class in question"), Head
           Teacher for Nursery/Primary or Principal for Secondary by
           default, admin-editable under Report Settings > Signature
           signer, rather than the fixed generic "Head of School". --}}
      <tr><td colspan="2"><b>{{ $data['signer_name'] ?? 'Head Teacher' }}'s Comment:</b> {{ $data['remarks']['principal'] ?: 'Not yet entered.' }}</td></tr>
    @endif
    {{-- "Next Term Begins" now renders here for EVERY template,
         including narrative (2026-09-21) -- matching the reference
         report card's own placement, "THIRD TRIMESTER BEGINS" beside
         the stamp, not in the header. The stamp only shows inline here
         when 'signoff' is NOT also placed elsewhere on the card (most
         schools, whose body order still includes both), so an
         already-live report never shows the stamp twice; a school
         whose own custom layout places 'remarks' without 'signoff'
         (matching the reference exactly) gets it inline here instead. --}}
    @if($show('next_term_begins'))
      <tr>
        <td @if($signoffAlsoPlaced) colspan="2" @endif>
          <b>{{ \App\Support\TermLabel::current() }} Begins:</b> {{ $data['remarks']['next_term_begins_on'] ?: 'To be announced.' }}.
        </td>
        @if(! $signoffAlsoPlaced && $show('stamp_signature'))
          <td style="text-align:center">
            @if($data['stamp_url'] ?? null)
              <img src="{{ $data['stamp_url'] }}" alt="School stamp" style="max-height:40px;max-width:100px;object-fit:contain;display:block;margin:0 auto .2rem" />
            @endif
            <span>School Stamp</span>
          </td>
        @endif
      </tr>
    @endif
  </table>
@endif
