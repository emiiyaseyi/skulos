@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Calendar'])
@section('title', 'Calendar')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Calendar</h1>
    <p class="subtitle">Notable days and events for {{ $school->name }}.</p>
  </div>
  @if($canCreate)
    <a class="btn btn-primary btn-sm" href="{{ route('school.calendar.create', ['school' => $school->slug]) }}">Create event</a>
  @endif
</div>

<div class="card">
  @if($events->isEmpty())
    <p class="muted">No events yet.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Date</th><th>Title</th><th>Category</th>@if($canCreate)<th></th>@endif</tr></thead>
      <tbody>
        @foreach($events as $event)
          <tr>
            <td class="muted" style="white-space:nowrap">
              {{ $event->starts_on?->format('d M Y') }}
              @if($event->ends_on && ! $event->ends_on->equalTo($event->starts_on)) &ndash; {{ $event->ends_on->format('d M Y') }} @endif
            </td>
            <td>
              <strong>{{ $event->title }}</strong>
              @if($event->category === 'exam' && ($event->subject || $event->classArm))
                <div class="muted" style="font-size:.8rem">{{ $event->subject?->name }}{{ $event->subject && $event->classArm ? ' &middot; ' : '' }}{{ $event->classArm?->label() }}</div>
              @endif
              @if($event->description)<div class="muted" style="font-size:.8rem">{{ \Illuminate\Support\Str::limit($event->description, 90) }}</div>@endif
            </td>
            <td class="muted">{{ ucfirst($event->category) }}{{ $event->is_default ? ' (default)' : '' }}</td>
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
    <div style="margin-top:1rem">{{ $events->links() }}</div>
  @endif
</div>
@endsection
