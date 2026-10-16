@extends('layouts.app')
@section('title', $x->payment_no)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('سداد الموردين'), route('supplier-payments.index')], [$x->payment_no, null]]])
@endsection
@section('content')
@php($u = auth()->user())
@php($path = $x->status === 'CANCELLED' ? ['DRAFT', 'CANCELLED'] : ['DRAFT', 'APPROVED'])
<div class="rec-bar">
    <div class="actions">
        @include('partials.delete-button', ['type' => 'supplier-payments', 'model' => $x])
        @if($x->status === 'DRAFT')
            @if($u->hasPermission('purchases.manage'))<a class="btn ghost sm" href="{{ route('supplier-payments.edit', $x) }}">{{ __('تعديل') }}</a>@endif
            @if($u->hasPermission('purchases.approve'))<form method="post" action="{{ route('supplier-payments.approve', $x) }}" class="inline" data-confirm="{{ __('اعتماد سند الصرف؟ يُرحَّل للدفاتر ولا يمكن تعديله بعدها.') }}">@csrf<button class="btn ok sm">{{ __('اعتماد') }}</button></form>@endif
            @if($u->hasPermission('purchases.manage'))<form method="post" action="{{ route('supplier-payments.cancel', $x) }}" class="inline" data-confirm="{{ __('إلغاء المسودة؟') }}">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>@endif
        @endif
        @if($x->attachment)<a class="btn ghost sm" href="{{ $x->attachment->url(false) }}" target="_blank" rel="noopener">{{ __('صورة الإيصال') }}</a>@endif
    </div>
    @include('partials.statusbar', ['path' => $path, 'current' => $x->status, 'bad' => ['CANCELLED']])
</div>
<div class="card">
    <h1 class="rec-title">{{ $x->payment_no }}</h1>
    <p class="rec-sub">{{ $x->supplier->name }}@if($x->description) · {{ $x->description }}@endif</p>
    <div class="grid g2">
        <dl class="kv">
            <dt>{{ __('التاريخ') }}</dt><dd>{{ $x->payment_date->format('Y-m-d') }}</dd>
            <dt>{{ __('المورد') }}</dt><dd><a href="{{ route('suppliers.show', $x->supplier) }}">{{ $x->supplier->name }}</a></dd>
            <dt>{{ __('عن الفاتورة') }}</dt><dd>@if($x->invoiceUrl())<a href="{{ $x->invoiceUrl() }}"><bdi>{{ $x->invoiceLabel() }}</bdi></a>@else{{ __('دفعة بلا فاتورة (مقدمة)') }}@endif</dd>
            <dt>{{ __('دُفع من') }}</dt><dd>@if($u->hasPermission('treasury.view') || $u->hasPermission('treasury.manage'))<a href="{{ route('treasury.accounts.show', $x->paymentAccount) }}">{{ $x->paymentAccount->name }}</a>@else{{ $x->paymentAccount->name }}@endif</dd>
            <dt>{{ __('المرجع') }}</dt><dd>{{ $x->reference ?? '—' }}</dd>
            @include('partials.journal-link', ['type' => 'SUPPLIER_PAYMENT', 'id' => $x->id, 'approved' => $x->status === 'APPROVED'])
        </dl>
        <div class="table-wrap"><table>
            <tr><th>{{ __('المبلغ المدفوع') }}</th><th class="num">{{ number_format($x->amount, 2) }}</th></tr>
        </table></div>
    </div>
    <dl class="kv" style="margin-top:10px">
        <dt>{{ __('أدخله') }}</dt><dd>{{ $names[$x->created_by] ?? '—' }}</dd>
        @if($x->approved_at)<dt>{{ __('اعتمده') }}</dt><dd>{{ $names[$x->approved_by] ?? '—' }} — {{ $x->approved_at->format('Y-m-d H:i') }} @if($x->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</dd>@endif
    </dl>
</div>
@include('partials.attachments', ['type' => 'supplier-payments', 'model' => $x])
@include('partials.chatter', ['activity' => $activity])
@endsection
