@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Import Questions'])
@section('title', 'Import Questions')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Import Questions</h1>
    <p class="subtitle">Bring questions in from a spreadsheet or a standard question file.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.questions.index', ['school' => $school->slug]) }}">Back to question bank</a>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if($errors->any())
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ $errors->first() }}</div>
@endif

@if($preview)
  <div class="card" style="margin-bottom:1.25rem;border-left:4px solid var(--accent)">
    <h3 style="margin-bottom:.5rem">Preview &mdash; nothing has been imported yet</h3>
    <p class="muted" style="margin-bottom:.8rem">
      {{ $preview['count'] }} question(s) parsed from your {{ strtoupper($preview['format']) }} file.
      @if($preview['errors'])
        {{ count($preview['errors']) }} row(s) would be skipped.
      @endif
      Check the rows below, then run the import for real without ticking "Preview only".
    </p>

    @if($preview['errors'])
      <div class="flash flash-bad" style="margin-bottom:.8rem">
        <strong>Rows that would be skipped:</strong>
        <ul style="margin:.4rem 0 0 1rem">
          @foreach(array_slice($preview['errors'], 0, 10) as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    @if($preview['sample'])
      <div style="overflow-x:auto">
      <table class="table-stack">
        <thead><tr><th>Type</th><th>Question</th><th>Marks</th><th>Options</th><th>Correct</th></tr></thead>
        <tbody>
          @foreach($preview['sample'] as $row)
            <tr>
              <td class="muted" data-label="Type">{{ ucfirst(str_replace('_', ' ', $row['type'])) }}</td>
              <td data-label="Question">{{ $row['prompt'] }}</td>
              <td class="muted" data-label="Marks">{{ $row['points'] }}</td>
              <td class="muted" data-label="Options">{{ $row['answers'] }}</td>
              <td data-label="Correct">
                @if($row['type'] === 'essay')
                  <span class="muted">Manually graded</span>
                @elseif($row['correct'] === 0)
                  <span class="badge badge-warn">No correct answer</span>
                @else
                  <span class="badge badge-ok">{{ $row['correct'] }}</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
      @if($preview['count'] > count($preview['sample']))
        <p class="muted" style="margin-top:.6rem">Showing the first {{ count($preview['sample']) }} of {{ $preview['count'] }}.</p>
      @endif
    @endif
  </div>
@endif

<div class="grid cols-2" style="gap:1.1rem;align-items:start">
  <div class="card">
    <h3 style="margin-bottom:.8rem">Upload a file</h3>
    <form method="POST" action="{{ route('school.cbt.questions.import', ['school' => $school->slug]) }}" enctype="multipart/form-data">
      @csrf
      <div class="field">
        <label for="format">Format</label>
        <select id="format" name="format" required onchange="skulosShowFormatHelp()">
          @foreach($formats as $key => $label)
            <option value="{{ $key }}" @selected(old('format') === $key)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="file">File</label>
        <input id="file" name="file" type="file" />
        <p class="muted" style="font-size:.78rem;margin-top:.3rem">Up to 10 MB.</p>
      </div>

      <p class="muted" style="text-align:center;margin:.8rem 0">&mdash; or paste directly &mdash;</p>

      <div class="field">
        <label for="pasted_text">Paste question text</label>
        <textarea id="pasted_text" name="pasted_text" rows="12"
                  style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.82rem"
                  placeholder="{{ $placeholders['csv'] ?? '' }}">{{ old('pasted_text') }}</textarea>
        <p class="muted" style="font-size:.78rem;margin-top:.3rem">If you upload a file as well, the file is used and this box is ignored.</p>
      </div>

      <div class="field">
        <label for="category_id">Import into category</label>
        <select id="category_id" name="category_id">
          <option value="">Uncategorised</option>
          @foreach($categories as $category)
            <option value="{{ $category->id }}">{{ $category->name }}</option>
          @endforeach
        </select>
        <p class="muted" style="font-size:.78rem;margin-top:.3rem">A category named inside the file itself always wins over this.</p>
      </div>
      <label style="display:flex;align-items:flex-start;gap:.5rem;margin:.6rem 0">
        <input type="checkbox" name="dry_run" value="1" style="margin-top:.2rem" />
        <span>Preview only &mdash; show me what would be imported without saving anything</span>
      </label>
      <button class="btn btn-primary btn-block" type="submit">Import questions</button>
    </form>
  </div>

  <div class="card">
    <h3 style="margin-bottom:.8rem">Formats and sample files</h3>
    <p class="muted" style="margin-bottom:.8rem">Download a filled-in sample for any format, edit it, and upload it back.</p>

    @foreach($formats as $key => $label)
      <div class="cbt-fmt" data-format="{{ $key }}" style="border:1px solid var(--line);border-radius:8px;padding:.7rem;margin-bottom:.6rem">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin-bottom:.4rem">
          <strong>{{ $label }}</strong>
          <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.questions.import.template', ['school' => $school->slug, 'format' => $key]) }}">Sample file</a>
        </div>
        <p class="muted" style="font-size:.8rem;margin:0">
          @switch($key)
            @case('csv')
              Columns: <code>type,question,a1..a6,correct,marks,case_sensitive,marking_guide</code>. Types: multichoice, multicheck, truefalse, shortanswer, essay. For choice questions <code>correct</code> is the option letter(s), e.g. <code>C</code> or <code>A,B</code>.
              @break
            @case('gift')
              The standard GIFT text format: <code>::Title:: Question text {=right ~wrong}</code>, or <code>{TRUE}</code> / <code>{FALSE}</code>. Add per-option feedback after a <code>#</code>.
              @break
            @case('aiken')
              The standard Aiken format: the question on one line, then <code>A) option</code> lines, then <code>ANSWER: B</code>.
              @break
            @case('shortanswer')
              Plain text. Question on the first line, then one <code>Answer:</code> line per accepted spelling. Optional <code>Case: sensitive</code> and <code>Marks: 2</code>.
              @break
            @case('essay')
              Plain text. Question on the first line, then optional <code>Mark: 3</code> and <code>Answer guide: ...</code> (the guide is shown to graders only).
              @break
            @case('moodlexml')
              A Moodle question-bank XML export. Answer <code>fraction</code> is a percentage, so <code>100</code> marks the correct option.
              @break
            @case('json')
              This app's own JSON export. Round-trips every field including partial credit and per-option feedback.
              @break
          @endswitch
        </p>
      </div>
    @endforeach
  </div>
</div>

@push('scripts')
<script>
  var skulosPastePlaceholders = @json($placeholders);

  function skulosShowFormatHelp() {
    var chosen = document.getElementById('format').value;

    document.querySelectorAll('.cbt-fmt').forEach(function (el) {
      var active = el.dataset.format === chosen;
      el.style.borderColor = active ? 'var(--accent)' : 'var(--line)';
      el.style.borderWidth = active ? '2px' : '1px';
    });

    // The paste box's example follows the chosen format, so it always
    // shows the layout that would actually parse.
    var paste = document.getElementById('pasted_text');
    if (paste) paste.placeholder = skulosPastePlaceholders[chosen] || '';
  }
  document.addEventListener('DOMContentLoaded', skulosShowFormatHelp);
</script>
@endpush
@endsection
