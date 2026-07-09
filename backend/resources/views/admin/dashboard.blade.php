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
</style>
@endpush

@section('content')
<div class="pd-figs">
  <div class="pd-fig"><b>{{ $totalSchools }}</b><span>Total Schools</span></div>
  <div class="pd-fig"><b>{{ $byStatus['active'] }}</b><span>Active</span></div>
  <div class="pd-fig"><b>{{ $totalAdministrators }}</b><span>Administrators</span></div>
  <div class="pd-fig"><b>{{ $trashedCount }}</b><span>In Archive</span></div>
</div>

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
