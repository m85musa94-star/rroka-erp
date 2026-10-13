@extends('layouts.app')
@section('title', __('سداد الموردين'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('سداد الموردين'), null]], 'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('purchases.manage') ? route('supplier-payments.create', array_filter(request()->only('supplier_id'))) : null,
        'paginator' => $payments, 'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث برقم السند أو المرجع أو المورد…'),
    ])
@endsection
@section('content')
@php($rows = $payments ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا توجد سندات صرف للموردين بعد') }}</strong>{{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('سجّل كل مبلغ دُفع لمورد عن فاتورة آجلة، والحساب الذي دُفع منه.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الرقم') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('المورد') }}</th><th>{{ __('الفاتورة') }}</th><th>{{ __('دُفع من') }}</th><th class="num">{{ __('المبلغ') }}</th><th>{{ __('الحالة') }}</th></tr>
    @foreach($groups ?? ['' => $payments] as $title => $items)
        @if($groups)<tr class="grp"><td colspan="5">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td><td class="num">{{ number_format($items->where('status', 'APPROVED')->sum('amount'), 2) }}</td><td></td></tr>@endif
        @foreach($items as $x)
            <tr class="row-link" onclick="location='{{ route('supplier-payments.show', $x) }}'">
                <td><a href="{{ route('supplier-payments.show', $x) }}">{{ $x->payment_no }}</a></td><td class="num">{{ $x->payment_date->format('Y-m-d') }}</td>
                <td>{{ $x->supplier->name }}</td><td><bdi>{{ $x->invoiceLabel() ?? __('دفعة مقدمة') }}</bdi></td>
                <td>{{ $x->paymentAccount->name }}</td><td class="num">{{ number_format($x->amount, 2) }}</td>
                <td>@include('partials.badge', ['s' => $x->status]) @if($x->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</td>
            </tr>
        @endforeach
    @endforeach
</table></div></div>
<p class="hint">{{ __('مجاميع التجميع تشمل المعتمدة فقط.') }}</p>
@endif
@endsection
