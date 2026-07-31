@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Question Bank'])
@section('title', 'Question Bank')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Question Bank</h1>
    <p class="subtitle">Build questions once, reuse them across any number of papers.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.papers.index', ['school' => $school->slug]) }}">Papers</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.security-profiles.index', ['school' => $school->slug]) }}">Security profiles</a>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if($errors->any())
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ $errors->first() }}</div>
@endif

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.8rem">Add a question</h3>
  <form method="POST" action="{{ route('school.cbt.questions.store', ['school' => $school->slug]) }}" enctype="multipart/form-data">
    @csrf
    @include('school.cbt.questions._fields', ['prefix' => 'new'])
    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:.6rem">Add question</button>
  </form>
</div>

<div class="card">
  <h3 style="margin-bottom:.8rem">Bank</h3>
  @if($questions->isEmpty())
    <p class="muted">No questions yet. Add one above.</p>
  @else
    <form id="bulkQuestionsForm" method="POST" action="{{ route('school.cbt.questions.bulk-action', ['school' => $school->slug]) }}"></form>
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th style="width:2.2rem"><input type="checkbox" id="qSelectAll" aria-label="Select all" /></th><th>Prompt</th><th>Type</th><th>Points</th><th>Category</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($questions as $question)
          <tr>
            <td><input type="checkbox" form="bulkQuestionsForm" class="q-row-check" name="question_ids[]" value="{{ $question->id }}" aria-label="Select question" /></td>
            <td data-label="Prompt" style="max-width:22rem">{{ \Illuminate\Support\Str::limit($question->prompt, 90) }}</td>
            <td class="muted" data-label="Type">{{ ucfirst(str_replace('_', ' ', $question->type)) }}</td>
            <td class="muted" data-label="Points">{{ $question->points }}</td>
            <td class="muted" data-label="Category">{{ $question->category ?: 'Uncategorized' }}</td>
            <td data-label="Status"><span class="badge {{ $question->is_active ? 'badge-ok' : 'badge-warn' }}">{{ $question->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td style="white-space:nowrap">
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
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $questions->links() }}</div>
    <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
      <span class="muted" style="font-size:.85rem">Tick any number of questions above, then choose an action.</span>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button form="bulkQuestionsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="activate">Activate</button>
        <button form="bulkQuestionsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="deactivate">Deactivate</button>
        <button form="bulkQuestionsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="delete" onclick="return confirm('Remove the selected questions?')">Delete selected</button>
      </div>
    </div>
    <script>
      (function(){
        var all = document.getElementById('qSelectAll');
        if (all) all.addEventListener('change', function(){
          document.querySelectorAll('.q-row-check').forEach(function(cb){ cb.checked = all.checked; });
        });
        var form = document.getElementById('bulkQuestionsForm');
        form.addEventListener('submit', function(e){
          if (document.querySelectorAll('.q-row-check:checked').length === 0) { e.preventDefault(); alert('Tick at least one question first.'); }
        });
      })();
    </script>
  @endif
</div>
@endsection
