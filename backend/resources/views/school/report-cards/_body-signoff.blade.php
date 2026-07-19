@if($show('stamp_signature') && ($data['stamp_url'] || $data['signature_url']))
  <div class="rc-signoff">
    @if($data['stamp_url'])
      <div><img src="{{ $data['stamp_url'] }}" alt="School stamp" /><div class="line">School Stamp</div></div>
    @endif
    @if($data['signature_url'])
      <div><img src="{{ $data['signature_url'] }}" alt="Signature" /><div class="line">Class Teacher's Signature</div></div>
    @endif
  </div>
@endif
