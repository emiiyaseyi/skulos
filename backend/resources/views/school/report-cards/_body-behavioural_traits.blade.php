{{-- Standalone section, split from the old combined "Behavioural Traits
     & Physical Stats" block (user correction 2026-07-24: "Help me
     separate this... in the report card designer to standalone each"). --}}
@if($show('behavioural_traits') && count($data['traits']))
  <div class="rc-block">
    <h3>Behavioural / Psychomotor Traits</h3>
    <table class="rc-traits">
      @foreach($data['traits'] as $trait)
        <tr><td>{{ $trait['name'] }}</td><td class="val">{{ $trait['value'] ?? 'Not yet entered' }}</td></tr>
      @endforeach
    </table>
  </div>
@endif
