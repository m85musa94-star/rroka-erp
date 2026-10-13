@extends('layouts.app')
@section('title', __('سندات القبض'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('سندات القبض'), null]], 'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('sales.manage') ? route('sales.receipts.create', array_filter(request()->only('client_id', 'project_id'))) : null,
        'paginator' => $receipts, 'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث برقم السند أو المرجع أو العميل…'),
    ])
@endsection
@section('content')
@php($rows = $receipts ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا توجد سندات قبض بعد') }}</strong>{{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('سجّل كل مبلغ استُلم من عميل، والحساب الذي دخل فيه.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الرقم') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('العميل') }}</th><th>{{ __('الفاتورة') }}</th><th>{{ __('المشروع') }}</th><th>{{ __('استُلم في') }}</th><th class="num">{{ __('المبلغ') }}</th><th>{{ __('الحالة') }}</th></tr>
    @foreach($groups ?? ['' => $receipts] as $title => $items)
        @if($groups)<tr class="grp"><td colspan="6">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td><td class="num">{{ number_format($items->where('status', 'APPROVED')->sum('amount'), 2) }}</td><td></td></tr>@endif
        @foreach($items as $x)
            <tr class="row-link" onclick="location='{{ route('sales.receipts.show', $x) }}'">
                <td><a href="{{ route('sales.receipts.show', $x) }}">{{ $x->receipt_no }}</a></td><td class="num">{{ $x->receipt_date->format('Y-m-d') }}</td>
                <td>{{ $x->client->business_name }}</td><td dir="ltr">{{ $x->invoice?->daftra_invoice_no ?? '—' }}</td><td>{{ $x->project?->project_no ?? '—' }}</td>
                <td>{{ $x->paymentAccount->name }}</td><td class="num">{{ number_format($x->amount, 2) }}</td>
                <td>@include('partials.badge', ['s' => $x->status]) @if($x->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</td>
            </tr>
        @endforeach
    @endforeach
</table></div></div>
<p class="hint">{{ __('مجاميع التجميع تشمل المعتمدة فقط.') }}</p>
@endif
@endsection
