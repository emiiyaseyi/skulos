@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Releases'])
@section('title', 'Releases')
@section('heading', 'Releases')
@section('crumb', 'Packaged code releases self-hosted schools can pull through their license, instead of raw git/GitHub access')

@section('content')
<div class="card" style="margin-bottom:1.25rem;max-width:40rem">
  <h3 style="margin-bottom:.4rem">Build a new release</h3>
  <p class="muted" style="margin-bottom:.8rem">Packages a clean snapshot of this platform's own <code>backend/</code> working copy (a plain <code>git archive</code> of the current commit — only tracked files, nothing local/uncommitted). A built release stays a draft, only visible here, until you publish it.</p>
  <form method="POST" action="{{ route('admin.releases.store') }}">
    @csrf
    <div class="field">
      <label for="version">Version</label>
      <input id="version" name="version" value="{{ old('version') }}" placeholder="e.g. 2026.08.07" required />
      @error('version')<p class="err">{{ $message }}</p>@enderror
    </div>
    <div class="field">
      <label for="notes">Release notes</label>
      <textarea id="notes" name="notes" rows="3" placeholder="What changed in this release, shown to the self-hosted operator">{{ old('notes') }}</textarea>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Build release</button>
  </form>
</div>

<div class="card">
  <h3 style="margin-bottom:.8rem">Every release</h3>
  @if($releases->isEmpty())
    <p class="muted">No releases built yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Version</th><th>Notes</th><th>Size</th><th>Status</th><th>Built</th><th></th></tr></thead>
      <tbody>
        @foreach($releases as $release)
          <tr>
            <td data-label="Version"><strong>{{ $release->version }}</strong></td>
            <td class="muted" data-label="Notes">{{ \Illuminate\Support\Str::limit($release->notes, 80) ?: '—' }}</td>
            <td class="muted" data-label="Size">{{ $release->humanSize() }}</td>
            <td data-label="Status">
              @if($release->isPublished())
                <span class="badge badge-ok">Published</span>
              @else
                <span class="badge badge-warn">Draft</span>
              @endif
            </td>
            <td class="muted" data-label="Built">{{ $release->created_at->toFormattedDateString() }} by {{ $release->createdBy?->name ?? 'Unknown' }}</td>
            <td style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="{{ route('admin.releases.download', $release) }}">Download</a>
              @if($release->isPublished())
                <form method="POST" action="{{ route('admin.releases.unpublish', $release) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">Unpublish</button>
                </form>
              @else
                <form method="POST" action="{{ route('admin.releases.publish', $release) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-primary btn-sm" type="submit">Publish</button>
                </form>
              @endif
              <form method="POST" action="{{ route('admin.releases.destroy', $release) }}" style="display:inline" onsubmit="return confirm('Delete release {{ $release->version }}? This cannot be undone.')">
                @csrf @method('DELETE')
                <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
