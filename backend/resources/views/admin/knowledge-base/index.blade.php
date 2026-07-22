@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Knowledge Base'])
@section('title', 'Knowledge Base')
@section('heading', 'Knowledge Base')
@section('crumb', 'The in-app help articles every school\'s members can read')

@section('content')
<div class="card" style="margin-bottom:1.25rem;max-width:28rem">
  <h3 style="margin-bottom:.6rem">Add a category</h3>
  <form method="POST" action="{{ route('admin.knowledge-base.categories.store') }}" style="display:flex;gap:.5rem">
    @csrf
    <input name="name" placeholder="e.g. Billing" required style="flex:1" />
    <button class="btn btn-primary btn-sm" type="submit">Add</button>
  </form>
</div>

@if($categories->isEmpty())
  <div class="card"><p class="muted">No categories yet. Add one above to get started.</p></div>
@else
  @foreach($categories as $category)
    <div class="card" style="margin-bottom:1.1rem">
      <div class="row-between" style="margin-bottom:.7rem;flex-wrap:wrap;gap:.6rem">
        <form method="POST" action="{{ route('admin.knowledge-base.categories.update', ['categoryId' => $category->id]) }}" style="display:flex;gap:.4rem;align-items:center">
          @csrf
          <input name="name" value="{{ $category->name }}" style="max-width:16rem" />
          <button class="btn btn-ghost btn-sm" type="submit">Rename</button>
        </form>
        <form method="POST" action="{{ route('admin.knowledge-base.categories.destroy', ['categoryId' => $category->id]) }}" onsubmit="return confirm('Remove {{ $category->name }} and every article in it?')">
          @csrf @method('DELETE')
          <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove category</button>
        </form>
      </div>

      @if($category->articles->isEmpty())
        <p class="muted">No articles in this category yet.</p>
      @else
        <div style="overflow-x:auto">
        <table>
          <thead><tr><th>Title</th><th>Status</th><th></th></tr></thead>
          <tbody>
            @foreach($category->articles as $article)
              <tr>
                <td>{{ $article->title }}</td>
                <td><span class="badge {{ $article->is_published ? 'badge-ok' : 'badge-warn' }}">{{ $article->is_published ? 'Published' : 'Draft' }}</span></td>
                <td style="white-space:nowrap">
                  <a class="btn btn-ghost btn-sm" href="{{ route('admin.knowledge-base.articles.edit', ['articleId' => $article->id]) }}">Edit</a>
                  <form method="POST" action="{{ route('admin.knowledge-base.articles.destroy', ['articleId' => $article->id]) }}" style="display:inline" onsubmit="return confirm('Remove &quot;{{ $article->title }}&quot;?')">
                    @csrf @method('DELETE')
                    <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove</button>
                  </form>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
        </div>
      @endif
    </div>
  @endforeach
@endif

<div class="card">
  <h3 style="margin-bottom:.8rem">Add an article</h3>
  <form method="POST" action="{{ route('admin.knowledge-base.articles.store') }}">
    @csrf
    <div class="grid cols-2">
      <div class="field">
        <label for="knowledge_base_category_id">Category</label>
        <select id="knowledge_base_category_id" name="knowledge_base_category_id" required>
          @foreach($categories as $category)
            <option value="{{ $category->id }}">{{ $category->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="title">Title</label>
        <input id="title" name="title" required />
      </div>
    </div>
    <div class="field">
      <label for="body">Short intro (optional)</label>
      <textarea id="body" name="body" rows="2" placeholder="One or two sentences shown above the steps."></textarea>
    </div>
    <div class="field">
      <label for="steps">Steps (one per line)</label>
      <textarea id="steps" name="steps" rows="8" placeholder="Go to Students &gt; Add Student.&#10;Fill in the required fields.&#10;Save."></textarea>
    </div>
    <div class="field">
      <label for="start_route">Start here button (optional)</label>
      <select id="start_route" name="start_route">
        <option value="">No button</option>
        @foreach(config('skulos.knowledge_base_start_routes') as $routeName => $meta)
          <option value="{{ $routeName }}">{{ $meta['label'] }}</option>
        @endforeach
      </select>
    </div>
    <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;margin-bottom:1rem">
      <input type="hidden" name="is_published" value="0" />
      <input type="checkbox" name="is_published" value="1" checked />
      <span>Published (visible to schools)</span>
    </label>
    <button class="btn btn-primary btn-sm" type="submit">Add article</button>
  </form>
</div>
@endsection
