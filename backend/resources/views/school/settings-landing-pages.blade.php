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
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Role</th><th>Landing page</th></tr></thead>
      <tbody>
        @foreach($roles as $slug => $role)
          <tr>
            <td><strong>{{ $role['name'] }}</strong></td>
            <td>
              <select name="pages[{{ $slug }}]">
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
@endsection
