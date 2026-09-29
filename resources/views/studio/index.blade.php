@extends('layouts.app')
@section('title', __('الاستوديو'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('الاستوديو'), null]],
        'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('studio.manage') ? route('studio.create', array_filter(request()->only('client_id', 'project_id'))) : null,
        'newLabel' => __('رفع صور'),
        'paginator' => $assets,
        'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث بالعنوان أو الوسوم أو العميل أو المشروع…'),
    ])
@endsection
@section('content')
@unless($ready)
    <div class="alert warn">{{ __('مخزن الصور غير مربوط بعد، فلا تُحفظ الصور حتى لا تضيع. راجع دليل النشر.') }}</div>
@endunless
@php($rows = $assets ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state">
        <strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('الاستوديو فارغ') }}</strong>
        {{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('ارفع صور العملاء والأعمال المنجزة من زر «رفع صور».') }}
    </div>
@elseif($lv->view === 'kanban')
    @foreach($groups ?? ['' => $assets] as $title => $items)
        @if($groups)<h3 class="studio-group">{{ $title }} <span class="grp-count">({{ $items->count() }})</span></h3>@endif
        <div class="studio-grid">
            @foreach($items as $a)
                <a class="studio-card" href="{{ route('studio.show', $a) }}">
                    <span class="studio-thumb"><img src="{{ $a->url() }}" alt="{{ $a->title }}" loading="lazy"></span>
                    <span class="studio-cap">
                        <span class="kb-title">{{ $a->title }}</span>
                        <span class="kb-meta"><span class="badge st-{{ $a->category }}">{{ __("rroka.studio_category.$a->category") }}</span><span>{{ $a->client?->business_name }}</span></span>
                    </span>
                </a>
            @endforeach
        </div>
    @endforeach
@else
    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th></th><th>{{ __('الرقم') }}</th><th>{{ __('العنوان') }}</th><th>{{ __('التصنيف') }}</th><th>{{ __('العميل') }}</th><th>{{ __('المشروع') }}</th><th>{{ __('التاريخ') }}</th></tr>
            @foreach($groups ?? ['' => $assets] as $title => $items)
                @if($groups)<tr class="grp"><td colspan="7">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td></tr>@endif
                @foreach($items as $a)
                    <tr class="row-link" onclick="location='{{ route('studio.show', $a) }}'">
                        <td style="width:56px"><img class="studio-mini" src="{{ $a->url() }}" alt="" loading="lazy"></td>
                        <td class="num">{{ $a->asset_no }}</td>
                        <td><a href="{{ route('studio.show', $a) }}">{{ $a->title }}</a></td>
                        <td><span class="badge st-{{ $a->category }}">{{ __("rroka.studio_category.$a->category") }}</span></td>
                        <td>{{ $a->client?->business_name ?? '—' }}</td>
                        <td>{{ $a->project?->project_no ?? '—' }}</td>
                        <td class="num">{{ $a->created_at->format('Y-m-d') }}</td>
                    </tr>
                @endforeach
            @endforeach
        </table></div>
    </div>
@endif
@endsection
