@if ($paginator->hasPages())
  <nav class="pager" role="navigation" aria-label="Pagination">
    @if ($paginator->onFirstPage())
      <span class="btn btn-ghost btn-sm" aria-disabled="true">Previous</span>
    @else
      <a class="btn btn-ghost btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
    @endif

    <span class="muted pager-status">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>

    @if ($paginator->hasMorePages())
      <a class="btn btn-ghost btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
    @else
      <span class="btn btn-ghost btn-sm" aria-disabled="true">Next</span>
    @endif
  </nav>
@endif
