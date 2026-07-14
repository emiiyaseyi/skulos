@php
  $total = ($panel['boys'] ?? 0) + ($panel['girls'] ?? 0);
  $boysPct = $total ? round(($panel['boys'] / $total) * 100) : 0;
@endphp

<style>
  .rp-card{margin-bottom:1.5rem}
  .rp-donut{width:96px;height:96px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;
    background:conic-gradient(#6D4FE0 calc(var(--boys,0) * 1%), #E14F63 0)}
  .rp-donut i{width:70px;height:70px;border-radius:50%;background:var(--panel);display:grid;place-items:center;font-weight:800;font-size:.85rem;text-align:center}
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
        <div style="font-size:2rem;font-weight:800;margin-top:.4rem">{{ $panel['average'] }}%</div>
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
        <div class="rp-donut" style="--boys:{{ $boysPct }}"><i>{{ $boysPct }}% boys</i></div>
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
      @unless($panel['read_only'])
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem">
          <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.index', ['school' => $school->slug]) }}">Enter CA &amp; exam scores</a>
          <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.index', ['school' => $school->slug]) }}">Report cards</a>
          <a class="btn btn-ghost btn-sm" href="{{ route('school.students', ['school' => $school->slug]) }}">My students</a>
        </div>
      @endunless
    </div>
    @break

  @case('subject_overview')
    <div class="card rp-card">
      <h3 style="margin-bottom:.7rem">My subjects and classes</h3>
      @foreach($panel['groups'] as $sectionName => $subjects)
        <div style="margin-bottom:.9rem">
          <div class="muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.35rem">{{ $sectionName }}</div>
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
      </div>
    </div>
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
              <span class="rp-text"><strong>{{ $child['name'] }}</strong><span class="muted">{{ $child['class'] ?? 'Not yet placed in a class' }}</span></span>
              @if($child['id'])
                <a class="btn btn-ghost btn-sm" href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $child['id']]) }}">View report</a>
              @endif
            </li>
          @endforeach
        </ul>
      @endif
    </div>
    @break

  @case('own_result')
    <div class="card rp-card">
      @if(! empty($panel['student_id']))
        <a class="btn btn-primary btn-sm" href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $panel['student_id']]) }}">View my report{{ $panel['term'] ? " ({$panel['term']})" : '' }}</a>
      @else
        <p class="muted">Your student record has not been linked to this login yet. Contact the school office if this seems wrong.</p>
      @endif
    </div>
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
    @break

  @case('coming_soon')
    <div class="card rp-card">
      <h3 style="margin-bottom:.4rem">Your {{ $panel['role'] }} dashboard is coming soon</h3>
      <p class="muted">{{ $panel['module'] }} is not built into SKULOS yet, so there is nothing real to show here. Once it is, this dashboard will show your own figures, not placeholder numbers.</p>
    </div>
    @break

  @case('people_only')
    <div class="card rp-card">
      <p class="muted">{{ $panel['module'] }} is not built into SKULOS yet, so this dashboard shows what is already real: the people you will eventually manage through it. More figures will appear here as {{ $panel['module'] }} is built.</p>
    </div>
    @break

  @case('admin_overview')
    <div class="card rp-card">
      <h3 style="margin-bottom:.6rem">Quick actions</h3>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap">
        <a class="btn btn-primary btn-sm" href="{{ route('school.students.create', ['school' => $school->slug]) }}">Add student</a>
        <a class="btn btn-ghost btn-sm" href="{{ route('school.students.import', ['school' => $school->slug]) }}">Bulk import students</a>
        <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.index', ['school' => $school->slug]) }}">Report cards</a>
        <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.index', ['school' => $school->slug]) }}">Results</a>
      </div>
    </div>

    <div class="grid cols-2" style="align-items:start;margin-bottom:1.1rem">
      <div class="card rp-card" style="margin-bottom:0">
        <h3 style="margin-bottom:.6rem">Today's birthdays</h3>
        @if(empty($panel['birthdaysToday']))
          <p class="muted">No student birthdays today.</p>
        @else
          <ul class="rp-list">
            @foreach($panel['birthdaysToday'] as $b)
              <li><span><strong>{{ $b['name'] }}</strong> <span class="muted">{{ $b['age'] !== null ? $b['age'].' yrs' : '' }} {{ $b['class'] }}</span></span><span>&#127881;</span></li>
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

    @if(! empty($panel['resultTrend']))
      <div class="card rp-card">
        <h3 style="margin-bottom:.3rem">Result performance</h3>
        <p class="muted" style="font-size:.8rem;margin-bottom:.8rem">Average score across every term with results recorded so far.</p>
        @php($trendMax = max(1, ...array_column($panel['resultTrend'], 'average')))
        <div style="display:flex;align-items:flex-end;gap:.8rem;height:120px">
          @foreach($panel['resultTrend'] as $t)
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:.35rem">
              <span class="muted" style="font-size:.72rem;font-variant-numeric:tabular-nums">{{ $t['average'] }}%</span>
              <div style="width:100%;background:color-mix(in srgb,var(--accent) 70%,transparent);border-radius:6px 6px 0 0;min-height:3px;height:{{ max(4, round($t['average'] / $trendMax * 100)) }}%"></div>
              <span class="muted" style="font-size:.72rem;text-align:center">{{ $t['label'] }}</span>
            </div>
          @endforeach
        </div>
      </div>
    @endif
    @break
@endswitch
