@extends('layouts.app')
@section('title', __('عروض الأسعار'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('عروض الأسعار'), null]],
        'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('quotations.manage') ? route('quotations.create') : null,
        'paginator' => $quotations,
        'total' => $groups?->flatten()->count() ?? $columns?->flatten()->count(),
        'placeholder' => __('بحث برقم العرض أو اسم العميل…'),
    ])
@endsection
@section('content')
@if($columns)
    <div class="kanban">
        @foreach($columns as $st => $items)
            <div class="kb-col">
                <div class="kb-head">
                    <span>@include('partials.badge', ['s' => $st]) <span class="muted" style="font-weight:400">{{ $items->count() }}</span></span>
                    <span class="kb-sum">{{ number_format($items->sum('net_before_vat'), 2) }}</span>
                </div>
                @forelse($items as $q)
                    <a class="kb-card" href="{{ route('quotations.show', $q) }}">
                        <div class="kb-title">{{ $q->client->business_name }}</div>
                        <div class="kb-meta"><span>{{ $q->quotation_no }}</span><span dir="ltr">{{ number_format($q->net_before_vat, 2) }}</span></div>
                        <div class="kb-meta"><span>{{ $q->issue_date->format('Y-m-d') }}</span></div>
                    </a>
                @empty
                    <div class="kb-empty">{{ __('لا عروض') }}</div>
                @endforelse
            </div>
        @endforeach
    </div>
    <p class="hint">{{ __('الانتقال بين المراحل يتم من داخل العرض نفسه، لأن لكل انتقال شروطًا رقابية.') }}</p>
@else
    @php($rows = $quotations ?? $groups->flatten())
    @if($rows->isEmpty())
        <div class="card empty-state">
            <strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا توجد عروض أسعار بعد') }}</strong>
            {{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('أنشئ أول عرض من زر «جديد».') }}
        </div>
    @else
    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>{{ __('الرقم') }}</th><th>{{ __('العميل') }}</th><th>{{ __('التاريخ') }}</th><th class="num">{{ __('الصافي قبل الضريبة') }}</th><th>{{ __('الحالة') }}</th></tr>
            @foreach($groups ?? ['' => $quotations] as $title => $items)
                @if($groups)<tr class="grp"><td colspan="3">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td><td class="num">{{ number_format($items->sum('net_before_vat'), 2) }}</td><td></td></tr>@endif
                @foreach($items as $q)
                    <tr class="row-link" onclick="location='{{ route('quotations.show', $q) }}'">
                        <td><a href="{{ route('quotations.show', $q) }}">{{ $q->quotation_no }}</a></td>
                        <td>{{ $q->client->business_name }}</td>
                        <td class="num">{{ $q->issue_date->format('Y-m-d') }}</td>
                        <td class="num">{{ number_format($q->net_before_vat, 2) }}</td>
                        <td>@include('partials.badge', ['s' => $q->status])</td>
                    </tr>
                @endforeach
            @endforeach
        </table></div>
    </div>
    @endif
@endif
@endsection
