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
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Name</th><th>Address</th><th>Main</th>@if($canManage)<th></th>@endif</tr></thead>
      <tbody>
        @foreach($campuses as $campus)
          <tr>
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
      <input type="checkbox" name="is_main" value="1" style="width:auto" /> This is the main campus
    </label>
    <button class="btn btn-primary" type="submit">Add campus</button>
  </form>
</div>
@endif
@endsection
