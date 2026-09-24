@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Behavioural Traits'])
@section('title', 'Behavioural Traits')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Behavioural Traits</h1>
    <p class="subtitle">The behavioural/psychomotor traits class teachers rate each student on. Choose whether each is a written remark or a score.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.index', ['school' => $school->slug]) }}">Back to Report Cards</a>
</div>

@if($canManage)
  <div class="card" style="margin-bottom:1.1rem;max-width:40rem">
    <h3 style="margin-bottom:.3rem">Rating options</h3>
    <p class="hint" style="margin-bottom:.6rem">The words a class teacher picks from when rating a remark trait, one per line, in the order they should appear.</p>
    <form method="POST" action="{{ route('school.report-cards.traits.scale', ['school' => $school->slug]) }}">
      @csrf
      <div class="field">
        <textarea name="options" rows="5" required maxlength="500">{{ old('options', implode("\n", $remarkScale)) }}</textarea>
        @error('options')<div class="err">{{ $message }}</div>@enderror
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Save options</button>
    </form>
  </div>
  <div class="card" style="margin-bottom:1.1rem;max-width:40rem">
    <h3 style="margin-bottom:.6rem">Add a trait</h3>
    <form method="POST" action="{{ route('school.report-cards.traits.store', ['school' => $school->slug]) }}">
      @csrf
      <div class="grid cols-2" style="gap:1rem">
        <div class="field">
          <label for="name">Name</label>
          <input id="name" name="name" required maxlength="120" placeholder="e.g. Punctuality" value="{{ old('name') }}" />
        </div>
        <div class="field">
          <label for="type">Type</label>
          <select id="type" name="type" required onchange="document.getElementById('maxScoreField').style.display = this.value === 'score' ? '' : 'none'">
            <option value="remark" @selected(old('type', 'remark') === 'remark')>Remark ({{ implode(', ', $remarkScale) }})</option>
            <option value="score" @selected(old('type') === 'score')>Score</option>
          </select>
        </div>
      </div>
      <div class="field" id="maxScoreField" style="display:none">
        <label for="max_score">Maximum score</label>
        <input id="max_score" name="max_score" type="number" min="1" max="1000" value="{{ old('max_score', 5) }}" />
      </div>
      <div class="field">
        <label for="domain">Domain</label>
        <select id="domain" name="domain">
          <option value="">None (plain behavioural trait)</option>
          <option value="psychomotor" @selected(old('domain') === 'psychomotor')>Psychomotor</option>
          <option value="affective" @selected(old('domain') === 'affective')>Affective</option>
        </select>
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Add trait</button>
    </form>
  </div>
@endif

<div class="card">
  @if($traits->isEmpty())
    <p class="muted">No traits yet.</p>
  @else
    @if($canManage)
      <form id="bulkTraitsForm" method="POST" action="{{ route('school.report-cards.traits.bulk-action', ['school' => $school->slug]) }}">@csrf</form>
    @endif
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        @if($canManage)<th style="width:2.2rem"><input type="checkbox" id="trait-select-all" aria-label="Select all traits" /></th>@endif
        <th>Name</th><th>Type</th><th>Domain</th><th>Status</th>@if($canManage)<th></th>@endif</tr></thead>
      <tbody>
        @foreach($traits as $trait)
          <tr>
            @if($canManage)
              <td><input type="checkbox" form="bulkTraitsForm" class="trait-row-check" name="trait_ids[]" value="{{ $trait->id }}" aria-label="Select {{ $trait->name }}" /></td>
            @endif
            <td><strong>{{ $trait->name }}</strong></td>
            <td class="muted">{{ $trait->type === 'score' ? 'Score (0 to '.$trait->max_score.')' : 'Remark' }}</td>
            <td class="muted">{{ ['psychomotor' => 'Psychomotor', 'affective' => 'Affective'][$trait->domain] ?? 'None' }}</td>
            <td><span class="badge {{ $trait->is_active ? 'badge-ok' : 'badge-warn' }}">{{ $trait->is_active ? 'Active' : 'Retired' }}</span></td>
            @if($canManage)
              <td style="white-space:nowrap">
                <details style="display:inline-block">
                  <summary class="btn btn-ghost btn-sm" style="display:inline-block;cursor:pointer">Edit</summary>
                  <form method="POST" action="{{ route('school.report-cards.traits.update', ['school' => $school->slug, 'traitId' => $trait->id]) }}" style="margin-top:.6rem;min-width:16rem;text-align:left">
                    @csrf
                    <div class="field">
                      <label for="name_{{ $trait->id }}">Name</label>
                      <input id="name_{{ $trait->id }}" name="name" required maxlength="120" value="{{ $trait->name }}" />
                    </div>
                    <div class="field">
                      <label for="type_{{ $trait->id }}">Type</label>
                      <select id="type_{{ $trait->id }}" name="type" required onchange="document.getElementById('maxScoreField_{{ $trait->id }}').style.display = this.value === 'score' ? '' : 'none'">
                        <option value="remark" @selected($trait->type === 'remark')>Remark ({{ implode(', ', $remarkScale) }})</option>
                        <option value="score" @selected($trait->type === 'score')>Score</option>
                      </select>
                    </div>
                    <div class="field" id="maxScoreField_{{ $trait->id }}" style="{{ $trait->type === 'score' ? '' : 'display:none' }}">
                      <label for="max_score_{{ $trait->id }}">Maximum score</label>
                      <input id="max_score_{{ $trait->id }}" name="max_score" type="number" min="1" max="1000" value="{{ $trait->max_score ?: 5 }}" />
                    </div>
                    <div class="field">
                      <label for="domain_{{ $trait->id }}">Domain</label>
                      <select id="domain_{{ $trait->id }}" name="domain">
                        <option value="">None (plain behavioural trait)</option>
                        <option value="psychomotor" @selected($trait->domain === 'psychomotor')>Psychomotor</option>
                        <option value="affective" @selected($trait->domain === 'affective')>Affective</option>
                      </select>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">Save</button>
                  </form>
                </details>
                <form method="POST" action="{{ route('school.report-cards.traits.toggle-active', ['school' => $school->slug, 'traitId' => $trait->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">{{ $trait->is_active ? 'Retire' : 'Reactivate' }}</button>
                </form>
              </td>
            @endif
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    @if($canManage)
      <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
        <span class="muted" style="font-size:.85rem">Tick any number of traits above, then choose an action.</span>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <button form="bulkTraitsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="activate">Reactivate</button>
          <button form="bulkTraitsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="retire">Retire</button>
        </div>
      </div>
      <script>
        (function(){
          var all = document.getElementById('trait-select-all');
          var boxes = document.querySelectorAll('.trait-row-check');
          all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
          document.getElementById('bulkTraitsForm')?.addEventListener('submit', function(e){
            var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
            if (! anyChecked) { e.preventDefault(); alert('Tick at least one trait first.'); }
          });
        })();
      </script>
    @endif
  @endif
</div>
@endsection
