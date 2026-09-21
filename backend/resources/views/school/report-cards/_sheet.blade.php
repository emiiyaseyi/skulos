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
{{-- A school's own word for "Term" (user directive 2026-09-21), 'Term' unless the school set one under School Profile. --}}
@php($rcTermWord = \App\Support\TermLabel::current())
{{-- Resolved by ReportCardService::forStudent() per the STUDENT'S OWN
     section, falling back to the school default (user request
     2026-09-09) -- not read straight off $school any more. --}}
@php($logoUrl = $data['logo_url'] ?? null)
{{-- Section watermark (user directive 2026-09-21): opt-in per section,
     off unless that section's own ReportCardSection branding turned it
     on, in which case ReportCardService already resolved it to the
     same logo already printed in the header. Purely decorative, so it
     is excluded from print's page-count math the same way every other
     background/border decoration already is. --}}
@php($watermarkUrl = $data['watermark_url'] ?? null)
@php($rcTemplateClasses = ['modern' => 'rc-modern', 'compact' => 'rc-compact', 'bordered' => 'rc-bordered', 'vibrant' => 'rc-vibrant'])
<div class="rc-sheet {{ $rcTemplateClasses[$template] ?? ($isEval ? 'rc-eval' : '') }}">
  @if($watermarkUrl)
    <div class="rc-watermark" style="background-image:url('{{ $watermarkUrl }}')" aria-hidden="true"></div>
  @endif
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
  @php($rcAddress = $show('school_address') ? $school->address : null)
  @php($rcWebsite = $show('school_website') ? $school->website : null)
  @php($rcEmail = $show('school_email') ? $school->email : null)
  @php($rcPhones = collect([$show('school_phone') ? $school->phone : null, $show('school_alternate_phone') ? $school->alternate_phone : null])->filter()->values())
  @php($rcHasContact = $rcAddress || $rcWebsite || $rcEmail || $rcPhones->isNotEmpty())
  {{-- Icon glyphs kept small and inline rather than pulled from the
       shared nav icon partial, this row needs a muted monochrome mark
       sized to the report card's own tiny contact text, not the nav's
       accent colored square (user request 2026-07-27: "let's add little
       icons representing website, email and phone number"). --}}
  @php($rcIcoWebsite = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>')
  @php($rcIcoMail = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/></svg>')
  @php($rcIcoPhone = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>')
  <div class="rc-head">
    @if($logoUrl)<img class="rc-logo" src="{{ $logoUrl }}" alt="" />@endif
    <div class="rc-head-text">
      <h1>{{ $school->name }}</h1>
      @if($rcHasContact)
        @if($rcAddress)<p>{{ $rcAddress }}</p>@endif
        @if($rcWebsite || $rcEmail || $rcPhones->isNotEmpty())
          <p class="rc-contact-line">
            @if($rcWebsite)<span class="rc-contact-item">{!! $rcIcoWebsite !!}<a href="{{ str_starts_with($rcWebsite, 'http') ? $rcWebsite : 'https://'.$rcWebsite }}">{{ $rcWebsite }}</a></span>@endif
            @if($rcEmail)<span class="rc-contact-item">{!! $rcIcoMail !!}<a href="mailto:{{ $rcEmail }}">{{ $rcEmail }}</a></span>@endif
            @if($rcPhones->isNotEmpty())<span class="rc-contact-item">{!! $rcIcoPhone !!}{{ $rcPhones->implode(' • ') }}</span>@endif
          </p>
        @endif
      @elseif($school->motto)
        <p>{{ $school->motto }}</p>
      @endif
      @php($rcSessionYears = $data['term']['session'] ? trim(preg_replace('/\s*Session$/i', '', $data['term']['session'])) : null)
      @php($rcReportTypeLabel = ($data['report_type'] ?? 'full_term') === 'mid_term' ? 'Mid '.$rcTermWord.' Report' : 'Report')
      <div class="term">{{ $data['term']['name'] }} {{ $rcReportTypeLabel }} @if($rcSessionYears && ! str_contains($data['term']['name'], $rcSessionYears))&middot; {{ $data['term']['session'] }}@endif</div>
    </div>
    {{-- No student photo on a narrative report (user directive
         2026-09-21: "remmber that the creche, reception and KG classes
         of nursery does not use student image on the report card"),
         matching the reference report card's own header, which has no
         photo box at all -- logo left, name/address centered, nothing
         on the right. --}}
    @if($show('student_photo_header') && ! $isEval)
      @if($data['identity']['photo_url'] ?? null)
        <img class="rc-photo" src="{{ $data['identity']['photo_url'] }}" alt="" />
      @elseif($show('student_photo_avatar_fallback'))
        @php($rcIsFemale = strtolower((string) ($data['identity']['gender'] ?? '')) === 'female')
        <div class="rc-photo">
          @if($rcIsFemale)
            <svg viewBox="0 0 100 100" role="img" aria-label="Avatar"><rect width="100" height="100" fill="#f4e0ea"/><circle cx="50" cy="42" r="20" fill="#c98bab"/><path d="M28 40c0-14 10-24 22-24s22 10 22 24c0 4-1 8-2 10-2-10-8-6-8-16-6 6-20 6-26 0 0 10-6 6-8 16-1-2-2-6-2-10z" fill="#6b3550"/><path d="M18 96c2-20 14-32 32-32s30 12 32 32z" fill="#c98bab"/></svg>
          @else
            <svg viewBox="0 0 100 100" role="img" aria-label="Avatar"><rect width="100" height="100" fill="#dfe7f2"/><circle cx="50" cy="42" r="20" fill="#7a97bd"/><path d="M30 40c0-13 9-22 20-22s20 9 20 22" fill="#3c526f"/><path d="M18 96c2-20 14-32 32-32s30 12 32 32z" fill="#7a97bd"/></svg>
          @endif
        </div>
      @endif
    @endif
  </div>

  @if($isEval)
    {{-- Name/Gender/Class/Session in one row (user reference report card,
         2026-09-21: "NAME: ... GENDER: ... CLASS: ... SESSION: ..."),
         Gender/Session added alongside the pre-existing Name/Class/Next
         Term Begins strip, which previously dropped both entirely for
         the narrative template even though every other template's own
         identity grid already carries them. --}}
    <div class="rc-strip">
      <div><b>Name</b>{{ $data['identity']['name'] }}</div>
      @if($show('gender'))<div><b>Gender</b>{{ $data['identity']['gender'] }}</div>@endif
      @if($show('class'))<div><b>Class</b>{{ $data['identity']['class'] }}</div>@endif
      @if($data['term']['session'] ?? null)<div><b>Session</b>{{ $data['term']['session'] }}</div>@endif
      @if($show('next_term_begins'))<div><b>Next {{ $rcTermWord }} Begins</b>{{ $data['remarks']['next_term_begins_on'] ?: 'To be announced.' }}</div>@endif
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
