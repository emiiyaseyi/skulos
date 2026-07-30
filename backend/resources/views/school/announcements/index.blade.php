@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Announcements'])
@section('title', 'Announcements')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Announcements</h1>
    <p class="subtitle">Every announcement sent to this school, most recent first.</p>
  </div>
  @if($canCompose)
    <a class="btn btn-primary btn-sm" href="{{ route('school.announcements.create', ['school' => $school->slug]) }}">Send announcement</a>
  @endif
</div>

<div class="card">
  @if($announcements->isEmpty())
    <p class="muted">No announcements yet.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Title</th><th>Audience</th><th>Sent</th></tr></thead>
      <tbody>
        @foreach($announcements as $a)
          <tr>
            <td>
              <a href="{{ route('school.announcements.show', ['school' => $school->slug, 'announcementId' => $a->id]) }}">
                <strong>{{ $a->title }}</strong>
                <div class="muted">{{ \Illuminate\Support\Str::limit($a->body, 90) }}</div>
              </a>
            </td>
            <td class="muted">{{ ucfirst($a->audience) }}</td>
            <td class="muted" style="white-space:nowrap">{{ $a->published_at?->diffForHumans() ?? 'Not sent' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $announcements->links() }}</div>
  @endif
</div>
@endsection
