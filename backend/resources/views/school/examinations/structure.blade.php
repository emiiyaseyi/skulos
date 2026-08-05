@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Assessment Structure'])
@section('title', 'Assessment Structure')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Assessment Structure</h1>
    <p class="subtitle">The Test / Continuous Assessment and Examination components every subject is scored against. Currently sums to {{ rtrim(rtrim(number_format($totalPossible, 2), '0'), '.') }} marks in total.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    @if($canManagePermission)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.window-extensions.index', ['school' => $school->slug]) }}">Extension requests @if($pendingExtensionCount)<span class="badge badge-warn">{{ $pendingExtensionCount }}</span>@endif</a>
    @endif
    <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.grading', ['school' => $school->slug]) }}">Grading</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.index', ['school' => $school->slug]) }}">Back to Results</a>
  </div>
</div>

@php
  $editingTermIdValue = $editingTerm->id ?? 'all';
@endphp

@if($canManagePermission)
  <div class="grid cols-2" style="gap:.8rem;margin-bottom:1.1rem;align-items:start">
    <div class="card">
      <form method="GET" action="{{ route('school.examinations.structure', ['school' => $school->slug]) }}">
        @if($term)<input type="hidden" name="term_id" value="{{ $term->id }}" />@endif
        <div class="field" style="margin:0">
          <label for="editing_term_id">Editing structure for</label>
          <select id="editing_term_id" name="editing_term_id" onchange="this.form.requestSubmit()">
            <option value="all" @selected($editingTermIdValue === 'all')>All terms (the universal structure)</option>
            @foreach($terms as $t)
              <option value="{{ $t->id }}" @selected($editingTermIdValue === $t->id)>{{ $t->name }} ({{ $t->session?->name }}) only</option>
            @endforeach
          </select>
        </div>
      </form>
      @if($editingTerm)
        <p class="muted" style="margin-top:.5rem;font-size:.82rem">
          @if($isForked)
            Editing {{ $editingTerm->name }}'s own independent structure. Changes here never affect the universal structure or any other term.
          @else
            Following the universal structure below — {{ $editingTerm->name }} has no independent structure of its own yet.
          @endif
        </p>
        <form method="POST" action="{{ route($isForked ? 'school.examinations.structure.unfork-term' : 'school.examinations.structure.fork-term', ['school' => $school->slug]) }}" style="margin-top:.5rem" @if($isForked) onsubmit="return confirm('Revert to the universal structure? {{ $editingTerm->name }}\'s own components will be removed.')" @endif>
          @csrf
          <input type="hidden" name="term_id" value="{{ $editingTerm->id }}" />
          <button class="btn btn-ghost btn-sm" type="submit">{{ $isForked ? 'Revert to the universal structure' : 'Customize the structure for '.$editingTerm->name }}</button>
        </form>
      @else
        <p class="muted" style="margin-top:.5rem;font-size:.82rem">Every term without its own structure follows this one (user directive 2026-08-05: "the assessment structure can be changed per term so its not a fixed one across the whole session or school life time").</p>
      @endif
    </div>

    <div class="card">
      <form method="GET" action="{{ route('school.examinations.structure', ['school' => $school->slug]) }}">
        <input type="hidden" name="editing_term_id" value="{{ $editingTermIdValue }}" />
        <div class="field" style="margin:0">
          <label for="term_id">Score entry windows for term</label>
          <select id="term_id" name="term_id" onchange="this.form.requestSubmit()">
            @foreach($terms as $t)
              <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->name }} ({{ $t->session?->name }})</option>
            @endforeach
          </select>
        </div>
      </form>
    </div>
  </div>
@endif

@if($canManage && ! $editingTerm && $caComponents->isEmpty() && $examComponents->isEmpty())
  <div class="card" style="margin-bottom:1.1rem">
    <h3 style="margin-bottom:.4rem">Load a default structure</h3>
    <p class="muted" style="margin-bottom:.8rem">Two Continuous Assessments (20 marks each) plus one Exam (60 marks), the common Nigerian school split. You can add, remove, rename, or reweight anything afterwards.</p>
    <form method="POST" action="{{ route('school.examinations.structure.apply-defaults', ['school' => $school->slug]) }}">
      @csrf
      <button class="btn btn-primary btn-sm" type="submit">Load default structure</button>
    </form>
  </div>
@endif

<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <h3 style="margin-bottom:.8rem">Test / Continuous Assessment</h3>
    @if($caComponents->isEmpty())
      <p class="muted">None yet.</p>
    @else
      @if($canManage)
        <form id="bulkCaForm" method="POST" action="{{ route('school.examinations.structure.bulk-destroy', ['school' => $school->slug]) }}"></form>
      @endif
      <div style="overflow-x:auto">
      <table>
        <thead><tr>
          @if($canManage)<th style="width:2.2rem"><input type="checkbox" id="ca-select-all" aria-label="Select all CA components" /></th>@endif
          <th>Name</th><th>Out of</th>@if($canManagePermission && $term)<th>Entry window</th>@endif @if($canManage)<th></th>@endif</tr></thead>
        <tbody>
          @foreach($caComponents as $component)
            @php $window = $windowsByComponent->get($component->id); @endphp
            <tr>
              @if($canManage)<td><input type="checkbox" form="bulkCaForm" class="ca-row-check" name="component_ids[]" value="{{ $component->id }}" aria-label="Select {{ $component->name }}" /></td>@endif
              <td><strong>{{ $component->name }}</strong></td>
              <td class="muted">{{ rtrim(rtrim(number_format($component->max_score, 2), '0'), '.') }}</td>
              @if($canManagePermission && $term)
                <td class="muted">
                  @if($window && ($window->opens_at || $window->closes_at))
                    {{ $window->opens_at?->format('d M, g:ia') ?? 'Any time' }} &rarr; {{ $window->closes_at?->format('d M, g:ia') ?? 'No close' }}
                  @else
                    Always open
                  @endif
                  <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('window-{{ $component->id }}').classList.toggle('show')" style="margin-left:.3rem">Edit</button>
                </td>
              @endif
              @if($canManage)
                <td style="white-space:nowrap">
                  <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('edit-{{ $component->id }}').classList.toggle('show')">Edit</button>
                  <form method="POST" action="{{ route('school.examinations.structure.destroy', ['school' => $school->slug, 'componentId' => $component->id]) }}" style="display:inline" onsubmit="return confirm('Remove {{ $component->name }}?')">
                    @csrf @method('DELETE')
                    <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove</button>
                  </form>
                </td>
              @endif
            </tr>
            @if($canManage)
            <tr id="edit-{{ $component->id }}" class="component-edit-row">
              <td colspan="5">
                <form method="POST" action="{{ route('school.examinations.structure.update', ['school' => $school->slug, 'componentId' => $component->id]) }}" style="display:flex;gap:.5rem;padding:.6rem 0">
                  @csrf
                  <input name="name" value="{{ $component->name }}" required style="flex:1" />
                  <input name="max_score" type="number" step="0.01" min="0.01" value="{{ $component->max_score }}" required style="max-width:8rem" />
                  <button class="btn btn-primary btn-sm" type="submit">Save</button>
                </form>
              </td>
            </tr>
            @endif
            @if($canManagePermission && $term)
            <tr id="window-{{ $component->id }}" class="component-edit-row">
              <td colspan="5">
                <form method="POST" action="{{ route('school.examinations.structure.windows.update', ['school' => $school->slug]) }}" style="display:flex;gap:.5rem;padding:.6rem 0;flex-wrap:wrap;align-items:flex-end">
                  @csrf
                  <input type="hidden" name="assessment_component_id" value="{{ $component->id }}" />
                  <input type="hidden" name="term_id" value="{{ $term->id }}" />
                  <div class="field" style="margin:0">
                    <label style="font-size:.72rem">Opens</label>
                    <input type="datetime-local" name="opens_at" value="{{ $window?->opens_at?->format('Y-m-d\TH:i') }}" />
                  </div>
                  <div class="field" style="margin:0">
                    <label style="font-size:.72rem">Closes</label>
                    <input type="datetime-local" name="closes_at" value="{{ $window?->closes_at?->format('Y-m-d\TH:i') }}" />
                  </div>
                  <button class="btn btn-primary btn-sm" type="submit">Save window</button>
                  <span class="muted">Leave both blank for always open.</span>
                </form>
              </td>
            </tr>
            @endif
          @endforeach
        </tbody>
      </table>
      </div>

      @if($canManage)
        <div class="row-between" style="margin-top:.6rem;flex-wrap:wrap;gap:.6rem">
          <span class="muted" style="font-size:.85rem">Tick any number above, then remove them all at once.</span>
          <button form="bulkCaForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" onclick="return confirm('Remove the selected components?')">Remove selected</button>
        </div>
        <script>
          (function(){
            var all = document.getElementById('ca-select-all');
            var boxes = document.querySelectorAll('.ca-row-check');
            all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
            document.getElementById('bulkCaForm')?.addEventListener('submit', function(e){
              var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
              if (! anyChecked) { e.preventDefault(); alert('Tick at least one component first.'); }
            });
          })();
        </script>
      @endif
    @endif

    @if($canManage)
      <form method="POST" action="{{ route('school.examinations.structure.store', ['school' => $school->slug]) }}" style="display:flex;gap:.5rem;margin-top:1rem;flex-wrap:wrap">
        @csrf
        <input type="hidden" name="type" value="ca" />
        <input type="hidden" name="editing_term_id" value="{{ $editingTermIdValue }}" />
        <input name="name" placeholder="e.g. First CA" required style="flex:1;min-width:9rem" />
        <input name="max_score" type="number" step="0.01" min="0.01" placeholder="Out of" required style="max-width:7rem" />
        <button class="btn btn-primary btn-sm" type="submit">Add</button>
      </form>
    @endif
  </div>

  <div class="card">
    <h3 style="margin-bottom:.8rem">Examinations</h3>
    @if($examComponents->isEmpty())
      <p class="muted">None yet.</p>
    @else
      @if($canManage)
        <form id="bulkExamForm" method="POST" action="{{ route('school.examinations.structure.bulk-destroy', ['school' => $school->slug]) }}"></form>
      @endif
      <div style="overflow-x:auto">
      <table>
        <thead><tr>
          @if($canManage)<th style="width:2.2rem"><input type="checkbox" id="exam-select-all" aria-label="Select all exam components" /></th>@endif
          <th>Name</th><th>Out of</th>@if($canManagePermission && $term)<th>Entry window</th>@endif @if($canManage)<th></th>@endif</tr></thead>
        <tbody>
          @foreach($examComponents as $component)
            @php $window = $windowsByComponent->get($component->id); @endphp
            <tr>
              @if($canManage)<td><input type="checkbox" form="bulkExamForm" class="exam-row-check" name="component_ids[]" value="{{ $component->id }}" aria-label="Select {{ $component->name }}" /></td>@endif
              <td><strong>{{ $component->name }}</strong></td>
              <td class="muted">{{ rtrim(rtrim(number_format($component->max_score, 2), '0'), '.') }}</td>
              @if($canManagePermission && $term)
                <td class="muted">
                  @if($window && ($window->opens_at || $window->closes_at))
                    {{ $window->opens_at?->format('d M, g:ia') ?? 'Any time' }} &rarr; {{ $window->closes_at?->format('d M, g:ia') ?? 'No close' }}
                  @else
                    Always open
                  @endif
                  <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('window-{{ $component->id }}').classList.toggle('show')" style="margin-left:.3rem">Edit</button>
                </td>
              @endif
              @if($canManage)
                <td style="white-space:nowrap">
                  <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('edit-{{ $component->id }}').classList.toggle('show')">Edit</button>
                  <form method="POST" action="{{ route('school.examinations.structure.destroy', ['school' => $school->slug, 'componentId' => $component->id]) }}" style="display:inline" onsubmit="return confirm('Remove {{ $component->name }}?')">
                    @csrf @method('DELETE')
                    <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove</button>
                  </form>
                </td>
              @endif
            </tr>
            @if($canManage)
            <tr id="edit-{{ $component->id }}" class="component-edit-row">
              <td colspan="5">
                <form method="POST" action="{{ route('school.examinations.structure.update', ['school' => $school->slug, 'componentId' => $component->id]) }}" style="display:flex;gap:.5rem;padding:.6rem 0">
                  @csrf
                  <input name="name" value="{{ $component->name }}" required style="flex:1" />
                  <input name="max_score" type="number" step="0.01" min="0.01" value="{{ $component->max_score }}" required style="max-width:8rem" />
                  <button class="btn btn-primary btn-sm" type="submit">Save</button>
                </form>
              </td>
            </tr>
            @endif
            @if($canManagePermission && $term)
            <tr id="window-{{ $component->id }}" class="component-edit-row">
              <td colspan="5">
                <form method="POST" action="{{ route('school.examinations.structure.windows.update', ['school' => $school->slug]) }}" style="display:flex;gap:.5rem;padding:.6rem 0;flex-wrap:wrap;align-items:flex-end">
                  @csrf
                  <input type="hidden" name="assessment_component_id" value="{{ $component->id }}" />
                  <input type="hidden" name="term_id" value="{{ $term->id }}" />
                  <div class="field" style="margin:0">
                    <label style="font-size:.72rem">Opens</label>
                    <input type="datetime-local" name="opens_at" value="{{ $window?->opens_at?->format('Y-m-d\TH:i') }}" />
                  </div>
                  <div class="field" style="margin:0">
                    <label style="font-size:.72rem">Closes</label>
                    <input type="datetime-local" name="closes_at" value="{{ $window?->closes_at?->format('Y-m-d\TH:i') }}" />
                  </div>
                  <button class="btn btn-primary btn-sm" type="submit">Save window</button>
                  <span class="muted">Leave both blank for always open.</span>
                </form>
              </td>
            </tr>
            @endif
          @endforeach
        </tbody>
      </table>
      </div>

      @if($canManage)
        <div class="row-between" style="margin-top:.6rem;flex-wrap:wrap;gap:.6rem">
          <span class="muted" style="font-size:.85rem">Tick any number above, then remove them all at once.</span>
          <button form="bulkExamForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" onclick="return confirm('Remove the selected components?')">Remove selected</button>
        </div>
        <script>
          (function(){
            var all = document.getElementById('exam-select-all');
            var boxes = document.querySelectorAll('.exam-row-check');
            all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
            document.getElementById('bulkExamForm')?.addEventListener('submit', function(e){
              var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
              if (! anyChecked) { e.preventDefault(); alert('Tick at least one component first.'); }
            });
          })();
        </script>
      @endif
    @endif

    @if($canManage)
      <form method="POST" action="{{ route('school.examinations.structure.store', ['school' => $school->slug]) }}" style="display:flex;gap:.5rem;margin-top:1rem;flex-wrap:wrap">
        @csrf
        <input type="hidden" name="type" value="exam" />
        <input type="hidden" name="editing_term_id" value="{{ $editingTermIdValue }}" />
        <input name="name" placeholder="e.g. Exam" required style="flex:1;min-width:9rem" />
        <input name="max_score" type="number" step="0.01" min="0.01" placeholder="Out of" required style="max-width:7rem" />
        <button class="btn btn-primary btn-sm" type="submit">Add</button>
      </form>
    @endif
  </div>
</div>

<style>
  .component-edit-row{display:none}
  .component-edit-row.show{display:table-row}
</style>
@endsection
