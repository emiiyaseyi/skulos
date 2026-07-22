@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Landing Pages'])
@section('title', 'Landing Pages')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Landing Pages</h1>
    <p class="subtitle">Where each role lands right after signing in. Shows the platform's own default until you set one for this school specifically; saving here always overrides the platform default for your school.</p>
  </div>
</div>

<div class="card" style="max-width:40rem">
  <form method="POST" action="{{ route('school.settings.landing-pages.update', ['school' => $school->slug]) }}">
    @csrf

    {{-- User request 2026-07-22: "Landing Pages should also have bulk way of getting it done for all users instead of doing it one after the other." Tick several roles, pick one landing page, apply it to all of them at once, then Save as normal. --}}
    <div class="row-between" style="margin-bottom:.8rem;flex-wrap:wrap;gap:.6rem">
      <span class="muted" style="font-size:.85rem">Tick roles below, choose a page, then apply it to all of them at once.</span>
      <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <select id="lp-bulk-value">
          @foreach($options as $routeName => $meta)
            <option value="{{ $routeName }}">{{ $meta['label'] }}</option>
          @endforeach
        </select>
        <button type="button" class="btn btn-ghost btn-sm" id="lp-bulk-apply">Apply to selected</button>
      </div>
    </div>

    <div style="overflow-x:auto">
    <table>
      <thead><tr><th style="width:2.2rem"><input type="checkbox" id="lp-select-all" aria-label="Select all roles" /></th><th>Role</th><th>Landing page</th></tr></thead>
      <tbody>
        @foreach($roles as $slug => $role)
          <tr>
            <td><input type="checkbox" class="lp-row-check" aria-label="Select {{ $role['name'] }}" /></td>
            <td><strong>{{ $role['name'] }}</strong></td>
            <td>
              <select name="pages[{{ $slug }}]" class="lp-row-select">
                @foreach($options as $routeName => $meta)
                  <option value="{{ $routeName }}" @selected(($current[$slug] ?? 'school.dashboard') === $routeName)>{{ $meta['label'] }}</option>
                @endforeach
              </select>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <button class="btn btn-primary btn-sm" style="margin-top:1rem" type="submit">Save</button>
  </form>
</div>

<script>
(function(){
  var all = document.getElementById('lp-select-all');
  var boxes = function(){ return document.querySelectorAll('.lp-row-check'); };
  all?.addEventListener('change', function(){ boxes().forEach(function(cb){ cb.checked = all.checked; }); });

  document.getElementById('lp-bulk-apply')?.addEventListener('click', function(){
    var value = document.getElementById('lp-bulk-value').value;
    var rows = document.querySelectorAll('.lp-row-check');
    var anyChecked = Array.prototype.some.call(rows, function(cb){ return cb.checked; });
    if (! anyChecked) { alert('Tick at least one role first.'); return; }
    rows.forEach(function(cb, i){
      if (cb.checked) {
        document.querySelectorAll('.lp-row-select')[i].value = value;
      }
    });
  });
})();
</script>
@endsection
