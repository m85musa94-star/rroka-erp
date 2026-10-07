@extends('layouts.app')
@section('title', $p->purchase_no)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('فواتير المشتريات'), route('purchases.index')], [$p->purchase_no, null]]])
@endsection
@section('content')
@php
    $u = auth()->user();
    $q = fn ($x) => rtrim(rtrim(number_format((float) $x, 4, '.', ','), '0'), '.');
    $path = $p->status === 'CANCELLED' ? ['DRAFT', 'CANCELLED'] : ['DRAFT', 'APPROVED'];
@endphp
<div class="rec-bar">
    <div class="actions">
        @include('partials.delete-button', ['type' => 'purchases', 'model' => $p])
        @if($p->status === 'DRAFT')
            @if($u->hasPermission('purchases.manage'))<a class="btn ghost sm" href="{{ route('purchases.edit', $p) }}">{{ __('تعديل') }}</a>@endif
            @if($u->hasPermission('purchases.approve'))
                <form method="post" action="{{ route('purchases.approve', $p) }}" class="inline" data-confirm="{{ __('اعتماد الفاتورة؟ تدخل الخامات المخزون بتكلفتها ولا يمكن تعديل الفاتورة بعدها.') }}">@csrf<button class="btn ok sm">{{ __('اعتماد وإدخال المخزون') }}</button></form>
            @endif
            @if($u->hasPermission('purchases.manage'))
                <form method="post" action="{{ route('purchases.cancel', $p) }}" class="inline" data-confirm="{{ __('إلغاء المسودة؟') }}">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>
            @endif
        @endif
        @if($p->attachment)<a class="btn ghost sm" href="{{ $p->attachment->url(false) }}" target="_blank" rel="noopener">{{ __('صورة الفاتورة') }}</a>@endif
    </div>
    @include('partials.statusbar', ['path' => $path, 'current' => $p->status, 'bad' => ['CANCELLED']])
</div>
<div class="card">
    <h1 class="rec-title">{{ $p->purchase_no }}</h1>
    <p class="rec-sub"><a href="{{ route('suppliers.show', $p->supplier) }}">{{ $p->supplier->name }}</a> · {{ __('فاتورة المورد') }} <bdi dir="ltr">{{ $p->supplier_invoice_no }}</bdi></p>
    <dl class="kv">
        <dt>{{ __('تاريخ الفاتورة') }}</dt><dd>{{ $p->invoice_date->format('Y-m-d') }}</dd>
        <dt>{{ __('تاريخ الاستحقاق') }}</dt><dd>{{ $p->due_date?->format('Y-m-d') ?? '—' }}</dd>
        @include('partials.journal-link', ['type' => 'PURCHASE', 'id' => $p->id, 'approved' => $p->status === 'APPROVED'])
        <dt>{{ __('أدخلها') }}</dt><dd>{{ $names[$p->created_by] ?? '—' }}</dd>
        @if($p->approved_at)<dt>{{ __('اعتمدها') }}</dt><dd>{{ $names[$p->approved_by] ?? '—' }} — {{ $p->approved_at->format('Y-m-d H:i') }} @if($p->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</dd>@endif
        <dt>{{ __('دفترة') }}</dt><dd>{{ $p->daftra_purchase_id ? __('مرتبط (رقم :no)', ['no' => $p->daftra_purchase_id]) : __('لم تُرسل — الإرسال موقوف حتى التحقق من ربط دفترة') }}</dd>
        @if($p->notes)<dt>{{ __('ملاحظات') }}</dt><dd>{{ $p->notes }}</dd>@endif
    </dl>
    @unless($p->attachment_id)<p class="hint warn-text">{{ __('لا توجد صورة للمستند المؤيد.') }}</p>@endunless
</div>
<div class="card">
    <h2>{{ __('البنود') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>#</th><th>{{ __('الخامة') }}</th><th class="num">{{ __('الكمية') }}</th><th class="num">{{ __('سعر الوحدة') }}</th><th class="num">{{ __('الإجمالي') }}</th>@if($p->status === 'APPROVED')<th class="num">{{ __('تكلفة الوحدة المستلمة') }}</th>@endif</tr>
        @foreach($p->lines as $l)
            <tr><td>{{ $l->line_no }}</td><td><a href="{{ route('materials.show', $l->material) }}">{{ $l->material->code }}</a> — {{ $l->material->name }} <span class="muted">({{ $l->material->uom }})</span></td>
                <td class="num">{{ $q($l->quantity) }}</td><td class="num">{{ number_format($l->unit_price, 2) }}</td><td class="num">{{ number_format($l->line_total, 2) }}</td>
                @if($p->status === 'APPROVED')<td class="num">{{ isset($receipts[$l->id]) ? number_format($receipts[$l->id], 4) : '—' }}</td>@endif</tr>
        @endforeach
        <tr><td colspan="4">{{ __('المجموع') }}</td><td class="num">{{ number_format($totals->subtotal, 2) }}</td>@if($p->status === 'APPROVED')<td></td>@endif</tr>
        <tr><td colspan="4">{{ __('الخصم') }}</td><td class="num">{{ number_format($totals->discount_amount, 2) }}</td>@if($p->status === 'APPROVED')<td></td>@endif</tr>
        <tr><th colspan="4">{{ __('الصافي قبل الضريبة') }}</th><th class="num">{{ number_format($totals->net_before_vat, 2) }}</th>@if($p->status === 'APPROVED')<th></th>@endif</tr>
        <tr><td colspan="4">{{ __('ضريبة القيمة المضافة (كما في الفاتورة)') }}</td><td class="num">{{ number_format($totals->vat_amount, 2) }}</td>@if($p->status === 'APPROVED')<td></td>@endif</tr>
        <tr><th colspan="4">{{ __('الإجمالي') }}</th><th class="num">{{ number_format($totals->total, 2) }}</th>@if($p->status === 'APPROVED')<th></th>@endif</tr>
    </table></div>
    <p class="hint">{{ __('تكلفة الوحدة المستلمة = سعر الوحدة بعد توزيع خصم الفاتورة على البنود بنسبة قيمتها، ودون الضريبة.') }}</p>
</div>
@include('partials.chatter', ['activity' => $activity])
@endsection
