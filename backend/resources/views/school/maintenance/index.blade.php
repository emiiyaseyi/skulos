@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'School Health & Storage'])
@section('title', 'School Health & Storage')

@push('head')
<style>
  .health-score{display:flex;align-items:center;gap:1.2rem}
  .health-ring{width:88px;height:88px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;
    background:conic-gradient(var(--hc) calc(var(--score) * 1%), var(--soft) 0)}
  .health-ring i{width:68px;height:68px;border-radius:50%;background:var(--panel);display:grid;place-items:center;font-weight:800;font-size:1.3rem;font-variant-numeric:tabular-nums}
  .finding{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.6rem .8rem;border-radius:8px;margin-bottom:.5rem;font-size:.88rem}
  .finding.critical{background:color-mix(in srgb,var(--bad) 12%,transparent)}
  .finding.warning{background:color-mix(in srgb,var(--warn) 14%,transparent)}
  .finding.info{background:var(--soft)}
  .storage-bar{height:10px;border-radius:6px;background:var(--soft);overflow:hidden;margin:.5rem 0}
  .storage-bar span{display:block;height:100%;background:var(--accent)}
  .storage-groups{list-style:none;display:flex;flex-direction:column;gap:.4rem;padding:0}
  .storage-groups li{display:flex;justify-content:space-between;font-size:.88rem;padding:.4rem 0;border-bottom:1px solid var(--line)}
  .storage-groups li:last-child{border-bottom:none}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">School Health &amp; Storage</h1>
    <p class="subtitle">A real, live check of your school's data, not an estimate.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.backups.index', ['school' => $school->slug]) }}">Backups</a>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.9rem">Health score</h3>
  <div class="health-score">
    @php($hc = $health['score'] >= 80 ? 'var(--ok)' : ($health['score'] >= 50 ? 'var(--warn)' : 'var(--bad)'))
    <div class="health-ring" style="--score:{{ $health['score'] }};--hc:{{ $hc }}"><i>{{ $health['score'] }}</i></div>
    <div>
      @if(empty($health['findings']))
        <p class="muted">No issues found. Everything checked out is in good shape.</p>
      @else
        <p class="muted" style="margin-bottom:.6rem">{{ count($health['findings']) }} thing(s) worth a look.</p>
      @endif
    </div>
  </div>

  @if(! empty($health['findings']))
    <div style="margin-top:1rem">
      @foreach($health['findings'] as $finding)
        <div class="finding {{ $finding['severity'] }}">
          <span>{{ $finding['message'] }}</span>
          @if($finding['action_route'] && \Illuminate\Support\Facades\Route::has($finding['action_route']))
            <a class="btn btn-ghost btn-sm" href="{{ route($finding['action_route'], ['school' => $school->slug]) }}">{{ $finding['action_label'] }}</a>
          @endif
        </div>
      @endforeach
    </div>
  @endif
</div>

@php($totalBytes = max(1, $storage['total_bytes']))
<div class="grid cols-3" style="align-items:stretch;margin-bottom:1.1rem">
  <div class="card" style="text-align:center;padding:1rem">
    <p class="muted" style="margin-bottom:.2rem">Total storage used</p>
    {{-- Same "big stat number" size as .stat b (this page always renders inside .school-theme), 2026-07-28 text consistency audit. --}}
    <div style="font-size:1.9rem;font-weight:800">{{ \App\Services\Maintenance\StorageService::humanBytes($storage['total_bytes']) }}</div>
    @if($storage['limit_mb'])
      @php($usedMb = $storage['total_bytes'] / 1048576)
      @php($pct = min(100, round($usedMb / $storage['limit_mb'] * 100)))
      <div class="storage-bar"><span style="width:{{ $pct }}%"></span></div>
      <p class="muted" style="font-size:.82rem">{{ $pct }}% of {{ number_format($storage['limit_mb']) }} MB plan allowance</p>
    @endif
  </div>
  <div class="card" style="text-align:center;padding:1rem">
    <p class="muted" style="margin-bottom:.2rem">Database</p>
    <div style="font-size:1.9rem;font-weight:800">{{ \App\Services\Maintenance\StorageService::humanBytes($storage['database_bytes']) }}</div>
    <p class="muted" style="font-size:.82rem">Records: students, scores, attendance, everything</p>
  </div>
  <div class="card" style="text-align:center;padding:1rem">
    <p class="muted" style="margin-bottom:.2rem">Uploaded files</p>
    <div style="font-size:1.9rem;font-weight:800">{{ \App\Services\Maintenance\StorageService::humanBytes($storage['files_bytes']) }}</div>
    <p class="muted" style="font-size:.82rem">Photos, documents, evidence uploads</p>
  </div>
</div>

<div class="grid cols-2" style="align-items:start;margin-bottom:1.1rem">
  <div class="card">
    <h3 style="margin-bottom:.4rem">What's using the file storage</h3>
    <p class="muted" style="font-size:.85rem;margin-bottom:.8rem">Real sizes measured from the files on disk, largest first.</p>
    <ul class="storage-groups">
      @foreach($storage['files'] as $cat)
        <li style="display:block">
          <div style="display:flex;justify-content:space-between;gap:.6rem">
            <span>{{ $cat['label'] }}</span>
            <span class="muted" style="white-space:nowrap">{{ \App\Services\Maintenance\StorageService::humanBytes($cat['bytes']) }} &middot; {{ number_format($cat['count']) }} file(s)</span>
          </div>
          <div class="storage-bar" style="margin:.35rem 0 0"><span style="width:{{ $storage['files_bytes'] > 0 ? max(1, round($cat['bytes'] / max(1, $storage['files_bytes']) * 100)) : 0 }}%"></span></div>
        </li>
      @endforeach
    </ul>
    <p class="muted" style="font-size:.8rem;margin-top:.8rem;margin-bottom:0">
      Every uploaded image is automatically compressed to WebP at upload time, and report cards are
      generated fresh each time they are viewed rather than stored, so they use no storage at all.
    </p>
  </div>

  <div>
    <div class="card" style="margin-bottom:1.1rem">
      <h3 style="margin-bottom:.7rem">What's in the database</h3>
      <ul class="storage-groups">
        @foreach($storage['groups'] as $group)
          <li><span>{{ $group['label'] }}</span><span class="muted">{{ number_format($group['count']) }} record(s)</span></li>
        @endforeach
      </ul>
    </div>

    <div class="card">
      <h3 style="margin-bottom:.7rem">Backups</h3>
      <p style="margin-bottom:.6rem">{{ $storage['backups_count'] }} backup(s) kept, {{ \App\Services\Maintenance\StorageService::humanBytes($storage['backups_bytes']) }}</p>
      @if($canManage)
        <div style="display:flex;gap:.6rem;flex-wrap:wrap">
          <form method="POST" action="{{ route('school.backups.store', ['school' => $school->slug]) }}">
            @csrf
            <button class="btn btn-primary btn-sm" type="submit">Back up now</button>
          </form>
          @if($latestBackup)
            <a class="btn btn-ghost btn-sm" href="{{ route('school.backups.download', ['school' => $school->slug, 'backupId' => $latestBackup->id]) }}">Download latest ({{ $latestBackup->created_at->diffForHumans() }})</a>
          @endif
          <a class="btn btn-ghost btn-sm" href="{{ route('school.backups.index', ['school' => $school->slug]) }}">All backups</a>
        </div>
      @endif
    </div>
  </div>
</div>

@if($canManage)
<div class="card">
  <h3 style="margin-bottom:.4rem">Maintenance</h3>
  <p class="muted" style="margin-bottom:.8rem">Safe, on demand actions. These also run automatically every week.</p>
  <div style="display:flex;gap:.6rem;flex-wrap:wrap">
    <form method="POST" action="{{ route('school.maintenance.optimize', ['school' => $school->slug]) }}">
      @csrf
      <button class="btn btn-ghost btn-sm" type="submit">Optimize database now</button>
    </form>
    <form method="POST" action="{{ route('school.maintenance.retention', ['school' => $school->slug]) }}">
      @csrf
      <button class="btn btn-ghost btn-sm" type="submit">Run retention cleanup now</button>
    </form>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.settings.retention.edit', ['school' => $school->slug]) }}">Retention settings</a>
  </div>
</div>
@endif
@endsection
