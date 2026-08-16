{{--
  What an upload WOULD do, before anything is written (2026-08-16).
  Shows the rows that are ready, the rows that are not and exactly why,
  and any tidying that was applied, so an admin decides with the facts
  in front of them rather than finding out afterwards.

  Expects $preview (from Importer::preview()), $columns, and
  $confirmRoute.
--}}
@php($good = $preview['good'])
@php($bad = $preview['bad'])

<div class="grid cols-3" style="margin-bottom:1.2rem">
  <div class="stat"><b>{{ $preview['total'] }}</b><span>Rows in file</span></div>
  <div class="stat"><b>{{ count($good) }}</b><span>Ready to import</span></div>
  <div class="stat"><b>{{ count($bad) }}</b><span>Need attention</span></div>
</div>

@if(count($bad))
  <div class="card" style="margin-bottom:1.2rem">
    <h3 style="margin-bottom:.3rem">Rows that need attention</h3>
    <p class="muted" style="margin-bottom:.8rem">
      These are not imported. Fix them in your file and upload again, or import the good rows now
      and add these later.
    </p>
    <div style="overflow-x:auto">
      <table class="table-stack">
        <thead><tr><th>Row</th><th>What it says</th><th>Why it cannot be imported</th></tr></thead>
        <tbody>
          @foreach($bad as $entry)
            <tr>
              <td data-label="Row">{{ $entry['row'] }}</td>
              <td data-label="What it says" class="muted">
                {{ collect($entry['values'])->filter()->take(3)->implode(' · ') ?: '(empty row)' }}
              </td>
              <td data-label="Why">
                @foreach($entry['already_on_record'] as $message)
                  <div><span class="badge badge-warn">Duplicate</span> {{ $message }}</div>
                @endforeach
                @foreach($entry['duplicate_in_file'] as $message)
                  <div><span class="badge badge-warn">Duplicate</span> {{ $message }}</div>
                @endforeach
                @foreach($entry['errors'] as $field => $messages)
                  @foreach($messages as $message)
                    <div class="muted">{{ $message }}</div>
                  @endforeach
                @endforeach
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endif

@if(count($good))
  <div class="card" style="margin-bottom:1.2rem">
    <h3 style="margin-bottom:.3rem">Ready to import</h3>
    @php($tidied = collect($good)->filter(fn ($e) => $e['changed'] !== []))
    @if($tidied->isNotEmpty())
      <p class="muted" style="margin-bottom:.8rem">
        {{ $tidied->count() }} row(s) were tidied: extra spaces and invisible characters removed.
        Spelling and capitalisation are never changed.
      </p>
    @endif
    <div style="overflow-x:auto;max-height:24rem">
      <table class="table-stack">
        <thead>
          <tr>
            <th>Row</th>
            @foreach(array_slice($columns, 0, 5, true) as $key => $label)<th>{{ $label }}</th>@endforeach
          </tr>
        </thead>
        <tbody>
          @foreach(array_slice($good, 0, 100) as $entry)
            <tr>
              <td data-label="Row" class="muted">{{ $entry['row'] }}</td>
              @foreach(array_slice($columns, 0, 5, true) as $key => $label)
                <td data-label="{{ $label }}">
                  {{ $entry['values'][$key] ?? '' }}
                  @if(in_array($key, $entry['changed'], true))<span class="badge badge-ok" title="Extra spacing removed">tidied</span>@endif
                </td>
              @endforeach
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @if(count($good) > 100)
      <p class="hint" style="margin-top:.6rem">Showing the first 100 of {{ count($good) }}.</p>
    @endif
  </div>
@endif

<div class="card">
  @if(count($good))
    <form method="POST" action="{{ $confirmRoute }}">
      @csrf
      <button class="btn btn-primary btn-sm" type="submit">
        Import the {{ count($good) }} good row(s){{ count($bad) ? ', skip the rest' : '' }}
      </button>
      <a class="btn btn-ghost btn-sm" href="{{ url()->current() }}">Cancel and fix my file</a>
    </form>
  @else
    <p class="muted" style="margin:0">
      Nothing in this file can be imported yet. Fix the rows above and upload again.
    </p>
  @endif
</div>
