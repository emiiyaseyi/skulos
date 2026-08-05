@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Assessments'])
@section('title', 'Assessments')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Assessments</h1>
    <p class="subtitle">Each child's assessments, at a glance.</p>
  </div>
</div>

@if($cards->isEmpty())
  <div class="card">
    <p class="muted">No children linked to your account yet.</p>
  </div>
@else
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr));gap:1rem">
    @foreach($cards as $card)
      @php($student = $card['student'])
      <a href="{{ route('school.cbt.parent.child', ['school' => $school->slug, 'studentId' => $student->id]) }}" class="card" style="text-decoration:none;color:inherit;display:block">
        <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.9rem">
          <div class="avatar" style="width:2.6rem;height:2.6rem;border-radius:50%;background:var(--soft);display:flex;align-items:center;justify-content:center;font-weight:800">{{ mb_substr($student->first_name, 0, 1) }}</div>
          <div>
            <div style="font-weight:800">{{ $student->fullName() }}</div>
            <div class="muted" style="font-size:.8rem">{{ $student->currentClassArm?->label() ?? 'No class' }}</div>
          </div>
        </div>

        @if($card['total'] === 0)
          <p class="muted" style="margin:0">No assessments assigned right now.</p>
        @else
          <div class="grid cols-3" style="gap:.5rem;text-align:center">
            <div>
              <div style="font-size:1.3rem;font-weight:800">{{ $card['total'] }}</div>
              <div class="muted" style="font-size:.72rem;text-transform:uppercase">Total</div>
            </div>
            <div>
              <div style="font-size:1.3rem;font-weight:800;color:var(--ok)">{{ $card['completed'] }}</div>
              <div class="muted" style="font-size:.72rem;text-transform:uppercase">Completed</div>
            </div>
            <div>
              <div style="font-size:1.3rem;font-weight:800;color:var(--warn)">{{ $card['left'] }}</div>
              <div class="muted" style="font-size:.72rem;text-transform:uppercase">Left</div>
            </div>
          </div>
          @if($card['inProgress'] > 0)
            <p class="muted" style="margin:.6rem 0 0;font-size:.8rem;text-align:center">{{ $card['inProgress'] }} in progress</p>
          @endif
        @endif
      </a>
    @endforeach
  </div>
@endif
@endsection
