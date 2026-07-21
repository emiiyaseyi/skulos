@extends('layouts.app', ['portal' => 'admin'])
@section('title', 'Archive')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Archive</h1>
    <p class="subtitle">Deleted schools. Their databases are kept, so any of these can be restored.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('admin.schools.index') }}">Back to schools</a>
</div>

<div class="card">
  @if($schools->isEmpty())
    <p class="muted">Nothing in the archive.</p>
  @else
    <form id="bulkRestoreForm" method="POST" action="{{ route('admin.schools.archive.bulk-restore') }}"></form>
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        <th style="width:2.2rem"><input type="checkbox" id="archive-select-all" aria-label="Select all schools" /></th>
        <th>School</th><th>Address</th><th>Deleted</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($schools as $school)
          <tr>
            <td><input type="checkbox" form="bulkRestoreForm" class="archive-row-check" name="school_ids[]" value="{{ $school->id }}" aria-label="Select {{ $school->name }}" /></td>
            <td><strong>{{ $school->name }}</strong></td>
            <td class="muted">{{ $school->slug }}.{{ config('app.domain','localhost') }}</td>
            <td class="muted">{{ $school->deleted_at?->toDayDateTimeString() }}</td>
            <td>
              <form method="POST" action="{{ route('admin.schools.restore', ['schoolId' => $school->id]) }}">
                @csrf
                <button class="btn btn-primary btn-sm" type="submit">Restore</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
      <span class="muted" style="font-size:.85rem">Tick any number of schools above, then restore them all at once.</span>
      <button form="bulkRestoreForm" class="btn btn-primary btn-sm" type="submit">Restore selected</button>
    </div>
    <script>
      (function(){
        var all = document.getElementById('archive-select-all');
        var boxes = document.querySelectorAll('.archive-row-check');
        all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
        document.getElementById('bulkRestoreForm')?.addEventListener('submit', function(e){
          var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
          if (! anyChecked) { e.preventDefault(); alert('Tick at least one school first.'); }
        });
      })();
    </script>
  @endif
</div>
@endsection
