@if($template === 'narrative' || $show('subject_comments'))
  @if($template === 'narrative' && ($data['narrative_style'] ?? 'comments') === 'skills')
    {{--
      Key Assessment Areas (user directive 2026-09-21, sourced from a
      real school's own Nursery/Early Years report card): a grouped
      category/skill remark table, replacing the plain per-subject
      comments table below for any section that has opted into the
      'skills' narrative style (SchoolSectionBranding.narrative_style).
      Every configured skill area always appears, even one this student
      was never given a remark for, so a gap is noticed rather than
      silently missing, same convention the Behavioural Traits block
      already uses.
    --}}
    <div class="rc-block">
      <h3>Key Assessment Areas</h3>
      <div style="overflow-x:auto">
      <table class="rc-table">
        <thead><tr><th style="width:20%">Area</th><th style="width:28%">Skill</th><th>Class Teacher's Remark</th></tr></thead>
        <tbody>
          @forelse(($data['skill_areas'] ?? []) as $category => $skills)
            @foreach($skills as $i => $skill)
              <tr>
                @if($i === 0)<td rowspan="{{ count($skills) }}" style="font-weight:700;vertical-align:top">{{ $category }}</td>@endif
                <td>{{ $skill['name'] }}</td>
                <td>{{ $skill['remark'] ?: 'Not yet entered.' }}</td>
              </tr>
            @endforeach
          @empty
            <tr><td colspan="3" style="color:#888">No assessment areas configured yet.</td></tr>
          @endforelse
        </tbody>
      </table>
      </div>
    </div>
  @else
    <div class="rc-block">
      <h3>Subject Comments</h3>
      <div style="overflow-x:auto">
      <table class="rc-table">
        <thead><tr><th style="width:30%">Subject</th><th>Comment</th></tr></thead>
        <tbody>
          @forelse($data['subject_comments'] as $subjectName => $comment)
            <tr><td>{{ $subjectName }}</td><td>{{ $comment ?: 'Not yet entered.' }}</td></tr>
          @empty
            <tr><td colspan="2" style="color:#888">No subject comments entered yet.</td></tr>
          @endforelse
        </tbody>
      </table>
      </div>
    </div>
  @endif
@endif
