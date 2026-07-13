@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Dashboard'])
@section('title', 'Platform Dashboard')
@section('heading', 'Platform Dashboard')
@section('crumb', 'How SKULOS is doing across every school')

@push('head')
<style>
  .pd-figs{display:grid;grid-template-columns:repeat(4,1fr);gap:1.1rem;margin-bottom:1.5rem}
  .pd-fig{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:1.2rem 1.3rem;box-shadow:var(--shadow)}
  .pd-fig b{font-size:1.7rem;font-weight:800;display:block}
  .pd-fig span{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim)}
  .pd-donut{width:120px;height:120px;border-radius:50%;margin:0 auto;
    background:conic-gradient(
      #12A97B 0 {{ $donutPct['active'] }}%,
      #E5901C {{ $donutPct['active'] }}% {{ $donutPct['active'] + $donutPct['suspended'] }}%,
      #E14F63 {{ $donutPct['active'] + $donutPct['suspended'] }}% {{ $donutPct['active'] + $donutPct['suspended'] + $donutPct['archived'] }}%,
      var(--soft) {{ $donutPct['active'] + $donutPct['suspended'] + $donutPct['archived'] }}% 100%)}
  .pd-legend{display:flex;flex-direction:column;gap:.5rem;margin-top:1rem}
  .pd-legend span{display:flex;align-items:center;gap:.5rem;font-size:.85rem}
  .pd-legend i{width:10px;height:10px;border-radius:50%;display:inline-block}
  .pd-activity{list-style:none;display:flex;flex-direction:column;gap:.7rem}
  .pd-activity li{display:flex;justify-content:space-between;gap:1rem;padding-bottom:.7rem;border-bottom:1px solid var(--line);font-size:.85rem}
  .pd-activity li:last-child{border-bottom:none;padding-bottom:0}
  .pd-activity .who{font-weight:700}
  .pd-activity .when{color:var(--mut-dim);white-space:nowrap}
  .pd-alert{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;
    background:color-mix(in srgb,var(--warn) 14%,var(--panel));border:1px solid color-mix(in srgb,var(--warn) 40%,transparent);
    border-radius:var(--radius);padding:1rem 1.3rem;margin-bottom:1.25rem}
  .pd-bars{display:flex;align-items:flex-end;gap:.8rem;height:140px;margin-bottom:.5rem}
  .pd-bar{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:.4rem}
  .pd-bar .col{width:100%;background:color-mix(in srgb,var(--accent) 70%,transparent);border-radius:6px 6px 0 0;min-height:3px}
  .pd-bar span{font-size:.72rem;color:var(--mut-dim)}
</style>
@endpush

@section('content')
@if($pendingTrials > 0)
  <div class="pd-alert">
    <span><strong>{{ $pendingTrials }}</strong> new trial {{ \Illuminate\Support\Str::plural('request', $pendingTrials) }} waiting for your review.</span>
    <a class="btn btn-primary btn-sm" href="{{ route('admin.trial-requests.index') }}">Review now</a>
  </div>
@endif

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.8rem">Quick actions</h3>
  <div style="display:flex;gap:.6rem;flex-wrap:wrap">
    <a class="btn btn-primary btn-sm" href="{{ route('admin.schools.index') }}">Add school</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.schools.index') }}">Clone a school</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.billing.index') }}">Create plan</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.trial-requests.index') }}">Review trial requests</a>
  </div>
</div>

<div class="pd-figs">
  <div class="pd-fig"><b>{{ $totalSchools }}</b><span>Total Schools</span></div>
  <div class="pd-fig"><b>{{ $byStatus['active'] }}</b><span>Active Schools</span></div>
  <div class="pd-fig"><b>{{ $pendingTrials }}</b><span>Trial Schools</span></div>
  <div class="pd-fig"><b>{{ $activeSubscriptions }}</b><span>Active Subscriptions</span></div>
  <div class="pd-fig"><b>{{ $totalUsers }}</b><span>Total Users</span></div>
  <div class="pd-fig"><b>{{ $totalAdministrators }}</b><span>Administrators</span></div>
  <div class="pd-fig"><b>{{ number_format($crossSchoolTotals['students']) }}</b><span>Students</span></div>
  <div class="pd-fig"><b>{{ number_format($crossSchoolTotals['staff']) }}</b><span>Staff</span></div>
  <div class="pd-fig"><b>{{ number_format($crossSchoolTotals['parents']) }}</b><span>Parents</span></div>
  <div class="pd-fig"><b>{{ $trashedCount }}</b><span>In Archive</span></div>
  <div class="pd-fig"><b>{{ $conversionRate !== null ? $conversionRate.'%' : 'N/A' }}</b><span>Trial Conversion</span></div>
  <div class="pd-fig"><b>{{ $schoolsWithoutBackup }}</b><span>Schools Without a Backup</span></div>
</div>
@if($crossSchoolTotals['schools_counted'] < $totalSchools)
  <p class="muted" style="font-size:.78rem;margin:-1rem 0 1.5rem">Students/Staff/Parents totals reflect {{ $crossSchoolTotals['schools_counted'] }} of {{ $totalSchools }} schools, the rest could not be reached just now.</p>
@endif

<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <h3 style="margin-bottom:1rem">Schools by status</h3>
    <div class="pd-donut"></div>
    <div class="pd-legend">
      <span><i style="background:#12A97B"></i> Active ({{ $byStatus['active'] }})</span>
      <span><i style="background:#E5901C"></i> Suspended ({{ $byStatus['suspended'] }})</span>
      <span><i style="background:#E14F63"></i> Archived ({{ $byStatus['archived'] }})</span>
      <span><i style="background:var(--soft);border:1px solid var(--line)"></i> Pending ({{ $byStatus['pending'] }})</span>
    </div>
  </div>

  <div class="card">
    <h3 style="margin-bottom:1rem">School growth</h3>
    <div class="pd-bars">
      @foreach($growthMonths as $m)
        <div class="pd-bar">
          <span>{{ $m['count'] }}</span>
          <div class="col" style="height:{{ max(3, round($m['count'] / $growthMax * 100)) }}%"></div>
          <span>{{ $m['label'] }}</span>
        </div>
      @endforeach
    </div>
    <p class="muted" style="font-size:.8rem">New schools provisioned per month, last 6 months.</p>
  </div>
</div>

<div class="grid cols-2" style="align-items:start;margin-top:1.25rem">
  <div class="card">
    <h3 style="margin-bottom:1rem">Subscriptions by plan</h3>
    @if($subscriptionBreakdown->isEmpty())
      <p class="muted">No schools yet.</p>
    @else
      <ul class="pd-activity">
        @foreach($subscriptionBreakdown as $row)
          <li><span>{{ $row['plan'] }}</span><span class="when">{{ $row['count'] }}</span></li>
        @endforeach
      </ul>
    @endif
  </div>

  <div class="card">
    <h3 style="margin-bottom:1rem">Recently created</h3>
    @if($recentSchools->isEmpty())
      <p class="muted">No schools created yet.</p>
    @else
      <ul class="pd-activity">
        @foreach($recentSchools as $s)
          <li>
            <span><a href="{{ route('admin.schools.show', $s) }}">{{ $s->name }}</a></span>
            <span class="when">{{ $s->created_at?->diffForHumans() }}</span>
          </li>
        @endforeach
      </ul>
    @endif
  </div>
</div>

<div class="card" style="margin-top:1.25rem">
  <h3 style="margin-bottom:.3rem">Server health</h3>
  <p class="muted" style="font-size:.78rem;margin-bottom:1rem">A self check, not full infrastructure monitoring, which is not wired in yet.</p>
  <div class="grid cols-3">
    <div>
      <div class="muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem">Database</div>
      @if($serverHealth['database_ok'])
        <div style="color:#12A97B;font-weight:700">Responding ({{ $serverHealth['database_ms'] }}ms)</div>
      @else
        <div style="color:#E14F63;font-weight:700">Not responding</div>
      @endif
    </div>
    <div>
      <div class="muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem">Disk Free</div>
      <div style="font-weight:700">{{ $serverHealth['disk_free_pct'] !== null ? $serverHealth['disk_free_pct'].'% ('.$serverHealth['disk_free_gb'].' GB)' : 'N/A' }}</div>
    </div>
    <div>
      <div class="muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem">Queue Driver</div>
      <div style="font-weight:700">{{ ucfirst($serverHealth['queue_driver']) }}</div>
    </div>
  </div>
</div>

<div class="card" style="margin-top:1.25rem">
  <h3 style="margin-bottom:1rem">Recent platform activity</h3>
  @if($recentActivity->isEmpty())
    <p class="muted">Nothing recorded yet.</p>
  @else
    <ul class="pd-activity">
      @foreach($recentActivity as $log)
        <li>
          <span><span class="who">{{ $log->user?->name ?? 'System' }}</span> &middot; {{ str_replace('.', ' ', $log->action) }}</span>
          <span class="when">{{ $log->created_at?->diffForHumans() }}</span>
        </li>
      @endforeach
    </ul>
  @endif
</div>
@endsection
