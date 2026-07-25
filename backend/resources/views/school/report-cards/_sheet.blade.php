{{--
  One student's report card sheet (extracted 2026-07-21 from render.blade.php
  so the bulk class-print view can include this once per student without
  duplicating ~80 lines of header/id/body markup). Expects $data, $template,
  $fields, $school, $layoutEnabled, $layoutZones, $headerAlign, $bodyAlign,
  $bodySections, $customBlocks — exactly what render.blade.php already
  builds per student via ReportCardController::show()/ReportCardService.
--}}
@php($show = fn($f) => $fields[$f] ?? false)
@php($isEval = in_array($template, ['evaluation', 'narrative'], true))
@php($logoUrl = $school->logo_path ? \Illuminate\Support\Facades\Storage::url($school->logo_path) : null)
<div class="rc-sheet {{ $template === 'modern' ? 'rc-modern' : ($template === 'compact' ? 'rc-compact' : ($isEval ? 'rc-eval' : '')) }}">
  @if(($layoutEnabled ?? false) && ! empty($layoutZones))
    <div class="rc-head rc-head-custom">
      @foreach(($layoutZones['header'] ?? []) as $block)
        <div style="align-self:{{ ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][($headerAlign ?? [])[$block] ?? 'center'] }}">
          @include('school.report-cards._layout-block', ['block' => $block])
        </div>
      @endforeach
    </div>
    @if(! empty($layoutZones['info']))
      <div class="rc-id">
        @foreach($layoutZones['info'] as $block)
          @include('school.report-cards._layout-block', ['block' => $block])
        @endforeach
      </div>
    @endif
  @else
  {{--
    Logo left, student photo right, name/address/term centered between
    them (user request 2026-07-24). Neither side renders anything, not
    even a placeholder, when the school has no logo or the student has
    no photo uploaded ("if there's no image uploaded for the
    learner/student, nothing appears there"). School address is its own
    toggle (report_card_fields 'school_address', on by default, "let's
    add the school address as a default thing to also appear on the
    report card header too, admin can decide to remove it").
  --}}
  {{--
    Address, website, email, and phone numbers all on the one row (user
    request 2026-07-24: "beside the school address, the same row let's
    have the school website, email address and all phone numbers"),
    each independently toggleable, only ever showing whichever the
    school actually has filled in and turned on. Falls back to the
    motto alone when none of these apply, the row's original purpose
    before this existed. Kept as one single inline PHP expression
    below, not a multi statement block: this codebase has hit a real
    Blade compiler quirk before where that shape, positioned next to
    certain directives, intermittently fails to compile (see
    render.blade.php's own note on the same issue), and writing the
    literal directive names in this very comment turned out to trigger
    the same quirk a second way, so it is deliberately paraphrased here.
  --}}
  @php($contactParts = collect([$show('school_address') ? $school->address : null, $show('school_website') ? $school->website : null, $show('school_email') ? $school->email : null, $show('school_phone') ? $school->phone : null, $show('school_alternate_phone') ? $school->alternate_phone : null])->filter()->values())
  <div class="rc-head">
    @if($logoUrl)<img class="rc-logo" src="{{ $logoUrl }}" alt="" />@endif
    <div class="rc-head-text">
      <h1>{{ $school->name }}</h1>
      @if($contactParts->isNotEmpty())
        <p>{{ $contactParts->implode(' • ') }}</p>
      @elseif($school->motto)
        <p>{{ $school->motto }}</p>
      @endif
      <div class="term">{{ $data['term']['name'] }} Report &middot; {{ $data['term']['session'] }}</div>
    </div>
    @if($data['identity']['photo_url'] ?? null)<img class="rc-photo" src="{{ $data['identity']['photo_url'] }}" alt="" />@endif
  </div>

  @if($isEval)
    <div class="rc-strip">
      <div><b>Name</b>{{ $data['identity']['name'] }}</div>
      @if($show('class'))<div><b>Class</b>{{ $data['identity']['class'] }}</div>@endif
      @if($show('next_term_begins'))<div><b>Next Term Begins</b>{{ $data['remarks']['next_term_begins_on'] ?: 'To be announced.' }}</div>@endif
    </div>
  @endif

  <div class="rc-id">
    @unless($isEval)<div><b>Name</b>{{ $data['identity']['name'] }}</div>@endunless
    @if($show('admission_number'))<div><b>Admission No.</b>{{ $data['identity']['admission_number'] }}</div>@endif
    @if($show('class') && ! $isEval)<div><b>Class</b>{{ $data['identity']['class'] }}</div>@endif
    @if($show('gender'))<div><b>Gender</b>{{ $data['identity']['gender'] }}</div>@endif
    @if($show('date_of_birth'))<div><b>Date of Birth</b>{{ $data['identity']['date_of_birth'] }}</div>@endif
    @if($show('age'))<div><b>Age</b>{{ $data['identity']['age'] }}</div>@endif
    @if($show('house'))<div><b>House</b>{{ $data['identity']['house'] }}</div>@endif
    @if($show('class_teacher'))<div><b>Class Teacher</b>{{ $data['identity']['class_teacher'] }}</div>@endif
    @if($show('attendance'))<div><b>Attendance</b>{{ $data['attendance']['present'] }} of {{ $data['attendance']['total'] }}</div>@endif
  </div>
  @endif

  {{--
    Rows of one or more sections (user request 2026-07-24: "let the
    report card designer also allow one to insert columns into a
    section of the report card so one can have items side by side...
    admin selects the rows and columns needed"). $bodyRows is
    ReportCardLayout::groupIntoRows($bodySections), computed once in the
    controller so the real report card here and the designer's own live
    preview (layout.blade.php) build identical rows from the same rule.
    Falls back to one section per row if a caller has not passed it.
  --}}
  @php($rows = $bodyRows ?? collect($bodySections)->map(fn ($k) => [$k])->all())
  @foreach($rows as $row)
    @if(count($row) === 1)
      @php($sectionKey = $row[0])
      @if((($bodyAlign ?? [])[$sectionKey] ?? 'stretch') === 'stretch')
        @if(str_starts_with($sectionKey, 'custom_'))
          @include('school.report-cards._body-custom', ['customBlock' => ($customBlocks ?? [])[$sectionKey] ?? null])
        @else
          @includeIf('school.report-cards._body-'.$sectionKey)
        @endif
      @else
        <div style="max-width:75%;{{ ['left' => 'margin-right:auto', 'right' => 'margin-left:auto', 'center' => 'margin-left:auto;margin-right:auto'][$bodyAlign[$sectionKey]] }}">
          @if(str_starts_with($sectionKey, 'custom_'))
            @include('school.report-cards._body-custom', ['customBlock' => ($customBlocks ?? [])[$sectionKey] ?? null])
          @else
            @includeIf('school.report-cards._body-'.$sectionKey)
          @endif
        </div>
      @endif
    @else
      <div class="rc-cols" style="--rc-cols:{{ count($row) }}">
        @foreach($row as $sectionKey)
          <div>
            @if(str_starts_with($sectionKey, 'custom_'))
              @include('school.report-cards._body-custom', ['customBlock' => ($customBlocks ?? [])[$sectionKey] ?? null])
            @else
              @includeIf('school.report-cards._body-'.$sectionKey)
            @endif
          </div>
        @endforeach
      </div>
    @endif
  @endforeach

  {{--
    Footer (user request 2026-07-24: "a footer for report card where
    the school type what they want as the footer but by default picks
    the motto under school profile and use, can be place anywhere at
    the footer, remember our one report card approach"). Lives in this
    one shared partial, so it renders the same way regardless of which
    template a school has chosen. "Place anywhere at the footer" is the
    left/center/right alignment choice below (Report settings), not a
    body-order position, a footer is always the very last thing on the
    page.
  --}}
  @php($footerContent = $footerText ?? $school->motto)
  @if($footerContent)
    <div class="rc-footer" style="text-align:{{ $footerAlign ?? 'center' }}">{{ $footerContent }}</div>
  @endif
</div>
