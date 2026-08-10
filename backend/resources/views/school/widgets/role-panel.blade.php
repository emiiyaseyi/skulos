@php
  $total = ($panel['boys'] ?? 0) + ($panel['girls'] ?? 0);
  $boysPct = $total ? round(($panel['boys'] / $total) * 100) : 0;
  $girlsPct = $total ? round(($panel['girls'] / $total) * 100) : 0;
  // The boys/girls split respects the same chart_style setting every
  // other chart does (user directive 2026-07-28: even though it is a
  // proportion breakdown, not a trend, the school can still choose
  // either presentation for it) — a donut for 'line', a simple 2-bar
  // comparison for 'bar', rather than a "line" rendering that would not
  // mean anything for a two-category split.
  $genderChartStyle = \App\Models\Tenant\Setting::grab('chart_style', config('skulos.default_chart_style', 'line'));
@endphp

<style>
  .rp-card{margin-bottom:1.5rem}
  .rp-donut{width:96px;height:96px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;
    background:conic-gradient(#6D4FE0 calc(var(--boys,0) * 1%), #E14F63 0)}
  .rp-donut i{width:70px;height:70px;border-radius:50%;background:var(--panel);display:grid;place-items:center;font-weight:800;font-size:.85rem;text-align:center}
  .rp-gender-bars{display:flex;flex-direction:column;gap:.4rem;width:9rem;flex-shrink:0}
  .rp-gender-bars .track{background:var(--bg);border-radius:6px;height:.6rem;overflow:hidden}
  .rp-gender-bars .fill{height:100%;border-radius:6px}
  .rp-gender-bars .lbl{display:flex;justify-content:space-between;font-size:.7rem;color:var(--mut-dim);margin-bottom:.15rem}
  .rp-legend{display:flex;gap:1.2rem;margin-top:.8rem}
  .rp-legend span{display:flex;align-items:center;gap:.4rem;font-size:.82rem}
  .rp-legend i{width:10px;height:10px;border-radius:50%;display:inline-block}
  .rp-row{display:flex;gap:1.5rem;align-items:center;flex-wrap:wrap}
  .rp-list{list-style:none;display:flex;flex-direction:column;gap:.5rem}
  .rp-list li{display:flex;justify-content:space-between;align-items:center;gap:.6rem;flex-wrap:wrap;padding:.5rem .7rem;background:var(--bg);border-radius:8px;font-size:.88rem}
  .rp-list li .rp-text{display:flex;flex-direction:column;gap:.1rem}
</style>

@switch($panel['type'])

  @case('performance')
    <div class="card rp-card">
      <h3 style="margin-bottom:.3rem">Performance overview</h3>
      @if($panel['average'] === null)
        <p class="muted">No scores recorded yet{{ $panel['term'] ? " for {$panel['term']}" : '' }}.</p>
      @else
        <p class="muted">Average score across all recorded assessments in {{ $panel['term'] }}.</p>
        {{-- Same "big stat number" size as the shared .stat card (this widget always renders inside the school portal's .school-theme shell), not an improvised third size, per the 2026-07-28 text consistency audit. --}}
        <div style="font-size:1.9rem;font-weight:800;margin-top:.4rem">{{ $panel['average'] }}%</div>
      @endif
    </div>
    @break

  @case('class_overview')
    <div class="card rp-card">
      <div class="row-between" style="margin-bottom:.8rem">
        <h3>{{ $panel['class_label'] }} overview</h3>
        @if($panel['read_only'])<span class="badge">View only</span>@endif
      </div>
      <div class="rp-row">
        @if($genderChartStyle === 'bar')
          <div class="rp-gender-bars">
            <div>
              <div class="lbl"><span>Boys</span><span>{{ $panel['boys'] }} ({{ $boysPct }}%)</span></div>
              <div class="track"><div class="fill" style="width:{{ $boysPct }}%;background:#6D4FE0"></div></div>
            </div>
            <div>
              <div class="lbl"><span>Girls</span><span>{{ $panel['girls'] }} ({{ $girlsPct }}%)</span></div>
              <div class="track"><div class="fill" style="width:{{ $girlsPct }}%;background:#E14F63"></div></div>
            </div>
          </div>
        @else
          <div class="rp-donut" style="--boys:{{ $boysPct }}"><i>{{ $boysPct }}% boys</i></div>
        @endif
        <div>
          <div class="rp-legend">
            <span><i style="background:#6D4FE0"></i> Boys ({{ $panel['boys'] }})</span>
            <span><i style="background:#E14F63"></i> Girls ({{ $panel['girls'] }})</span>
          </div>
          <p class="muted" style="margin-top:.7rem">
            @if($panel['average'] === null)
              No scores recorded yet{{ $panel['term'] ? " for {$panel['term']}" : '' }}.
            @else
              Class average this term ({{ $panel['term'] }}): <strong style="color:var(--ink)">{{ $panel['average'] }}%</strong>
            @endif
          </p>
        </div>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem">
        {{-- Take attendance: every class-scoped role holds attendance.create
             (class-teacher and class-assistant alike), unlike CA/report
             cards which only a class teacher can actually enter, so this
             sits outside the read_only gate below. --}}
        <a class="btn btn-primary btn-sm" href="{{ route('school.attendance.index', ['school' => $school->slug, 'class_arm_id' => $panel['class_arm_id']]) }}">Take attendance</a>
        @unless($panel['read_only'])
          <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.index', ['school' => $school->slug]) }}">Enter CA &amp; exam scores</a>
          <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.index', ['school' => $school->slug]) }}">Report cards</a>
        @endunless
        <a class="btn btn-ghost btn-sm" href="{{ route('school.students', ['school' => $school->slug]) }}">My students</a>
        <a class="btn btn-ghost btn-sm" href="{{ route('school.chat.index', ['school' => $school->slug]) }}">Message parents</a>
      </div>
    </div>
    @include('school.widgets._students-at-risk', ['items' => $panel['attendanceAtRisk'] ?? [], 'title' => 'Attendance needing attention', 'subtitle' => 'Low attendance this term, an early flag, not a formal report.'])
    @include('school.widgets._students-at-risk', ['items' => $panel['performanceAtRisk'] ?? [], 'title' => 'Performance needing attention', 'subtitle' => 'Low average score this term, an early flag, not a formal report.'])
    @include('school.widgets._upcoming-exams', ['items' => $panel['upcomingExams'] ?? []])
    @include('school.widgets._upcoming-assignments', ['items' => $panel['upcomingAssignments'] ?? []])
    @include('school.widgets._birthdays', ['today' => $panel['birthdaysToday'] ?? [], 'upcoming' => $panel['birthdaysUpcoming'] ?? []])
    @break

  @case('subject_overview')
    <div class="card rp-card">
      <h3 style="margin-bottom:.7rem">My subjects and classes</h3>
      @foreach($panel['groups'] as $sectionName => $subjects)
        <div style="margin-bottom:.9rem">
          <div class="muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.35rem">{{ $sectionName }}</div>
          <ul class="rp-list">
            @foreach($subjects as $subjectName => $classLabels)
              <li><span><strong>{{ $subjectName }}</strong></span><span class="muted">{{ implode(', ', $classLabels) }}</span></li>
            @endforeach
          </ul>
        </div>
      @endforeach
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem">
        <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.index', ['school' => $school->slug]) }}">Enter CA &amp; exam scores</a>
        <a class="btn btn-ghost btn-sm" href="{{ route('school.students', ['school' => $school->slug]) }}">My students</a>
        <a class="btn btn-ghost btn-sm" href="{{ route('school.chat.index', ['school' => $school->slug]) }}">Message parents</a>
      </div>
    </div>
    @include('school.widgets._students-at-risk', ['items' => $panel['attendanceAtRisk'] ?? [], 'title' => 'Attendance needing attention', 'subtitle' => 'Low attendance this term, an early flag, not a formal report.'])
    @include('school.widgets._students-at-risk', ['items' => $panel['performanceAtRisk'] ?? [], 'title' => 'Performance needing attention', 'subtitle' => 'Low average score this term, an early flag, not a formal report.'])
    @include('school.widgets._upcoming-exams', ['items' => $panel['upcomingExams'] ?? []])
    @include('school.widgets._upcoming-assignments', ['items' => $panel['upcomingAssignments'] ?? []])
    @include('school.widgets._birthdays', ['today' => $panel['birthdaysToday'] ?? [], 'upcoming' => $panel['birthdaysUpcoming'] ?? []])
    @break

  @case('children')
    <div class="card rp-card">
      <h3 style="margin-bottom:.7rem">My children</h3>
      @if(empty($panel['children']))
        <p class="muted">No children linked to your account yet. Contact the school office to be linked.</p>
      @else
        <ul class="rp-list">
          @foreach($panel['children'] as $child)
            <li>
              <span class="rp-text">
                <strong>{{ $child['name'] }}</strong>
                <span class="muted">{{ $child['class'] ?? 'Not yet placed in a class' }}</span>
                @if($child['latestReportTerm'])
                  <span class="muted">Report card released for {{ $child['latestReportTerm'] }}{{ $child['latestReportReleasedAt'] ? ' ('.$child['latestReportReleasedAt']->diffForHumans().')' : '' }}</span>
                @else
                  <span class="muted">No report card released yet.</span>
                @endif
              </span>
              @if($child['id'])
                <a class="btn btn-ghost btn-sm" href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $child['id']]) }}">View Profile</a>
              @endif
            </li>
          @endforeach
        </ul>
        <div style="margin-top:1rem">
          <a class="btn btn-ghost btn-sm" href="{{ route('school.chat.index', ['school' => $school->slug]) }}">Chat with a teacher</a>
        </div>
      @endif
    </div>
    @include('school.widgets._upcoming-exams', ['items' => $panel['upcomingExams'] ?? []])
    @include('school.widgets._upcoming-assignments', ['items' => $panel['upcomingAssignments'] ?? []])
    @include('school.widgets._fees-due-popup', [
      'id' => 'fees-due-popup', 'title' => 'Fees due per child', 'school' => $school,
      'rows' => collect($panel['feePerChild'] ?? []),
    ])
    @include('school.widgets._stat-popup', [
      'id' => 'attendance-term-popup', 'title' => 'Attendance this term, per child', 'rows' => collect($panel['attendancePerChild'] ?? [])
        ->map(fn ($r) => ['name' => $r['name'], 'value' => $r['rate'] !== null ? "{$r['rate']}%" : 'Not marked yet', 'ok' => $r['rate'] === null || $r['rate'] >= 75]),
      'emptyText' => 'No attendance recorded yet.',
    ])
    @break

  @case('own_result')
    <div class="card rp-card">
      @if(! empty($panel['student_id']))
        <a class="btn btn-primary btn-sm" href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $panel['student_id']]) }}">View my report{{ $panel['term'] ? " ({$panel['term']})" : '' }}</a>
      @else
        <p class="muted">Your student record has not been linked to this login yet. Contact the school office if this seems wrong.</p>
      @endif
    </div>
    @include('school.widgets._upcoming-exams', ['items' => $panel['upcomingExams'] ?? []])
    @include('school.widgets._upcoming-assignments', ['items' => $panel['upcomingAssignments'] ?? []])
    @break

  @case('no_class')
    <div class="card rp-card"><p class="muted">You are not yet set as the class teacher of any class. Ask an administrator to assign you to a class.</p></div>
    @break

  @case('no_assignments')
    <div class="card rp-card"><p class="muted">You have no teaching assignments yet. Ask an administrator to assign you a subject and class.</p></div>
    @break

  @case('no_record')
    <div class="card rp-card"><p class="muted">Your student record has not been linked to this account yet. Contact the school office.</p></div>
    @break

  @case('pending_reviews')
    <div class="card rp-card">
      <h3 style="margin-bottom:.3rem">Report cards awaiting a decision</h3>
      @if(empty($panel['items']))
        <p class="muted">Nothing is waiting on review right now{{ $panel['term'] ? " for {$panel['term']}" : '' }}.</p>
      @else
        <ul class="rp-list">
          @foreach($panel['items'] as $item)
            <li><span><strong>{{ $item['student'] }}</strong> <span class="muted">{{ $item['class'] }}</span></span><span class="muted">{{ $item['status'] }}</span></li>
          @endforeach
        </ul>
        @if($panel['more'] > 0)
          <p class="muted" style="margin-top:.6rem">and {{ $panel['more'] }} more.</p>
        @endif
        <a class="btn btn-ghost btn-sm" style="margin-top:.8rem" href="{{ route('school.report-cards.pending-review', ['school' => $school->slug]) }}">Open pending review</a>
      @endif
    </div>
    @if(isset($panel['upcomingExams']))
      <div class="card rp-card">
        <h3 style="margin-bottom:.3rem">Upcoming exams</h3>
        @if(empty($panel['upcomingExams']))
          <p class="muted">No exams scheduled yet.</p>
        @else
          <ul class="rp-list">
            @foreach($panel['upcomingExams'] as $exam)
              <li><span><strong>{{ $exam['title'] }}</strong> <span class="muted">{{ $exam['subject'] }}{{ $exam['subject'] && $exam['class'] ? ' &middot; ' : '' }}{{ $exam['class'] }}</span></span><span class="muted">{{ $exam['date'] }}</span></li>
            @endforeach
          </ul>
        @endif
        <a class="btn btn-ghost btn-sm" style="margin-top:.8rem" href="{{ route('school.calendar.index', ['school' => $school->slug]) }}">Open calendar</a>
      </div>
    @endif
    @break

  @case('registrar_overview')
    <div class="card rp-card">
      @if($panel['unassigned'] > 0)
        <p class="muted">{{ $panel['unassigned'] }} student(s) still need a class assigned.</p>
        <a class="btn btn-ghost btn-sm" style="margin-top:.6rem" href="{{ route('school.students', ['school' => $school->slug]) }}">Review students</a>
      @else
        <p class="muted">Every active student has a class assigned.</p>
      @endif
    </div>
    @break

  @case('ict_overview')
    <div class="card rp-card">
      @if(! $panel['has_backup'])
        <p class="muted">No backup has been taken yet.</p>
      @else
        <p class="muted">Most recent backup shown above.</p>
      @endif
      <a class="btn btn-ghost btn-sm" style="margin-top:.6rem" href="{{ route('school.backups.index', ['school' => $school->slug]) }}">Manage backups</a>
    </div>
    <div class="card rp-card">
      <h3 style="margin-bottom:.3rem">Recent failed logins</h3>
      <p class="muted" style="margin-bottom:.8rem">Last 7 days. Tracked passively, no account is locked automatically.</p>
      @if(empty($panel['recentFailedLogins']))
        <p class="muted">No failed sign in attempts in the last 7 days.</p>
      @else
        <ul class="rp-list">
          @foreach($panel['recentFailedLogins'] as $attempt)
            <li><span><strong>{{ $attempt['identifier'] }}</strong> <span class="muted">{{ $attempt['reason'] }}{{ $attempt['ip'] ? ' &middot; '.$attempt['ip'] : '' }}</span></span><span class="muted">{{ $attempt['when'] }}</span></li>
          @endforeach
        </ul>
      @endif
    </div>
    @break

  @case('coming_soon')
    <div class="card rp-card">
      <h3 style="margin-bottom:.4rem">Your {{ $panel['role'] }} dashboard is coming soon</h3>
      <p class="muted">{{ $panel['module'] }} is not built into SKULOS yet, so there is nothing real to show here. Once it is, this dashboard will show your own figures, not placeholder numbers.</p>
    </div>
    @break

  @case('finance_overview')
    <div class="card rp-card">
      @if($panel['pendingCount'] > 0)
        <p class="muted">{{ $panel['pendingCount'] }} payment(s) awaiting verification.</p>
        <a class="btn btn-primary btn-sm" style="margin-top:.6rem" href="{{ route('school.finance.payments.index', ['school' => $school->slug, 'status' => 'pending']) }}">Review pending payments</a>
      @else
        <p class="muted">No payments awaiting verification right now.</p>
      @endif
    </div>
    @break

  @case('people_only')
    <div class="card rp-card">
      <p class="muted">{{ $panel['module'] }} is not built into SKULOS yet, so this dashboard shows what is already real: the people you will eventually manage through it. More figures will appear here as {{ $panel['module'] }} is built.</p>
    </div>
    @break

  @case('admin_overview')
    {{-- UX audit finding (2026-08-10): this panel's own "Quick actions"
         card rendered a second, older, un-permission-gated set of
         buttons directly beneath dashboard.blade.php's own newer, richer
         "Quick actions" card (added 2026-07-13, every button individually
         permission-checked) for the exact same wildcard-tier roles —
         two panels with the same heading, stacked, reads as a duplicated
         component rather than a deliberate two-tier design. Removed here
         rather than from dashboard.blade.php since that one is the more
         complete, better-gated of the two (staff/students/report
         cards/results/announcements/events vs. just students/report
         cards/results here, with no permission checks at all). --}}
    <div class="grid cols-2" style="margin-bottom:1.1rem">
      <div class="card rp-card" style="margin-bottom:0">
        <h3 style="margin-bottom:.6rem">Today's birthdays</h3>
        @if(empty($panel['birthdaysToday']))
          <p class="muted">No student birthdays today.</p>
        @else
          <ul class="rp-list">
            @foreach($panel['birthdaysToday'] as $b)
              {{-- The party-popper emoji this row used to end with read as informal/childish against the rest of the dashboard's tone (2026-07-28 text consistency audit); "Today's birthdays" already says what this list is. --}}
              <li><span><strong>{{ $b['name'] }}</strong> <span class="muted">{{ $b['age'] !== null ? $b['age'].' yrs' : '' }} {{ $b['class'] }}</span></span></li>
            @endforeach
          </ul>
        @endif
      </div>

      <div class="card rp-card" style="margin-bottom:0">
        <h3 style="margin-bottom:.6rem">Upcoming birthdays</h3>
        @if(empty($panel['birthdaysUpcoming']))
          <p class="muted">No birthdays in the next 30 days.</p>
        @else
          <ul class="rp-list">
            @foreach($panel['birthdaysUpcoming'] as $b)
              <li><span><strong>{{ $b['name'] }}</strong> <span class="muted">{{ $b['age'] !== null ? $b['age'].' yrs' : '' }} {{ $b['class'] }}</span></span><span class="muted">{{ $b['date'] }}</span></li>
            @endforeach
          </ul>
        @endif
      </div>
    </div>

    <div class="card rp-card">
      <h3 style="margin-bottom:.3rem">Report cards awaiting a decision</h3>
      @if(empty($panel['pendingReviews']))
        <p class="muted">Nothing is waiting on review right now{{ $panel['term'] ? " for {$panel['term']}" : '' }}.</p>
      @else
        <ul class="rp-list">
          @foreach($panel['pendingReviews'] as $item)
            <li><span><strong>{{ $item['student'] }}</strong> <span class="muted">{{ $item['class'] }}</span></span><span class="muted">{{ $item['status'] }}</span></li>
          @endforeach
        </ul>
        @if($panel['pendingReviewsMore'] > 0)
          <p class="muted" style="margin-top:.6rem">and {{ $panel['pendingReviewsMore'] }} more.</p>
        @endif
        <a class="btn btn-ghost btn-sm" style="margin-top:.8rem" href="{{ route('school.report-cards.pending-review', ['school' => $school->slug]) }}">Open pending review</a>
      @endif
    </div>

    @include('school.widgets._students-at-risk', ['items' => $panel['attendanceAtRisk'] ?? [], 'title' => 'Attendance at risk', 'subtitle' => 'Low attendance this term, an early flag, not a formal report.'])

    @include('school.widgets._students-at-risk', ['items' => $panel['performanceAtRisk'] ?? [], 'title' => 'Performance at risk', 'subtitle' => 'Low average score this term, an early flag, not a formal report.'])

    @if(! empty($panel['resultTrend']))
      {{--
        A bar chart forced onto a fixed 0-100% ceiling (the earlier fix
        for this widget, 2026-07-16) is mathematically correct, but for a
        school whose averages genuinely cluster tightly (e.g. 81.6% to
        84.1% after the same day's score rescale), the bars still read as
        visually flat, a real report from the user ("THIS HAS NOT BEEN
        FIXED") after seeing exactly that, which is why the shared
        _trend-line partial's own line mode zooms its axis to the data's
        own range rather than a fixed 0-100 ceiling. The user's own bar
        option (2026-07-22) is a deliberate, informed choice at that
        point, not the same silent-flattening bug, since it is opted
        into rather than the only rendering available.
      --}}
      <div class="card rp-card">
        <h3 style="margin-bottom:.3rem">Result performance</h3>
        <p class="muted" style="margin-bottom:.8rem">Average score across every term with results recorded so far.</p>
        @include('school.widgets._trend-line', [
          'values' => array_column($panel['resultTrend'], 'average'),
          'labels' => array_column($panel['resultTrend'], 'label'),
          'ariaLabel' => 'Result performance trend across terms',
        ])
      </div>
    @endif
    @break
@endswitch
