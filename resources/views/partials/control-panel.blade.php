{{--
  Odoo-style control panel.
  Params: $crumbs [[label, url|null]...], $lv (ListView|null), $newUrl, $newLabel,
          $paginator (LengthAwarePaginator|null), $total (int|null), $placeholder
--}}
<div class="cp">
    <div class="cp-row">
        <nav class="crumbs" aria-label="مسار التنقل">
            @foreach($crumbs as $i => [$label, $url])
                @if($i > 0)<span class="crumb-sep">/</span>@endif
                @if($url && ! $loop->last)
                    <a href="{{ $url }}">{{ $label }}</a>
                @else
                    <span class="crumb-current">{{ $label }}</span>
                @endif
            @endforeach
        </nav>
        @isset($paginator)
            @if($paginator && $paginator->total() > 0)
                <div class="cp-pager">
                    <span>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} / {{ $paginator->total() }}</span>
                    <a class="pg-btn @if($paginator->onFirstPage()) off @endif" href="{{ $paginator->previousPageUrl() ?? '#' }}" aria-label="السابق">›</a>
                    <a class="pg-btn @if(! $paginator->hasMorePages()) off @endif" href="{{ $paginator->nextPageUrl() ?? '#' }}" aria-label="التالي">‹</a>
                </div>
            @endif
        @endisset
        @isset($total)
            @if($total !== null)<div class="cp-pager"><span>{{ $total }} سجل</span></div>@endif
        @endisset
    </div>

    @isset($lv)
    <div class="cp-row">
        <div class="cp-left">
            @if(! empty($newUrl))
                <a class="btn" href="{{ $newUrl }}">{{ $newLabel ?? 'جديد' }}</a>
            @endif
        </div>

        <form method="get" class="cp-search" role="search">
            @foreach($lv->active as $f)<input type="hidden" name="f[]" value="{{ $f }}">@endforeach
            @if($lv->group)<input type="hidden" name="g" value="{{ $lv->group }}">@endif
            @if($lv->view !== ($lv->defaultView ?? $lv->views[0]))<input type="hidden" name="v" value="{{ $lv->view }}">@endif
            <div class="facets">
                @foreach($lv->active as $f)
                    <a class="facet" href="{{ $lv->toggleFilterUrl($f) }}" title="إزالة">{{ $lv->filters[$f]['label'] }} <b>×</b></a>
                @endforeach
                @if($lv->group)
                    <a class="facet facet-group" href="{{ $lv->url(['g' => null]) }}" title="إزالة">تجميع: {{ $lv->groups[$lv->group]['label'] }} <b>×</b></a>
                @endif
                @if($lv->q !== '')
                    <a class="facet facet-q" href="{{ $lv->url(['q' => null, 'page' => null]) }}" title="إزالة">«{{ $lv->q }}» <b>×</b></a>
                @endif
                <input name="q" value="" placeholder="{{ $placeholder ?? 'بحث…' }}" aria-label="بحث">
            </div>
            @if($lv->filters || $lv->groups)
            <details class="cp-drop">
                <summary aria-label="الفلاتر والتجميع">▾</summary>
                <div class="cp-menu">
                    @if($lv->filters)
                    <div class="cp-col">
                        <div class="cp-col-title">الفلاتر</div>
                        @php($prevGroup = null)
                        @foreach($lv->filters as $key => $f)
                            @if($prevGroup !== null && $prevGroup !== $f['group'])<hr>@endif
                            @php($prevGroup = $f['group'])
                            <a href="{{ $lv->toggleFilterUrl($key) }}" @class(['on' => in_array($key, $lv->active, true)])>{{ $f['label'] }}</a>
                        @endforeach
                    </div>
                    @endif
                    @if($lv->groups)
                    <div class="cp-col">
                        <div class="cp-col-title">تجميع حسب</div>
                        @foreach($lv->groups as $key => $g)
                            <a href="{{ $lv->url(['g' => $lv->group === $key ? null : $key, 'page' => null]) }}" @class(['on' => $lv->group === $key])>{{ $g['label'] }}</a>
                        @endforeach
                    </div>
                    @endif
                </div>
            </details>
            @endif
        </form>

        <div class="cp-views">
            @if(count($lv->views) > 1)
                @foreach($lv->views as $v)
                    <a href="{{ $lv->url(['v' => $v, 'page' => null]) }}" @class(['vw', 'on' => $lv->view === $v]) title="{{ $v === 'list' ? 'قائمة' : 'بطاقات' }}" aria-label="{{ $v === 'list' ? 'عرض القائمة' : 'عرض البطاقات' }}">
                        @if($v === 'list')
                            <svg width="18" height="18" viewBox="0 0 18 18"><rect x="2" y="3" width="14" height="2" rx="1" fill="currentColor"/><rect x="2" y="8" width="14" height="2" rx="1" fill="currentColor"/><rect x="2" y="13" width="14" height="2" rx="1" fill="currentColor"/></svg>
                        @else
                            <svg width="18" height="18" viewBox="0 0 18 18"><rect x="2" y="2" width="6" height="6" rx="1.5" fill="currentColor"/><rect x="10" y="2" width="6" height="6" rx="1.5" fill="currentColor"/><rect x="2" y="10" width="6" height="6" rx="1.5" fill="currentColor"/><rect x="10" y="10" width="6" height="6" rx="1.5" fill="currentColor"/></svg>
                        @endif
                    </a>
                @endforeach
            @endif
        </div>
    </div>
    @endisset
</div>
