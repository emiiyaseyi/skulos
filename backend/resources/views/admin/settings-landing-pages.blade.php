@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Landing Pages'])
@section('title', 'Landing Pages')
@section('heading', 'Landing Pages')
@section('crumb', 'Platform wide default landing page per role')
@section('content')
<div class="card" style="max-width:40rem">
  <p class="muted" style="margin-bottom:1.2rem">This is the platform wide default, applied to every school unless a school sets its own under its own Settings, which takes over for that school only.</p>
  <form method="POST" action="{{ route('admin.settings.landing-pages.update') }}">
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
