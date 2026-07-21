@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Edit article'])
@section('title', 'Edit article')
@section('heading', $article->title)
@section('crumb', 'Knowledge Base article')

@section('content')
<div class="card" style="max-width:40rem">
  <form method="POST" action="{{ route('admin.knowledge-base.articles.update', ['articleId' => $article->id]) }}">
    @csrf
    <div class="field">
      <label for="knowledge_base_category_id">Category</label>
      <select id="knowledge_base_category_id" name="knowledge_base_category_id" required>
        @foreach($categories as $category)
          <option value="{{ $category->id }}" @selected($article->knowledge_base_category_id === $category->id)>{{ $category->name }}</option>
        @endforeach
      </select>
    </div>
    <div class="field">
      <label for="title">Title</label>
      <input id="title" name="title" value="{{ $article->title }}" required />
    </div>
    <div class="field">
      <label for="body">Content</label>
      <textarea id="body" name="body" rows="12" required>{{ $article->body }}</textarea>
    </div>
    <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;margin-bottom:1rem">
      <input type="hidden" name="is_published" value="0" />
      <input type="checkbox" name="is_published" value="1" @checked($article->is_published) />
      <span>Published (visible to schools)</span>
    </label>
    <div style="display:flex;gap:.5rem">
      <button class="btn btn-primary btn-sm" type="submit">Save</button>
      <a class="btn btn-ghost btn-sm" href="{{ route('admin.knowledge-base.index') }}">Back</a>
    </div>
  </form>
</div>
@endsection
