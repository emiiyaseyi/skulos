@extends('layouts.app', ['accent' => $accent])
@section('title', 'Report Card')
@section('bare', true)

@push('head')
<style>
  body{background:#e9ebee}
  .rc-wrap{max-width:56rem;margin:0 auto;padding:2rem 1.25rem 4rem}
  .rc-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem}
  .rc-bar .tag{font-size:.75rem;font-weight:700;padding:.3rem .7rem;border-radius:999px;background:var(--accent);color:#241503}
  .rc-sheet{background:#fff;color:#1a1a1a;border-radius:10px;padding:2rem;box-shadow:0 8px 30px rgba(0,0,0,.12)}
  .rc-head{text-align:center;border-bottom:3px solid var(--accent);padding-bottom:1rem;margin-bottom:1rem}
  .rc-head h1{font-size:1.4rem;font-weight:800}
  .rc-head p{font-size:.85rem;color:#555}
  .rc-head .term{font-size:.95rem;font-weight:700;margin-top:.4rem;text-transform:uppercase;letter-spacing:.04em}
  .rc-id{display:grid;grid-template-columns:repeat(4,1fr);gap:.6rem;font-size:.82rem;margin-bottom:1.1rem}
  .rc-id div b{display:block;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;color:#777;font-weight:700}
  .rc-table{width:100%;border-collapse:collapse;font-size:.82rem;margin-bottom:1rem}
  .rc-table th,.rc-table td{border:1px solid #d8d8d8;padding:.45rem .5rem;text-align:left}
  .rc-table th{background:#f3f4f6;font-size:.7rem;text-transform:uppercase;letter-spacing:.03em}
  .rc-table td.num,.rc-table th.num{text-align:center;font-variant-numeric:tabular-nums}
  .rc-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:.7rem;margin:1rem 0}
  .rc-summary .box{background:#f7f7f8;border-radius:8px;padding:.7rem;text-align:center}
  .rc-summary .box b{font-size:1.15rem;display:block}
  .rc-summary .box span{font-size:.68rem;text-transform:uppercase;color:#777;font-weight:700}
  .rc-remarks div{margin-bottom:.7rem;font-size:.85rem}
  .rc-remarks b{display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.03em;color:#777;margin-bottom:.15rem}
  .rc-tally{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem}
  .rc-tally span{background:#f3f4f6;border-radius:6px;padding:.25rem .55rem;font-size:.75rem;font-weight:700}
  /* modern */
  .rc-modern .rc-head{background:linear-gradient(135deg,var(--accent),#2a2a2a);color:#fff;border-radius:10px 10px 0 0;border-bottom:none;padding:1.4rem}
  .rc-modern .rc-head p{color:rgba(255,255,255,.85)}
  .rc-modern .photo{width:64px;height:64px;border-radius:12px;background:#eee;margin:0 auto .6rem;display:grid;place-items:center;font-weight:800;color:#888}
  /* compact */
  .rc-compact .rc-table{font-size:.78rem}
  .rc-compact .rc-summary{grid-template-columns:repeat(3,1fr)}
  @media print{ body{background:#fff} .rc-bar{display:none} .rc-sheet{box-shadow:none} }
</style>
@endpush

@section('content')
@php($show = fn($f) => $fields[$f] ?? false)
<div class="rc-wrap">
  <div class="rc-bar">
    @if($isPreview)<span class="tag">Preview, sample data</span>@else<span></span>@endif
    <button class="btn btn-ghost btn-sm" onclick="window.print()" type="button">Print</button>
  </div>

  <div class="rc-sheet {{ $template === 'modern' ? 'rc-modern' : ($template === 'compact' ? 'rc-compact' : '') }}">
    <div class="rc-head">
      @if($template === 'modern')<div class="photo">{{ strtoupper(substr($data['identity']['name'],0,1)) }}</div>@endif
      <h1>{{ $school->name }}</h1>
      <p>{{ $school->address ?: $school->motto }}</p>
      <div class="term">{{ $data['term']['name'] }} Report &middot; {{ $data['term']['session'] }}</div>
    </div>

    <div class="rc-id">
      <div><b>Name</b>{{ $data['identity']['name'] }}</div>
      @if($show('admission_number'))<div><b>Admission No.</b>{{ $data['identity']['admission_number'] }}</div>@endif
      @if($show('class'))<div><b>Class</b>{{ $data['identity']['class'] }}</div>@endif
      @if($show('gender'))<div><b>Gender</b>{{ $data['identity']['gender'] }}</div>@endif
      @if($show('date_of_birth'))<div><b>Date of Birth</b>{{ $data['identity']['date_of_birth'] }}</div>@endif
      @if($show('age'))<div><b>Age</b>{{ $data['identity']['age'] }}</div>@endif
      @if($show('house'))<div><b>House</b>{{ $data['identity']['house'] }}</div>@endif
      @if($show('class_teacher'))<div><b>Class Teacher</b>{{ $data['identity']['class_teacher'] }}</div>@endif
      @if($show('attendance'))<div><b>Attendance</b>{{ $data['attendance']['present'] }} of {{ $data['attendance']['total'] }}</div>@endif
    </div>

    @if($template === 'formal' && $data['subjects'])
      @php($tally = collect($data['subjects'])->countBy('grade'))
      <div class="rc-tally">
        @foreach($tally as $grade => $count)
          <span>{{ $grade }}: {{ $count }}</span>
        @endforeach
      </div>
    @endif

    @php($firstSubject = $data['subjects'][0] ?? null)
    @php($priorTermNames = $firstSubject ? array_keys($firstSubject['previous_terms']) : [])

    <div style="overflow-x:auto">
    <table class="rc-table">
      <thead>
        <tr>
          <th>Subject</th>
          @if($template !== 'compact')
            @foreach(($firstSubject['components'] ?? []) as $componentName => $ignored)
              <th class="num">{{ $componentName }}</th>
            @endforeach
          @endif
          @if($show('cumulative_terms'))
            @foreach($priorTermNames as $termName)
              <th class="num">{{ $termName }}</th>
            @endforeach
          @endif
          <th class="num">Total</th>
          @if($show('cumulative_terms') && count($priorTermNames))<th class="num">Average</th>@endif
          <th class="num">Grade</th>
          @if($template === 'modern' && $show('subject_position'))<th class="num">Position</th>@endif
          @if($template === 'modern' && $show('class_average'))<th class="num">Class Avg</th>@endif
          @if($template === 'modern' && $show('subject_highest'))<th class="num">Highest</th>@endif
          @if($template === 'modern' && $show('subject_lowest'))<th class="num">Lowest</th>@endif
          @if($template !== 'compact')<th>Remark</th>@endif
        </tr>
      </thead>
      <tbody>
        @foreach($data['subjects'] as $subject)
          <tr>
            <td>{{ $subject['name'] }}</td>
            @if($template !== 'compact')
              @foreach($subject['components'] as $score)
                <td class="num">{{ $score }}</td>
              @endforeach
            @endif
            @if($show('cumulative_terms'))
              @foreach($priorTermNames as $termName)
                <td class="num">{{ $subject['previous_terms'][$termName] ?? 'N/A' }}</td>
              @endforeach
            @endif
            <td class="num"><strong>{{ $subject['total'] }}</strong></td>
            @if($show('cumulative_terms') && count($priorTermNames))<td class="num">{{ $subject['cumulative_average'] }}</td>@endif
            <td class="num"><strong>{{ $subject['grade'] }}</strong></td>
            @if($template === 'modern' && $show('subject_position'))<td class="num">{{ $subject['position'] }}</td>@endif
            @if($template === 'modern' && $show('class_average'))<td class="num">{{ $subject['class_average'] }}</td>@endif
            @if($template === 'modern' && $show('subject_highest'))<td class="num">{{ $subject['highest'] }}</td>@endif
            @if($template === 'modern' && $show('subject_lowest'))<td class="num">{{ $subject['lowest'] }}</td>@endif
            @if($template !== 'compact')<td>{{ $subject['remark'] }}</td>@endif
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    <div class="rc-summary">
      <div class="box"><b>{{ $data['overall']['total'] }}</b><span>Total Score</span></div>
      <div class="box"><b>{{ $data['overall']['average'] }}%</b><span>Average</span></div>
      @if($show('overall_position'))
        <div class="box"><b>{{ $data['overall']['position'] }}</b><span>Position</span></div>
        <div class="box"><b>{{ $data['overall']['class_size'] }}</b><span>Class Size</span></div>
      @endif
    </div>

    @if($show('class_teacher_remark') || $show('principal_remark') || $show('next_term_begins'))
      <div class="rc-remarks">
        @if($show('class_teacher_remark'))
          <div><b>Class Teacher's Remark</b>{{ $data['remarks']['class_teacher'] ?: 'Not yet entered.' }}</div>
        @endif
        @if($show('principal_remark'))
          <div><b>Head of School's Remark</b>{{ $data['remarks']['principal'] ?: 'Not yet entered.' }}</div>
        @endif
        @if($show('next_term_begins'))
          <div><b>Next Term Begins</b>{{ $data['remarks']['next_term_begins_on'] ?: 'To be announced.' }}</div>
        @endif
      </div>
    @endif
  </div>
</div>
@endsection
