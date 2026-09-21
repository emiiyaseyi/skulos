@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Calendar'])
@section('title', 'Calendar')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Calendar</h1>
    <p class="subtitle">
      @if($selectedTerm)
        {{ $selectedTerm->session?->name }} &middot; {{ $selectedTerm->name }}
      @else
        Notable days and events for {{ $school->name }}.
      @endif
    </p>
  </div>
  @if($canCreate)
    <a class="btn btn-primary btn-sm" href="{{ route('school.calendar.create', ['school' => $school->slug]) }}">Create event</a>
  @endif
</div>

@if($termsInSession->isNotEmpty())
  <div class="card" style="margin-bottom:1.1rem">
    <form method="GET" action="{{ route('school.calendar.index', ['school' => $school->slug]) }}">
      <div class="field" style="margin:0;max-width:18rem">
        <label for="term_id">{{ \App\Support\TermLabel::current() }}</label>
        <select id="term_id" name="term_id" onchange="this.form.requestSubmit()">
          @foreach($termsInSession as $term)
            <option value="{{ $term->id }}" @selected($selectedTerm->id === $term->id)>{{ $term->name }}</option>
          @endforeach
        </select>
      </div>
    </form>
  </div>
@endif

<div class="card">
  @if($events->isEmpty())
    <p class="muted">No events yet.</p>
  @else
    @php $anyRemovable = $events->contains(fn ($e) => ! $e->is_default); @endphp
    @if($canCreate && $anyRemovable)
      <form id="bulkEventsForm" method="POST" action="{{ route('school.calendar.bulk-destroy', ['school' => $school->slug]) }}">@csrf</form>
    @endif
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr>
        @if($canCreate && $anyRemovable)<th style="width:2.2rem"><input type="checkbox" id="event-select-all" aria-label="Select all events" /></th>@endif
        <th>Date</th><th>Title</th><th>Category</th>@if($canCreate)<th></th>@endif
      </tr></thead>
      <tbody>
        @foreach($events as $event)
          <tr>
            @if($canCreate && $anyRemovable)
              <td>
                @unless($event->is_default)
                  <input type="checkbox" form="bulkEventsForm" class="event-row-check" name="event_ids[]" value="{{ $event->id }}" aria-label="Select {{ $event->title }}" />
                @endunless
              </td>
            @endif
            <td class="muted" style="white-space:nowrap" data-label="Date">
              @if($canCreate)
                <a href="{{ route('school.calendar.edit', ['school' => $school->slug, 'eventId' => $event->id]) }}">
                  {{ $event->starts_on?->format('d M Y') ?? 'Set a date' }}
                  @if($event->ends_on && ! $event->ends_on->equalTo($event->starts_on)) &ndash; {{ $event->ends_on->format('d M Y') }} @endif
                </a>
              @else
                {{ $event->starts_on?->format('d M Y') ?? 'Not set' }}
                @if($event->ends_on && ! $event->ends_on->equalTo($event->starts_on)) &ndash; {{ $event->ends_on->format('d M Y') }} @endif
              @endif
            </td>
            <td data-label="Title">
              <strong>{{ $event->title }}</strong>
              @if($event->category === 'exam' && ($event->subject || $event->classArm))
                <div class="muted">{{ $event->subject?->name }}{{ $event->subject && $event->classArm ? ' &middot; ' : '' }}{{ $event->classArm?->label() }}</div>
              @endif
              @if($event->description)<div class="muted">{{ \Illuminate\Support\Str::limit($event->description, 90) }}</div>@endif
            </td>
            <td class="muted" data-label="Category">{{ ucfirst($event->category) }}{{ $event->is_default ? ' (default)' : '' }}</td>
            @if($canCreate)
              <td>
                @unless($event->is_default)
                  <form method="POST" action="{{ route('school.calendar.destroy', ['school' => $school->slug, 'eventId' => $event->id]) }}" onsubmit="return confirm('Remove this event?')">
                    @csrf @method('DELETE')
                    <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
                  </form>
                @endunless
              </td>
            @endif
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    @if($canCreate && $anyRemovable)
      <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
        <span class="muted" style="font-size:.85rem">Tick any number of events above, then remove them all at once.</span>
        <button form="bulkEventsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" onclick="return confirm('Remove the selected events?')">Remove selected</button>
      </div>
      <script>
        (function(){
          var all = document.getElementById('event-select-all');
          var boxes = document.querySelectorAll('.event-row-check');
          all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
          document.getElementById('bulkEventsForm')?.addEventListener('submit', function(e){
            var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
            if (! anyChecked) { e.preventDefault(); alert('Tick at least one event first.'); }
          });
        })();
      </script>
    @endif
    <div style="margin-top:1rem">{{ $events->links() }}</div>
  @endif
</div>
@endsection
