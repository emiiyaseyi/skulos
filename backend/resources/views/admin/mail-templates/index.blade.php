@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Mail Templates'])
@section('title', 'Mail Templates')
@section('heading', 'Mail Templates')
@section('crumb', 'Edit the wording of every email SKULOS sends')
@section('content')

<div class="card" style="margin-bottom:1.2rem">
  <p class="muted" style="margin:0">
    Each message below is what a school actually receives. Edit any of them and your wording is
    used from then on. Anything you have not edited keeps its built-in wording, and you can revert
    an edited one at any time.
  </p>
</div>

<div class="card">
  <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Email</th><th>When it is sent</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($catalog as $key => $meta)
          @php($row = $edited->get($key))
          <tr>
            <td data-label="Email"><b>{{ $meta['label'] }}</b></td>
            <td data-label="When it is sent" class="muted">{{ $meta['description'] }}</td>
            <td data-label="Status">
              @if($row)
                <span class="badge badge-ok">Edited</span>
                <div class="muted" style="font-size:.8rem">
                  {{ $row->updated_at?->diffForHumans() }}@if($row->updatedBy) by {{ $row->updatedBy->name }}@endif
                </div>
              @else
                <span class="badge">Built in</span>
              @endif
            </td>
            <td>
              <a class="btn btn-ghost btn-sm" href="{{ route('admin.mail-templates.edit', ['key' => $key]) }}">Edit</a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>
@endsection
