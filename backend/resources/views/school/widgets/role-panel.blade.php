@php
  $total = ($panel['boys'] ?? 0) + ($panel['girls'] ?? 0);
  $boysPct = $total ? round(($panel['boys'] / $total) * 100) : 0;
@endphp

<style>
  .rp-card{margin-bottom:1.5rem}
  .rp-donut{width:96px;height:96px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;
    background:conic-gradient(#6D4FE0 calc(var(--boys,0) * 1%), #E14F63 0)}
  .rp-donut i{width:70px;height:70px;border-radius:50%;background:var(--panel);display:grid;place-items:center;font-weight:800;font-size:.85rem;text-align:center}
  .rp-legend{display:flex;gap:1.2rem;margin-top:.8rem}
  .rp-legend span{display:flex;align-items:center;gap:.4rem;font-size:.82rem}
  .rp-legend i{width:10px;height:10px;border-radius:50%;display:inline-block}
  .rp-row{display:flex;gap:1.5rem;align-items:center;flex-wrap:wrap}
  .rp-list{list-style:none;display:flex;flex-direction:column;gap:.5rem}
  .rp-list li{display:flex;justify-content:space-between;padding:.5rem .7rem;background:var(--bg);border-radius:8px;font-size:.88rem}
</style>

@switch($panel['type'])

  @case('performance')
    <div class="card rp-card">
      <h3 style="margin-bottom:.3rem">Performance overview</h3>
      @if($panel['average'] === null)
        <p class="muted">No scores recorded yet{{ $panel['term'] ? " for {$panel['term']}" : '' }}.</p>
      @else
        <p class="muted">Average score across all recorded assessments in {{ $panel['term'] }}.</p>
        <div style="font-size:2rem;font-weight:800;margin-top:.4rem">{{ $panel['average'] }}%</div>
      @endif
    </div>
    @break

  @case('class_overview')
    <div class="card rp-card">
      <div class="row-between" style="margin-bottom:.8rem">
        <h3>{{ $panel['class_label'] }} overview</h3>
        @if($panel['read_only'])<span class="badge">View only</span>@endif
      </div>
      <div class="rp-row">
        <div class="rp-donut" style="--boys:{{ $boysPct }}"><i>{{ $boysPct }}% boys</i></div>
        <div>
          <div class="rp-legend">
            <span><i style="background:#6D4FE0"></i> Boys ({{ $panel['boys'] }})</span>
            <span><i style="background:#E14F63"></i> Girls ({{ $panel['girls'] }})</span>
          </div>
          <p class="muted" style="margin-top:.7rem">
            @if($panel['average'] === null)
              No scores recorded yet{{ $panel['term'] ? " for {$panel['term']}" : '' }}.
            @else
              Class average this term ({{ $panel['term'] }}): <strong style="color:var(--ink)">{{ $panel['average'] }}%</strong>
            @endif
          </p>
        </div>
      </div>
    </div>
    @break

  @case('subject_overview')
    <div class="card rp-card">
      <h3 style="margin-bottom:.7rem">My subjects and classes</h3>
      <ul class="rp-list">
        @foreach($panel['assignments'] as $a)
          <li><span><strong>{{ $a['subject'] }}</strong></span><span class="muted">{{ $a['class'] }}</span></li>
        @endforeach
      </ul>
    </div>
    @break

  @case('children')
    <div class="card rp-card">
      <h3 style="margin-bottom:.7rem">My children</h3>
      @if(empty($panel['children']))
        <p class="muted">No children linked to your account yet. Contact the school office to be linked.</p>
      @else
        <ul class="rp-list">
          @foreach($panel['children'] as $child)
            <li><span><strong>{{ $child['name'] }}</strong></span><span class="muted">{{ $child['class'] ?? 'Not yet placed in a class' }}</span></li>
          @endforeach
        </ul>
      @endif
    </div>
    @break

  @case('own_result')
    <div class="card rp-card">
      <p class="muted">Your class figures are shown above. Full report cards will appear here once scores are published{{ $panel['term'] ? " for {$panel['term']}" : '' }}.</p>
    </div>
    @break

  @case('no_class')
    <div class="card rp-card"><p class="muted">You are not yet set as the class teacher of any class. Ask an administrator to assign you to a class.</p></div>
    @break

  @case('no_assignments')
    <div class="card rp-card"><p class="muted">You have no teaching assignments yet. Ask an administrator to assign you a subject and class.</p></div>
    @break

  @case('no_record')
    <div class="card rp-card"><p class="muted">Your student record has not been linked to this account yet. Contact the school office.</p></div>
    @break
@endswitch
