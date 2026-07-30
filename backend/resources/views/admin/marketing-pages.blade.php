@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Marketing Pages'])
@section('title', 'Marketing Pages')
@section('heading', 'Marketing Pages')
@section('crumb', 'The public marketing site\'s HTML, editable from here')

@section('content')
@unless($siteReachable)
  <div class="card" style="margin-bottom:1.1rem;border-color:var(--bad)">
    <p><strong>The site/ folder is not reachable from this server.</strong> This means the running Laravel backend and the deployed marketing site (GitHub Pages) live on two different hosts, which is the normal production setup. Editing here only works when this app can see the real site/ folder, typically local development or a consolidated deployment.</p>
  </div>
@else
  <div class="card" style="margin-bottom:1.1rem">
    <p class="muted">Saving a page here only updates the file on this server. Publishing it live still needs a commit and push of the site/ folder (the GitHub Pages workflow deploys automatically on push to main).</p>
  </div>
@endunless

<div class="card">
  <div style="overflow-x:auto">
  <table class="table-stack">
    <thead><tr><th>Page</th><th>File</th><th>Last modified</th><th>Size</th><th></th></tr></thead>
    <tbody>
      @foreach($pages as $page)
        <tr>
          <td data-label="Page"><strong>{{ $page['label'] }}</strong></td>
          <td class="muted" data-label="File">{{ $page['file'] }}</td>
          <td class="muted" data-label="Last modified">{{ $page['modified'] ? \Illuminate\Support\Carbon::createFromTimestamp($page['modified'])->format('d M Y, g:ia') : 'N/A' }}</td>
          <td class="muted" data-label="Size">{{ $page['size'] ? number_format($page['size'] / 1024, 1).' KB' : 'N/A' }}</td>
          <td>
            @if($page['exists'])
              <a class="btn btn-ghost btn-sm" href="{{ route('admin.marketing-pages.edit', ['page' => $page['file']]) }}">Edit</a>
            @else
              <span class="badge badge-warn">Not found</span>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</div>
@endsection
