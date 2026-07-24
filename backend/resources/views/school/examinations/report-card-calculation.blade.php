@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Term Calculation'])
@section('title', 'Term Calculation')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Term Calculation</h1>
    <p class="subtitle">For each term, choose how its report card treats the terms before it in the same session.</p>
  </div>
</div>

@if($sequenceLabels->isEmpty())
  <div class="card"><p class="muted">No terms have been set up yet.</p></div>
@else
  <form method="POST" action="{{ route('school.examinations.report-card-calculation.update', ['school' => $school->slug]) }}">
    @csrf
    <div class="card">
      <table>
        <thead>
          <tr>
            <th>Term</th>
            <th>Calculation</th>
          </tr>
        </thead>
        <tbody>
          @foreach($sequenceLabels as $sequence => $label)
            <tr>
              <td><strong>{{ $label }}</strong></td>
              <td>
                @if($sequence <= 1)
                  <span class="muted">This term only, nothing comes before it.</span>
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
    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:.8rem">Save</button>
  </form>
@endif
@endsection
