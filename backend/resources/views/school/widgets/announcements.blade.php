@php($isRow = isset($isRow) ? $isRow : true)
<div class="ann-card" style="{{ $isRow ? '' : 'margin-bottom:1.1rem' }}">
  <h3>Announcements</h3>
  @if($announcements->isEmpty())
    <p class="muted" style="font-size:.82rem">No announcements yet.</p>
  @else
    <ul class="ann-list">
      @foreach($announcements as $a)
        <li>
          <strong>{{ $a->title }}</strong>
          <div class="when">{{ $a->published_at?->diffForHumans() }}</div>
        </li>
      @endforeach
    </ul>
  @endif
</div>
