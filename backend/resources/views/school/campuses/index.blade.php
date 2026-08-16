@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Campuses'])
@section('title', 'Campuses')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Campuses</h1>
    <p class="subtitle">{{ $campuses->count() }} on record.</p>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  @if($campuses->isEmpty())
    <p class="muted">No campuses added yet. If your school has one site, add it below and mark it as the main campus.</p>
  @else
    @if($canManage)
      <form id="bulkCampusesForm" method="POST" action="{{ route('school.campuses.bulk-destroy', ['school' => $school->slug]) }}">@csrf</form>
    @endif
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        @if($canManage)<th style="width:2.2rem"><input type="checkbox" id="campus-select-all" aria-label="Select all campuses" /></th>@endif
        <th>Name</th><th>Address</th><th>Main</th>@if($canManage)<th></th>@endif
      </tr></thead>
      <tbody>
        @foreach($campuses as $campus)
          <tr>
            @if($canManage)<td><input type="checkbox" form="bulkCampusesForm" class="campus-row-check" name="campus_ids[]" value="{{ $campus->id }}" aria-label="Select {{ $campus->name }}" /></td>@endif
            <td><strong>{{ $campus->name }}</strong></td>
            <td class="muted">{{ $campus->address ?: 'Not provided' }}</td>
            <td>{{ $campus->is_main ? 'Main campus' : '' }}</td>
            @if($canManage)
              <td>
                <form method="POST" action="{{ route('school.campuses.destroy', ['school' => $school->slug, 'campusId' => $campus->id]) }}" onsubmit="return confirm('Remove {{ $campus->name }}?')">
                  @csrf @method('DELETE')
                  <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
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
        <span class="muted" style="font-size:.85rem">Tick any number of campuses above, then remove them all at once.</span>
        <button form="bulkCampusesForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" onclick="return confirm('Remove the selected campuses?')">Remove selected</button>
      </div>
      <script>
        (function(){
          var all = document.getElementById('campus-select-all');
          var boxes = document.querySelectorAll('.campus-row-check');
          all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
          document.getElementById('bulkCampusesForm')?.addEventListener('submit', function(e){
            var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
            if (! anyChecked) { e.preventDefault(); alert('Tick at least one campus first.'); }
          });
        })();
      </script>
    @endif
  @endif
</div>

@if($canManage)
<div class="card" style="max-width:32rem">
  <h3 style="margin-bottom:.8rem">Add a campus</h3>
  <form method="POST" action="{{ route('school.campuses.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="field">
      <label for="name">Name</label>
      <input id="name" name="name" placeholder="e.g. Main Campus or Ikeja Branch" required />
    </div>
    <div class="field">
      <label for="address">Address</label>
      <textarea id="address" name="address" rows="2"></textarea>
    </div>
    <label style="font-weight:500;display:flex;gap:.5rem;align-items:center;margin-bottom:1rem">
      <input type="checkbox" name="is_main" value="1" /> This is the main campus
    </label>
    <button class="btn btn-primary" type="submit">Add campus</button>
  </form>
</div>
@endif
@endsection
