@extends('layouts.app')
@section('title', __('عرض السعر ').$q->quotation_no)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('عروض الأسعار'), route('quotations.index')], [$q->quotation_no, null]]])
@endsection
@section('content')
@php
    $u = auth()->user();
    $path = match ($q->status) {
        'REJECTED', 'EXPIRED' => ['DRAFT', 'SENT', $q->status],
        'CANCELLED' => ['DRAFT', 'CANCELLED'],
        default => ['DRAFT', 'SENT', 'APPROVED'],
    };
@endphp
<div class="rec-bar">
    <div class="actions">
        @if($q->status === 'DRAFT' && $u->hasPermission('quotations.manage'))
            <a class="btn ghost sm" href="{{ route('quotations.edit', $q) }}">{{ __('تعديل') }}</a>
            <form method="post" action="{{ route('quotations.transition', [$q, 'send']) }}" class="inline" data-confirm="{{ __('بعد الإرسال يُجمَّد العرض ولا يُعدَّل إلا بإعادته إلى مسودة. متابعة؟') }}">@csrf<button class="btn sm">{{ __('تسجيل الإرسال للعميل') }}</button></form>
        @endif
        @if($q->status === 'SENT')
            @if($u->hasPermission('quotations.approve'))
                <form method="post" action="{{ route('quotations.transition', [$q, 'approve']) }}" class="inline" data-confirm="{{ __('اعتماد العرض بموافقة العميل؟') }}">@csrf<button class="btn ok sm">{{ __('اعتماد (وافق العميل)') }}</button></form>
                <form method="post" action="{{ route('quotations.transition', [$q, 'reject']) }}" class="inline" data-confirm="{{ __('تسجيل رفض العميل؟') }}">@csrf<button class="btn bad sm">{{ __('رفض العميل') }}</button></form>
            @endif
            @if($u->hasPermission('quotations.manage'))
                <form method="post" action="{{ route('quotations.transition', [$q, 'revise']) }}" class="inline">@csrf<button class="btn ghost sm">{{ __('إعادة إلى مسودة') }}</button></form>
            @endif
        @endif
        @if(in_array($q->status, ['DRAFT', 'SENT']) && $u->hasPermission('quotations.manage'))
            <form method="post" action="{{ route('quotations.transition', [$q, 'cancel']) }}" class="inline" data-confirm="{{ __('إلغاء العرض نهائيًا؟') }}">@csrf<button class="btn ghost sm">{{ __('إلغاء العرض') }}</button></form>
        @endif
        @if($q->status === 'APPROVED' && ! $q->project && $u->hasPermission('projects.manage'))
            <a class="btn sm" href="{{ route('projects.create', ['quotation_id' => $q->id]) }}">{{ __('إنشاء مشروع') }}</a>
        @endif
        @if($q->project)
            <a class="btn ghost sm" href="{{ route('projects.show', $q->project) }}">{{ __('المشروع') }} {{ $q->project->project_no }}</a>
        @endif
        @if(in_array($q->status, ['SENT', 'APPROVED']) && ! $q->daftra_estimate_id && $u->hasPermission('daftra.sync'))
            <form method="post" action="{{ route('quotations.sync', $q) }}" class="inline">@csrf<button class="btn ghost sm">{{ __('إنشاء في دفترة') }}</button></form>
        @endif
        <button class="btn ghost sm" onclick="window.print()">{{ __('طباعة') }}</button>
    </div>
    @include('partials.statusbar', ['path' => $path, 'current' => $q->status, 'bad' => ['REJECTED', 'EXPIRED', 'CANCELLED']])
</div>
<div class="card">
    <h1 class="rec-title">{{ $q->quotation_no }}</h1>
    <dl class="kv">
        <dt>{{ __('العميل') }}</dt><dd><a href="{{ route('clients.show', $q->client) }}">{{ $q->client->business_name }}</a></dd>
        <dt>{{ __('تاريخ الإصدار') }}</dt><dd>{{ $q->issue_date->format('Y-m-d') }}</dd>
        <dt>{{ __('صالح حتى') }}</dt><dd>{{ $q->valid_until?->format('Y-m-d') ?? '—' }}</dd>
        @if($q->approved_at)<dt>{{ __('اعتُمد') }}</dt><dd>{{ $q->approved_at->format('Y-m-d H:i') }} — {{ $approver }}</dd>@endif
        <dt>{{ __('دفترة') }}</dt><dd>{{ $q->daftra_estimate_id ? __('مرتبط (رقم :no)', ['no' => $q->daftra_estimate_id]) : __('غير مرتبط') }}</dd>
        @if($q->notes)<dt>{{ __('ملاحظات') }}</dt><dd>{{ $q->notes }}</dd>@endif
    </dl>
</div>

<div class="card">
    <h2>{{ __('البنود') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>#</th><th>{{ __('الوصف') }}</th><th class="num">{{ __('الكمية') }}</th><th>{{ __('الوحدة') }}</th><th class="num">{{ __('سعر الوحدة') }}</th><th class="num">{{ __('الإجمالي') }}</th></tr>
        @foreach($q->lines as $l)
            <tr><td>{{ $l->line_no }}</td><td>{{ $l->description }}</td><td class="num">{{ rtrim(rtrim($l->quantity, '0'), '.') }}</td>
                <td>{{ $l->unit }}</td><td class="num">{{ number_format($l->unit_price, 2) }}</td><td class="num">{{ number_format($l->line_total, 2) }}</td></tr>
        @endforeach
        <tr><td colspan="5">{{ __('المجموع') }}</td><td class="num">{{ number_format($totals['subtotal'], 2) }}</td></tr>
        <tr><td colspan="5">{{ __('الخصم') }}</td><td class="num">{{ number_format($totals['discount_amount'], 2) }}</td></tr>
        <tr><th colspan="5">{{ __('الصافي قبل ضريبة القيمة المضافة') }}</th><th class="num">{{ number_format($totals['net_before_vat'], 2) }}</th></tr>
    </table></div>
    <p class="hint">{{ __('ضريبة القيمة المضافة والفاتورة الرسمية تصدران من دفترة.') }}</p>
</div>

@include('partials.sync-log', ['log' => $syncLog])
@include('partials.chatter', ['activity' => $activity])
@endsection
