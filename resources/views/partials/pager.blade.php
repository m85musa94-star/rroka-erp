@if ($paginator->hasPages())
    <ul class="pagination">
        @if ($paginator->onFirstPage())
            <li class="disabled"><span>{{ __('السابق') }}</span></li>
        @else
            <li><a href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('السابق') }}</a></li>
        @endif
        <li class="disabled"><span>{{ __('صفحة') }} {{ $paginator->currentPage() }} {{ __('من') }} {{ $paginator->lastPage() }}</span></li>
        @if ($paginator->hasMorePages())
            <li><a href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('التالي') }}</a></li>
        @else
            <li class="disabled"><span>{{ __('التالي') }}</span></li>
        @endif
    </ul>
@endif
