@if(! empty($items))
  <div class="card rp-card">
    <h3 style="margin-bottom:.3rem">Upcoming assignments</h3>
    <ul class="rp-list">
      @foreach($items as $assignment)
        <li><span><strong>{{ $assignment['title'] }}</strong> <span class="muted">{{ $assignment['subject'] ?? '' }}{{ ! empty($assignment['subject']) && ! empty($assignment['class']) ? ' &middot; ' : '' }}{{ $assignment['class'] ?? '' }}</span></span><span class="muted">Due {{ $assignment['date'] }}</span></li>
      @endforeach
    </ul>
    <a class="btn btn-ghost btn-sm" style="margin-top:.8rem" href="{{ route('school.assignments.index', ['school' => $school->slug]) }}">All assignments</a>
  </div>
@endif
