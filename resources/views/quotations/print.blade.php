@extends('layouts.print')
@section('title', __('عرض سعر').' '.$q->quotation_no)
@section('content')
@php($hasImg = $q->lines->contains(fn ($l) => $l->studio_asset_id))
<div class="doc-head">
    <div>
        <h1 class="doc-title">{{ __('عرض سعر') }}</h1>
        <div class="doc-no">{{ $q->quotation_no }}</div>
        @if(in_array($q->status, ['DRAFT', 'CANCELLED', 'REJECTED', 'EXPIRED'], true))<span class="stamp">{{ __("rroka.status.$q->status") }}</span>@endif
    </div>
    <table class="meta">
        <tr><td>{{ __('التاريخ') }}</td><td class="num">{{ $q->issue_date->format('Y-m-d') }}</td></tr>
        @if($q->valid_until)<tr><td>{{ __('صالح حتى') }}</td><td class="num">{{ $q->valid_until->format('Y-m-d') }}</td></tr>@endif
    </table>
</div>
<table class="meta">
    <tr><td>{{ __('العميل') }}</td><td><strong>{{ $q->client->business_name }}</strong></td></tr>
    @if($q->client->vat_number)<tr><td>{{ __('الرقم الضريبي للعميل') }}</td><td class="num">{{ $q->client->vat_number }}</td></tr>@endif
    @if($q->client->phone)<tr><td>{{ __('الجوال') }}</td><td class="num">{{ $q->client->phone }}</td></tr>@endif
    @if($q->client->city || $q->client->address)<tr><td>{{ __('العنوان') }}</td><td>{{ collect([$q->client->city, $q->client->address])->filter()->join(__('، ')) }}</td></tr>@endif
</table>

<table class="lines">
    <thead><tr><th>#</th>@if($hasImg)<th></th>@endif<th>{{ __('الوصف') }}</th><th class="num">{{ __('الكمية') }}</th><th>{{ __('الوحدة') }}</th><th class="num">{{ __('سعر الوحدة') }}</th><th class="num">{{ __('الإجمالي') }}</th></tr></thead>
    <tbody>
    @foreach($q->lines as $l)
        <tr><td class="num">{{ $l->line_no }}</td>
            @if($hasImg)<td>@if($l->studioAsset)<img class="line-img" src="{{ $l->studioAsset->url() }}" alt="">@endif</td>@endif
            <td>{{ $l->description }}</td><td class="num">{{ rtrim(rtrim($l->quantity, '0'), '.') }}</td><td>{{ $l->unit }}</td>
            <td class="num">{{ number_format($l->unit_price, 2) }}</td><td class="num">{{ number_format($l->line_total, 2) }}</td></tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>{{ __('المجموع') }}</td><td class="num">{{ number_format($totals['subtotal'], 2) }}</td></tr>
    @if((float) $totals['discount_amount'] > 0)<tr><td>{{ __('الخصم') }}</td><td class="num">− {{ number_format($totals['discount_amount'], 2) }}</td></tr>@endif
    <tr><td>{{ __('الصافي قبل ضريبة القيمة المضافة') }}</td><td class="num">{{ number_format($totals['net_before_vat'], 2) }}</td></tr>
    @if($totals['vat_pct'] !== null)
        <tr><td>{{ __('ضريبة القيمة المضافة :p%', ['p' => rtrim(rtrim(number_format($totals['vat_pct'], 2), '0'), '.')]) }}</td><td class="num">{{ number_format($totals['vat_amount'], 2) }}</td></tr>
        <tr class="grand"><td>{{ __('الإجمالي شامل الضريبة') }}</td><td class="num">{{ number_format($totals['total_incl_vat'], 2) }} {{ __('ريال') }}</td></tr>
    @else
        <tr class="grand"><td>{{ __('الإجمالي') }}</td><td class="num">{{ number_format($totals['net_before_vat'], 2) }} {{ __('ريال') }}</td></tr>
    @endif
</table>

@if($q->notes)
<div class="notes"><h3>{{ __('ملاحظات') }}</h3><div>{!! nl2br(e($q->notes)) !!}</div></div>
@endif
<div class="sign"><div>{{ __('اعتماد الشركة') }}</div><div>{{ __('موافقة العميل') }}</div></div>
@endsection
