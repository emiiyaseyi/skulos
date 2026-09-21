@php($termWord = \App\Support\TermLabel::current())
@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $termWord.' Calculation'])
@section('title', $termWord.' Calculation')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $termWord }} Calculation</h1>
    <p class="subtitle">For each {{ strtolower($termWord) }}, choose how its report card treats the {{ strtolower($termWord) }}s before it in the same session.</p>
  </div>
</div>

@if($sequenceLabels->isEmpty())
  <div class="card"><p class="muted">No {{ strtolower($termWord) }}s have been set up yet.</p></div>
@else
  <form method="POST" action="{{ route('school.examinations.report-card-calculation.update', ['school' => $school->slug]) }}">
    @csrf
    <div class="card">
      <table>
        <thead>
          <tr>
            <th>{{ $termWord }}</th>
            <th>Calculation</th>
          </tr>
        </thead>
        <tbody>
          @foreach($sequenceLabels as $sequence => $label)
            <tr>
              <td><strong>{{ $label }}</strong></td>
              <td>
                @if($sequence <= 1)
                  <span class="muted">This {{ strtolower($termWord) }} only, nothing comes before it.</span>
                @else
                  <select name="sequence[{{ $sequence }}]">
                    @foreach($modes as $code => $mode)
                      <option value="{{ $code }}" @selected(($policy[$sequence] ?? $defaultMode) === $code)>{{ $mode['label'] }}</option>
                    @endforeach
                  </select>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <div class="card" style="margin-top:.8rem">
      <h3 style="margin-bottom:.5rem">What each option means</h3>
      @foreach($modes as $mode)
        <p class="muted" style="margin-bottom:.4rem"><strong>{{ $mode['label'] }}</strong>: {{ $mode['description'] }}</p>
      @endforeach
    </div>

    {{--
      Per-class-only toggle (user request 2026-07-26): "per-class-only
      toggle option" for cumulative calculation, by default it applies to
      every class, this restricts it to specific classes only, e.g. a
      school might want it for Primary but not a WAEC/NECO graded
      Secondary section.
    --}}
    <div class="card" style="margin-top:.8rem">
      <h3 style="margin-bottom:.3rem">Which classes this applies to</h3>
      <p class="muted" style="margin-bottom:.6rem">By default, cumulative calculation applies to every class. Turn this on to restrict it to specific classes only.</p>
      <label style="display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem;font-weight:600">
        <input type="checkbox" id="restrictToggle" name="restrict_to_classes" value="1" @checked($restrictedToClasses) onchange="document.getElementById('classRestrictionList').style.display=this.checked?'':'none'" />
        Restrict cumulative calculation to specific classes only
      </label>
      <div id="classRestrictionList" style="display:{{ $restrictedToClasses ? '' : 'none' }}">
        @if($classes->isEmpty())
          <p class="muted">No classes have been set up yet.</p>
        @else
          <div style="overflow-x:auto">
          <table class="table-stack">
            <thead>
              <tr>
                <th style="width:2.5rem"><input type="checkbox" id="selectAllClasses" aria-label="Select every class" /></th>
                <th>Class</th>
                <th>Section</th>
              </tr>
            </thead>
            <tbody>
              @foreach($classes as $class)
                <tr>
                  <td><input type="checkbox" class="cumulative-class-check" name="class_ids[]" value="{{ $class->id }}" @checked(in_array($class->id, $includedClassIds)) /></td>
                  <td data-label="Class">{{ $class->name }}</td>
                  <td class="muted" data-label="Section">{{ $class->section?->name }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
          </div>
        @endif
      </div>
    </div>

    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:.8rem">Save</button>
  </form>
  <script>
    document.getElementById('selectAllClasses')?.addEventListener('change', function () {
      document.querySelectorAll('.cumulative-class-check').forEach(function (c) { c.checked = document.getElementById('selectAllClasses').checked; });
    });
  </script>
@endif
@endsection
