@extends('layouts.app')
@section('title', __('فواتير المشتريات'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('فواتير المشتريات'), null]], 'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('purchases.manage') ? route('purchases.create', array_filter(request()->only('supplier_id'))) : null,
        'paginator' => $invoices, 'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث برقم الفاتورة أو المورد…'),
    ])
@endsection
@section('content')
@php($rows = $invoices ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا توجد فواتير مشتريات بعد') }}</strong>{{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('كل خامة تدخل المخزن تُسجَّل هنا من فاتورة المورد مرة واحدة.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الرقم') }}</th><th>{{ __('المورد') }}</th><th>{{ __('فاتورة المورد') }}</th><th>{{ __('التاريخ') }}</th><th class="num">{{ __('الصافي قبل الضريبة') }}</th><th class="num">{{ __('الإجمالي') }}</th><th>{{ __('الحالة') }}</th></tr>
    @foreach($groups ?? ['' => $invoices] as $title => $items)
        @if($groups)<tr class="grp"><td colspan="4">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td><td class="num">{{ number_format($items->where('status', 'APPROVED')->sum('net_before_vat'), 2) }}</td><td colspan="2"></td></tr>@endif
        @foreach($items as $p)
            <tr class="row-link" onclick="location='{{ route('purchases.show', $p) }}'">
                <td><a href="{{ route('purchases.show', $p) }}">{{ $p->purchase_no }}</a></td><td>{{ $p->supplier->name }}</td><td>{{ $p->supplier_invoice_no }}</td>
                <td class="num">{{ $p->invoice_date->format('Y-m-d') }}</td><td class="num">{{ number_format($p->net_before_vat, 2) }}</td><td class="num">{{ number_format($p->total, 2) }}</td>
                <td>@include('partials.badge', ['s' => $p->status]) @if($p->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</td>
            </tr>
        @endforeach
    @endforeach
</table></div></div>
<p class="hint">{{ __('مجاميع التجميع تشمل المعتمدة فقط.') }}</p>
@endif
@endsection
