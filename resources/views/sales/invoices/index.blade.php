@extends('layouts.app')
@section('title', __('فواتير العملاء'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('فواتير العملاء'), null]], 'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('sales.manage') ? route('sales.invoices.create', array_filter(request()->only('client_id', 'project_id'))) : null,
        'paginator' => $invoices, 'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث برقم الفاتورة أو رقم دفترة أو العميل…'),
    ])
@endsection
@section('content')
@php($rows = $invoices ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا توجد فواتير عملاء بعد') }}</strong>{{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('سجّل هنا كل فاتورة صدرت للعميل من دفترة، برقمها في دفترة كما هو.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الرقم') }}</th><th>{{ __('رقم دفترة') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('العميل') }}</th><th>{{ __('المشروع') }}</th><th class="num">{{ __('قبل الضريبة') }}</th><th class="num">{{ __('الإجمالي') }}</th><th>{{ __('الحالة') }}</th></tr>
    @foreach($groups ?? ['' => $invoices] as $title => $items)
        @php($signed = fn ($c, $f) => $c->where('status', 'APPROVED')->sum(fn ($i) => ($i->kind === 'CREDIT_NOTE' ? -1 : 1) * $i->{$f}))
        @if($groups)<tr class="grp"><td colspan="5">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td><td class="num">{{ number_format($signed($items, 'amount'), 2) }}</td><td class="num">{{ number_format($signed($items, 'total'), 2) }}</td><td></td></tr>@endif
        @foreach($items as $x)
            <tr class="row-link" onclick="location='{{ route('sales.invoices.show', $x) }}'">
                <td><a href="{{ route('sales.invoices.show', $x) }}">{{ $x->invoice_no }}</a>@if($x->kind === 'CREDIT_NOTE') <span class="badge b-ON_HOLD">{{ __('إشعار دائن') }}</span>@endif</td>
                <td dir="ltr">{{ $x->daftra_invoice_no }}</td><td class="num">{{ $x->invoice_date->format('Y-m-d') }}</td>
                <td>{{ $x->client->business_name }}</td><td>{{ $x->project?->project_no ?? '—' }}</td>
                <td class="num">{{ $x->kind === 'CREDIT_NOTE' ? '('.number_format($x->amount, 2).')' : number_format($x->amount, 2) }}</td>
                <td class="num">{{ $x->kind === 'CREDIT_NOTE' ? '('.number_format($x->total, 2).')' : number_format($x->total, 2) }}</td>
                <td>@include('partials.badge', ['s' => $x->status]) @if($x->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</td>
            </tr>
        @endforeach
    @endforeach
</table></div></div>
<p class="hint">{{ __('مجاميع التجميع تشمل المعتمدة فقط، والإشعار الدائن يُطرح.') }}</p>
@endif
@endsection
