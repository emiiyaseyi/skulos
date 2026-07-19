@if($template === 'narrative' || $show('subject_comments'))
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
