@if ($paginator->hasPages())
    <ul class="pagination">
        @if ($paginator->onFirstPage())
            <li class="disabled"><span>السابق</span></li>
        @else
            <li><a href="{{ $paginator->previousPageUrl() }}" rel="prev">السابق</a></li>
        @endif
        <li class="disabled"><span>صفحة {{ $paginator->currentPage() }} من {{ $paginator->lastPage() }}</span></li>
        @if ($paginator->hasMorePages())
            <li><a href="{{ $paginator->nextPageUrl() }}" rel="next">التالي</a></li>
        @else
            <li class="disabled"><span>التالي</span></li>
        @endif
    </ul>
@endif
