@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Question Bank'])
@section('title', 'Question Bank')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Question Bank</h1>
    <p class="subtitle">Create, import and manage questions for all assessments.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.assessments.index', ['school' => $school->slug]) }}">Assessments</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.security-profiles.index', ['school' => $school->slug]) }}">Security profiles</a>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if($errors->any())
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ $errors->first() }}</div>
@endif

<div style="display:grid;grid-template-columns:minmax(0,14rem) minmax(0,1fr);gap:1.1rem;align-items:start" class="qb-layout">
  {{-- Category sidebar, with a live count per category --}}
  <div class="card">
    <h3 style="margin-bottom:.6rem;font-size:.78rem;text-transform:uppercase;letter-spacing:.05em">Categories</h3>
    <div class="stack" style="gap:.25rem;margin-bottom:.8rem">
      <a class="qb-cat {{ $activeCategoryId ? '' : 'qb-cat-active' }}" href="{{ route('school.cbt.questions.index', ['school' => $school->slug]) }}">
        <span>All questions</span><span class="muted">{{ $totalCount }}</span>
      </a>
      @foreach($categories as $category)
        <div style="display:flex;align-items:center;gap:.3rem">
          <a class="qb-cat {{ (string) $activeCategoryId === (string) $category->id ? 'qb-cat-active' : '' }}" style="flex:1"
             href="{{ route('school.cbt.questions.index', ['school' => $school->slug, 'cat' => $category->id]) }}">
            <span>{{ $category->name }}</span><span class="muted">{{ $category->questions_count }}</span>
          </a>
          <details>
            <summary class="btn btn-ghost btn-sm" style="cursor:pointer;padding:.15rem .35rem" title="Edit category">Edit</summary>
            <form method="POST" action="{{ route('school.cbt.questions.categories.update', ['school' => $school->slug, 'categoryId' => $category->id]) }}" style="margin-top:.4rem">
              @csrf
              <input name="name" type="text" value="{{ $category->name }}" required style="width:100%;margin-bottom:.3rem" />
              <button class="btn btn-primary btn-sm" type="submit">Save</button>
            </form>
            <form method="POST" action="{{ route('school.cbt.questions.categories.destroy', ['school' => $school->slug, 'categoryId' => $category->id]) }}" style="margin-top:.3rem" onsubmit="return confirm('Remove this category? Its questions become uncategorised.')">
              @csrf @method('DELETE')
              <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Delete</button>
            </form>
          </details>
        </div>
      @endforeach
    </div>
    <details>
      <summary class="btn btn-ghost btn-sm" style="cursor:pointer;width:100%">New category</summary>
      <form method="POST" action="{{ route('school.cbt.questions.categories.store', ['school' => $school->slug]) }}" style="margin-top:.5rem">
        @csrf
        <input name="name" type="text" placeholder="Category name" required style="width:100%;margin-bottom:.35rem" />
        <button class="btn btn-primary btn-sm btn-block" type="submit">Add category</button>
      </form>
    </details>
  </div>

  <div>
    {{-- Toolbar: one clean row. Import is its own screen, not an inline
         panel, so opening it can never reflow the buttons beside it. --}}
    <div class="card" style="margin-bottom:1.1rem">
      <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
        <button type="button" class="btn btn-primary btn-sm" id="newQuestionToggle">New Question</button>
        <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.questions.import.form', ['school' => $school->slug]) }}">Import</a>
        <span class="muted" style="font-size:.85rem;margin-left:.3rem">Export:</span>
        @foreach(\App\Services\Cbt\QuestionPortabilityService::EXPORT_FORMATS as $format => $label)
          <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.questions.export', ['school' => $school->slug, 'format' => $format, 'cat' => $activeCategoryId]) }}">{{ $label }}</a>
        @endforeach
      </div>

      <div id="newQuestionPanel" hidden style="margin-top:1rem;border-top:1px solid var(--line);padding-top:1rem">
        <form method="POST" action="{{ route('school.cbt.questions.store', ['school' => $school->slug]) }}" enctype="multipart/form-data">
          @csrf
          @include('school.cbt.questions._fields', ['prefix' => 'new'])
          <button class="btn btn-primary btn-sm" type="submit" style="margin-top:.6rem">Add question</button>
        </form>
      </div>
    </div>

    {{-- Search --}}
    <form method="GET" action="{{ route('school.cbt.questions.index', ['school' => $school->slug]) }}" class="card" style="margin-bottom:1.1rem;display:flex;gap:.5rem;align-items:center">
      @if($activeCategoryId)<input type="hidden" name="cat" value="{{ $activeCategoryId }}" />@endif
      <input name="search" type="search" value="{{ $search }}" placeholder="Search questions..." style="flex:1" />
      <button class="btn btn-primary btn-sm" type="submit">Search</button>
    </form>

    <div class="card">
      <p class="muted" style="margin-bottom:.8rem">
        {{ $questions->total() }} question(s) found{{ $activeCategoryId ? ' in this category' : ' site-wide' }}.
        @unless($isBlanket)
          Showing your own questions, plus any other teacher has marked for general use.
        @endunless
      </p>

      @if($questions->isEmpty())
        <p class="muted">No questions here yet.</p>
      @else
        <form id="bulkQuestionsForm" method="POST" action="{{ route('school.cbt.questions.bulk-action', ['school' => $school->slug]) }}">@csrf</form>

        {{-- Bulk action bar --}}
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-bottom:.8rem;padding:.6rem;border:1px solid var(--line);border-radius:8px">
          <button type="button" class="btn btn-ghost btn-sm" id="qSelectAllBtn">All</button>
          <button type="button" class="btn btn-ghost btn-sm" id="qSelectNoneBtn">None</button>
          <span class="muted" id="qSelectedCount" style="font-size:.85rem">0 selected</span>
          <button form="bulkQuestionsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="delete" onclick="return confirm('Remove the selected questions?')">Delete Selected</button>
          <select form="bulkQuestionsForm" name="target_category_id" style="max-width:12rem">
            <option value="">Move to category...</option>
            @foreach($categories as $category)
              <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
          </select>
          <button form="bulkQuestionsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="move">Move</button>
          <input form="bulkQuestionsForm" name="new_mark" type="number" step="0.5" min="0.5" placeholder="New mark" style="width:6.5rem" />
          <button form="bulkQuestionsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="change_mark">Change Mark</button>
          <button form="bulkQuestionsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="activate">Activate</button>
          <button form="bulkQuestionsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="deactivate">Deactivate</button>
        </div>

        <div style="overflow-x:auto">
        <table class="table-stack">
          <thead><tr><th style="width:2.2rem"><input type="checkbox" id="qSelectAll" aria-label="Select all" /></th><th>Question</th><th>Type</th><th>Category</th><th>Marks</th><th>Visibility</th><th>Modified</th><th>Actions</th></tr></thead>
          <tbody>
            @foreach($questions as $question)
              <tr>
                <td><input type="checkbox" form="bulkQuestionsForm" class="q-row-check" name="question_ids[]" value="{{ $question->id }}" aria-label="Select question" /></td>
                <td data-label="Question" style="max-width:22rem">
                  <strong>{{ \Illuminate\Support\Str::limit($question->name ?: $question->prompt, 90) }}</strong>
                  @unless($question->is_active)<span class="badge badge-warn" style="margin-left:.4rem">Inactive</span>@endunless
                </td>
                <td class="muted" data-label="Type">{{ ucfirst(str_replace('_', ' ', $question->type)) }}</td>
                <td class="muted" data-label="Category">{{ $question->category?->name ?: 'Uncategorised' }}</td>
                <td class="muted" data-label="Marks">{{ $question->points }}</td>
                @php($ownsThis = $isBlanket || $question->created_by_staff_id === $ownStaffId)
                <td data-label="Visibility">
                  <span class="badge {{ $question->visibility === 'general' ? 'badge-ok' : '' }}">{{ $question->visibility === 'general' ? 'General' : 'Private' }}</span>
                  @if(! $ownsThis && $question->creator)
                    <div class="muted" style="font-size:.75rem;margin-top:.2rem">By {{ $question->creator->fullName() }}</div>
                  @endif
                </td>
                <td class="muted" data-label="Modified">{{ $question->updated_at?->format('d M Y') }}</td>
                <td style="white-space:nowrap">
                  @if($ownsThis)
                    <details style="display:inline-block">
                      <summary class="btn btn-ghost btn-sm" style="display:inline-block;cursor:pointer">Edit</summary>
                      <form method="POST" action="{{ route('school.cbt.questions.update', ['school' => $school->slug, 'questionId' => $question->id]) }}" enctype="multipart/form-data" style="margin-top:.6rem;min-width:20rem;text-align:left">
                        @csrf
                        @include('school.cbt.questions._fields', ['prefix' => 'edit_'.$question->id, 'question' => $question])
                        <label style="display:flex;align-items:center;gap:.4rem;margin:.6rem 0">
                          <input type="checkbox" name="is_active" value="1" @checked($question->is_active) /> Active
                        </label>
                        <button class="btn btn-primary btn-sm" type="submit">Save</button>
                      </form>
                    </details>
                    <form method="POST" action="{{ route('school.cbt.questions.destroy', ['school' => $school->slug, 'questionId' => $question->id]) }}" style="display:inline" onsubmit="return confirm('Remove this question?')">
                      @csrf @method('DELETE')
                      <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Delete</button>
                    </form>
                  @else
                    <span class="muted" style="font-size:.82rem">Shared, view only</span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
        </div>
        <div style="margin-top:1rem">{{ $questions->links() }}</div>

        <script>
          (function(){
            var checks = function(){ return document.querySelectorAll('.q-row-check'); };
            var countEl = document.getElementById('qSelectedCount');
            function refresh(){
              countEl.textContent = document.querySelectorAll('.q-row-check:checked').length + ' selected';
            }
            document.getElementById('qSelectAll').addEventListener('change', function(){
              checks().forEach(function(cb){ cb.checked = this.checked; }, this); refresh();
            });
            document.getElementById('qSelectAllBtn').addEventListener('click', function(){
              checks().forEach(function(cb){ cb.checked = true; }); refresh();
            });
            document.getElementById('qSelectNoneBtn').addEventListener('click', function(){
              checks().forEach(function(cb){ cb.checked = false; }); refresh();
            });
            checks().forEach(function(cb){ cb.addEventListener('change', refresh); });
            document.getElementById('bulkQuestionsForm').addEventListener('submit', function(e){
              if (document.querySelectorAll('.q-row-check:checked').length === 0) { e.preventDefault(); alert('Tick at least one question first.'); }
            });
            refresh();
          })();
        </script>
      @endif
    </div>
  </div>
</div>

@push('scripts')
<script>
  (function () {
    var toggle = document.getElementById('newQuestionToggle');
    var panel = document.getElementById('newQuestionPanel');
    if (toggle && panel) {
      toggle.addEventListener('click', function () {
        panel.hidden = !panel.hidden;
        toggle.textContent = panel.hidden ? 'New Question' : 'Close';
      });
    }
  })();
</script>
@endpush

@push('head')
<style>
  .qb-cat{display:flex;justify-content:space-between;gap:.5rem;padding:.35rem .5rem;border-radius:6px;text-decoration:none;color:inherit;font-size:.88rem}
  .qb-cat:hover{background:var(--surface-2,#f2f2f2)}
  .qb-cat-active{background:var(--accent);color:var(--accent-ink)}
  .qb-cat-active .muted{color:var(--accent-ink);opacity:.8}
  @media(max-width:820px){ .qb-layout{grid-template-columns:1fr} }
</style>
@endpush
@endsection
