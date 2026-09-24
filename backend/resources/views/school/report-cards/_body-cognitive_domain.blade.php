{{--
  Cognitive Domain (user directive 2026-09-24, the school's own Basic 1 to 5
  report card): the class's whole subject list under group labels written
  sideways down the left (Languages, Maths & Sciences, Arts & Culture), one
  column per assessment (Test 1, Test 2, Exam...), a "Marks Obtainable" row,
  the total, the class average, grade and remark, and an overall
  performance line. Every subject the class offers is listed, a blank cell
  where it has no score yet, like the school's paper sheet. Subjects with
  no group sit in the same table without a side label.
--}}
@php
  $cogCols = $data['component_columns'] ?? [];
  $cogMax = $data['component_max'] ?? [];
  $cogScores = collect($data['subjects'] ?? [])->keyBy('name');
  $cogList = collect($data['offered_subjects'] ?? []);
  foreach ($cogScores as $cogName => $cogRow) {
    if (! $cogList->contains('name', $cogName)) {
      $cogList->push(['name' => $cogName, 'category' => $cogRow['category'] ?? null]);
    }
  }
  $cogGroups = $cogList->groupBy(fn ($s) => $s['category'] ?: '');
  $cogSpan = 2 + count($cogCols) + 4;
  // Overall Performance (user directive 2026-09-24): the student's total
  // score as a percentage of the marks available across EVERY subject the
  // class offers, i.e. total / (subjects offered x marks obtainable) x 100,
  // so a subject not yet scored still counts in the divisor.
  $cogPerSubject = array_sum($cogMax) ?: 100;
  $cogTotal = collect($data['subjects'] ?? [])->sum('total');
  $cogOverall = ($cogList->count() && count($data['subjects'] ?? []))
    ? round($cogTotal / ($cogList->count() * $cogPerSubject) * 100, 2)
    : null;
@endphp
@if($cogList->isNotEmpty())
  <div class="rc-block rc-cognitive-block">
    <table class="rc-bordered-table rc-cognitive">
      <thead>
        <tr><th colspan="{{ $cogSpan }}">Cognitive Domain</th></tr>
        <tr>
          <th colspan="2" style="text-align:left">Class Subjects</th>
          @foreach($cogCols as $c)<th>{{ $c }}</th>@endforeach
          <th>Total</th>
          <th rowspan="2">Class Average</th>
          <th rowspan="2">Grade</th>
          <th rowspan="2">Remark</th>
        </tr>
        <tr class="rc-marks-row">
          <th colspan="2" style="text-align:left">Marks Obtainable</th>
          @foreach($cogCols as $c)<th>{{ isset($cogMax[$c]) ? rtrim(rtrim(number_format($cogMax[$c], 2, '.', ''), '0'), '.') : '' }}</th>@endforeach
          <th>{{ rtrim(rtrim(number_format(array_sum($cogMax), 2, '.', ''), '0'), '.') ?: '' }}</th>
        </tr>
      </thead>
      <tbody>
        @foreach($cogGroups as $groupLabel => $groupSubjects)
          @foreach($groupSubjects as $i => $sub)
            @php($row = $cogScores[$sub['name']] ?? null)
            <tr>
              @if($groupLabel !== '' && $i === 0)
                <td rowspan="{{ $groupSubjects->count() }}" class="rc-vgroup"><span>{{ $groupLabel }}</span></td>
              @endif
              <td @if($groupLabel === '') colspan="2" @endif>{{ $sub['name'] }}</td>
              @foreach($cogCols as $c)<td class="num">{{ $row['components'][$c] ?? '' }}</td>@endforeach
              <td class="num"><strong>{{ $row['total'] ?? '' }}</strong></td>
              <td class="num">{{ $row['class_average'] ?? '' }}</td>
              <td class="num"><strong>{{ $row['grade'] ?? '' }}</strong></td>
              <td class="num">{{ $row['remark'] ?? '' }}</td>
            </tr>
          @endforeach
        @endforeach
        <tr class="rc-overall-row">
          <td colspan="2"><b>Overall Performance</b></td>
          <td colspan="{{ count($cogCols) + 4 }}" class="num"><strong>{{ $cogOverall !== null ? $cogOverall.'%' : '' }}</strong></td>
        </tr>
      </tbody>
    </table>
  </div>
@endif
