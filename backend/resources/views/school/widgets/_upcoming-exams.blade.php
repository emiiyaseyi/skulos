@if(! empty($items))
  <div class="card rp-card">
    <h3 style="margin-bottom:.3rem">Upcoming exams</h3>
    <ul class="rp-list">
      @foreach($items as $exam)
        <li><span><strong>{{ $exam['title'] }}</strong> <span class="muted">{{ $exam['subject'] ?? '' }}{{ ! empty($exam['subject']) && ! empty($exam['class']) ? ' &middot; ' : '' }}{{ $exam['class'] ?? '' }}</span></span><span class="muted">{{ $exam['date'] }}</span></li>
      @endforeach
    </ul>
  </div>
@endif
