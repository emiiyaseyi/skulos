@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Class Teacher Entry'])
@section('title', 'Class Teacher Entry')

@push('head')
<style>
  {{-- Wider than table-stack's 11rem default for a stacked remark field, a short input like Height/Club is fine at 11rem but a remark needs room to actually write in. --}}
  @media(max-width:640px){
    .ce-table td textarea{max-width:100%;width:100%}
  }
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Class Teacher Entry &middot; {{ $class->name }}</h1>
    <p class="subtitle">Physical stats, behavioural traits, and remarks for {{ $term->name }}. Fill in what applies, then save once for the whole class.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.class-index', ['school' => $school->slug, 'classId' => $class->id, 'term_id' => $term->id]) }}">Back to class</a>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.4rem">Stamp and signature</h3>
  <p class="muted" style="margin-bottom:.8rem">Used on report cards for this class arm. Leave blank to keep using the school's default, uploaded under Settings &gt; Branding.</p>
  <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
    @foreach($class->arms as $arm)
      <form method="POST" action="{{ route('school.report-cards.arm.stamp-signature', ['school' => $school->slug, 'armId' => $arm->id]) }}" enctype="multipart/form-data" style="border:1px solid var(--line);border-radius:10px;padding:.8rem">
        @csrf
        <div style="font-weight:700;font-size:.85rem;margin-bottom:.5rem">{{ $arm->label() }}</div>
        <div style="display:flex;gap:.8rem;margin-bottom:.6rem;align-items:flex-end;flex-wrap:wrap">
          <div>
            @if($arm->stamp_path)<img src="{{ \Illuminate\Support\Facades\Storage::url($arm->stamp_path) }}" alt="Stamp" style="height:36px;display:block;margin-bottom:.3rem" />@endif
            <label class="muted" for="stamp-{{ $arm->id }}">Stamp</label>
            <input id="stamp-{{ $arm->id }}" name="stamp" type="file" accept="image/*" style="max-width:11rem" />
          </div>
          <div>
            @if($arm->signature_path)<img src="{{ \Illuminate\Support\Facades\Storage::url($arm->signature_path) }}" alt="Signature" style="height:36px;display:block;margin-bottom:.3rem" />@endif
            <label class="muted" for="signature-{{ $arm->id }}">Signature</label>
            <input id="signature-{{ $arm->id }}" name="signature" type="file" accept="image/*" style="max-width:11rem" />
          </div>
        </div>
        <button class="btn btn-ghost btn-sm" type="submit">Save</button>
      </form>
    @endforeach
  </div>
</div>

<div class="card">
  @if($students->isEmpty())
    <p class="muted">No students in this class yet.</p>
  @elseif($narrativeStyle === 'skills' && $skillAreas->isEmpty() && $traits->isEmpty())
    <p class="muted">No active behavioural traits or Key Assessment Areas are set up yet for this narrative style. <a href="{{ route('school.report-cards.traits.index', ['school' => $school->slug]) }}">Add some</a>, then come back here.</p>
  @elseif($narrativeStyle !== 'skills' && $traits->isEmpty() && $subjects->isEmpty())
    <p class="muted">No active behavioural traits are set up yet. <a href="{{ route('school.report-cards.traits.index', ['school' => $school->slug]) }}">Add some</a>, then come back here.</p>
  @else
    @if($narrativeStyle !== 'skills')
    <div class="row-between" style="margin-bottom:1rem;flex-wrap:wrap;gap:.6rem">
      <p class="muted" style="margin:0;font-size:.85rem">Prefer a spreadsheet? Download everyone's current details, fill in what's missing, and upload it back.</p>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
        <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.class.entry.template', ['school' => $school->slug, 'classId' => $class->id, 'term_id' => $term->id]) }}">Download template</a>
        <form method="POST" action="{{ route('school.report-cards.class.entry.upload', ['school' => $school->slug, 'classId' => $class->id]) }}" enctype="multipart/form-data" style="display:flex;gap:.5rem;align-items:center">
          @csrf
          <input type="hidden" name="term_id" value="{{ $term->id }}" />
          <input type="file" name="file" accept=".csv" required />
          <button class="btn btn-ghost btn-sm" type="submit">Upload</button>
        </form>
      </div>
    </div>
    @else
    <p class="muted" style="margin-bottom:1rem;font-size:.85rem">Key Assessment Area remarks are entered on screen only for now, there is no spreadsheet round trip for this narrative style yet.</p>
    @endif
    <form method="POST" action="{{ route('school.report-cards.class.entry.update', ['school' => $school->slug, 'classId' => $class->id]) }}">
      @csrf
      <input type="hidden" name="term_id" value="{{ $term->id }}" />
      {{-- A genuine wide data-entry grid, one row per student, every
           cell a real input/select/textarea (fixed physical stats,
           traits, per-subject comments, remarks). Same .table-stack
           mobile treatment already proven on the score entry and
           attendance screens: below 640px this becomes one card per
           student with a labelled field for each column, rather than
           forcing horizontal scrolling through a dozen tiny inputs one
           handed. The remark textareas get a wider stacked width than
           the table-stack default (11rem is fine for a short input,
           too narrow to actually write a remark in). --}}
      <div style="overflow-x:auto">
      <table class="table-stack ce-table">
        <thead><tr>
          <th style="position:sticky;left:0;background:var(--panel)">Student</th>
          <th>Height</th><th>Weight</th><th>Club</th>
          @foreach($traits as $trait)
            <th>{{ $trait->name }}</th>
          @endforeach
          @if($narrativeStyle === 'skills')
            @foreach($skillAreas as $area)
              <th style="min-width:14rem">{{ $area->category }} &middot; {{ $area->name }}</th>
            @endforeach
          @else
            @foreach($subjects as $subject)
              <th>{{ $subject->name }} comment</th>
            @endforeach
          @endif
          <th style="min-width:14rem">Class teacher's remark</th>
          @if($canEnterPrincipalRemark)<th style="min-width:14rem">Head of school's remark</th>@endif
        </tr></thead>
        <tbody>
          @foreach($students as $student)
            @php
              $remark = $remarks->get($student->id);
              $studentRatings = ($ratings->get($student->id) ?? collect())->keyBy('behavioural_trait_id');
            @endphp
            <tr>
              <td style="position:sticky;left:0;background:var(--panel)" data-label="Student"><strong>{{ $student->fullName() }}</strong></td>
              <td data-label="Height"><input name="students[{{ $student->id }}][height]" value="{{ $remark?->height }}" placeholder="e.g. 111cm" style="min-width:6rem" /></td>
              <td data-label="Weight"><input name="students[{{ $student->id }}][weight]" value="{{ $remark?->weight }}" placeholder="e.g. 18kg" style="min-width:6rem" /></td>
              <td data-label="Club"><input name="students[{{ $student->id }}][club]" value="{{ $remark?->club }}" style="min-width:7rem" /></td>
              @foreach($traits as $trait)
                <td data-label="{{ $trait->name }}">
                  @if($trait->type === 'score')
                    <input type="number" min="0" max="{{ $trait->max_score }}" name="students[{{ $student->id }}][traits][{{ $trait->id }}]" value="{{ $studentRatings->get($trait->id)?->value }}" style="min-width:5rem" />
                  @else
                    <select name="students[{{ $student->id }}][traits][{{ $trait->id }}]" style="min-width:8rem">
                      <option value="">Not set</option>
                      @foreach(config('skulos.behavioural_trait_remark_scale', []) as $option)
                        <option value="{{ $option }}" @selected($studentRatings->get($trait->id)?->value === $option)>{{ $option }}</option>
                      @endforeach
                    </select>
                  @endif
                </td>
              @endforeach
              @if($narrativeStyle === 'skills')
                @foreach($skillAreas as $area)
                  <td data-label="{{ $area->category }} &middot; {{ $area->name }}"><textarea name="students[{{ $student->id }}][skill_remarks][{{ $area->id }}]" rows="2" style="min-width:14rem">{{ $skillRemarks->get($student->id.'-'.$area->id)?->first()?->remark }}</textarea></td>
                @endforeach
              @else
                @foreach($subjects as $subject)
                  <td data-label="{{ $subject->name }} comment"><input name="students[{{ $student->id }}][comments][{{ $subject->id }}]" value="{{ $comments->get($student->id.'-'.$subject->id)?->first()?->comment }}" style="min-width:14rem" /></td>
                @endforeach
              @endif
              <td data-label="Class teacher's remark"><textarea name="students[{{ $student->id }}][class_teacher_remark]" rows="2" style="min-width:14rem">{{ $remark?->class_teacher_remark }}</textarea></td>
              @if($canEnterPrincipalRemark)
                <td data-label="Head of school's remark"><textarea name="students[{{ $student->id }}][principal_remark]" rows="2" style="min-width:14rem">{{ $remark?->principal_remark }}</textarea></td>
              @endif
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
      <button class="btn btn-primary btn-sm" type="submit" style="margin-top:1rem">Save class entry</button>
    </form>
  @endif
</div>
@endsection
