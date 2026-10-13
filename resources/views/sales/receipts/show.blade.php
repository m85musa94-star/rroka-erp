@extends('layouts.app')
@section('title', $x->receipt_no)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('سندات القبض'), route('sales.receipts.index')], [$x->receipt_no, null]]])
@endsection
@section('content')
@php($u = auth()->user())
@php($path = $x->status === 'CANCELLED' ? ['DRAFT', 'CANCELLED'] : ['DRAFT', 'APPROVED'])
<div class="rec-bar">
    <div class="actions">
        @include('partials.delete-button', ['type' => 'receipts', 'model' => $x])
        @if($x->status === 'DRAFT')
            @if($u->hasPermission('sales.manage'))<a class="btn ghost sm" href="{{ route('sales.receipts.edit', $x) }}">{{ __('تعديل') }}</a>@endif
            @if($u->hasPermission('sales.approve'))<form method="post" action="{{ route('sales.receipts.approve', $x) }}" class="inline" data-confirm="{{ __('اعتماد سند القبض؟ يُرحَّل للدفاتر ولا يمكن تعديله بعدها.') }}">@csrf<button class="btn ok sm">{{ __('اعتماد') }}</button></form>@endif
            @if($u->hasPermission('sales.manage'))<form method="post" action="{{ route('sales.receipts.cancel', $x) }}" class="inline" data-confirm="{{ __('إلغاء المسودة؟') }}">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>@endif
        @endif
        @if($x->attachment)<a class="btn ghost sm" href="{{ $x->attachment->url(false) }}" target="_blank" rel="noopener">{{ __('صورة الإيصال') }}</a>@endif
    </div>
    @include('partials.statusbar', ['path' => $path, 'current' => $x->status, 'bad' => ['CANCELLED']])
</div>
<div class="card">
    <h1 class="rec-title">{{ $x->receipt_no }}</h1>
    <p class="rec-sub">{{ $x->client->business_name }}@if($x->description) · {{ $x->description }}@endif</p>
    <div class="grid g2">
        <dl class="kv">
            <dt>{{ __('التاريخ') }}</dt><dd>{{ $x->receipt_date->format('Y-m-d') }}</dd>
            <dt>{{ __('العميل') }}</dt><dd>@if($u->hasPermission('clients.view'))<a href="{{ route('clients.show', $x->client) }}">{{ $x->client->business_name }}</a>@else{{ $x->client->business_name }}@endif</dd>
            <dt>{{ __('الفاتورة') }}</dt><dd>@if($x->invoice)<a href="{{ route('sales.invoices.show', $x->invoice) }}">{{ $x->invoice->invoice_no }}</a> (<bdi dir="ltr">{{ $x->invoice->daftra_invoice_no }}</bdi>)@else{{ __('دفعة بلا فاتورة (مقدمة)') }}@endif</dd>
            <dt>{{ __('المشروع') }}</dt><dd>@if($x->project)<a href="{{ route('projects.show', $x->project) }}">{{ $x->project->project_no }}</a>@else{{ __('بلا مشروع') }}@endif</dd>
            <dt>{{ __('استُلم في') }}</dt><dd>@if($u->hasPermission('treasury.view') || $u->hasPermission('treasury.manage'))<a href="{{ route('treasury.accounts.show', $x->paymentAccount) }}">{{ $x->paymentAccount->name }}</a>@else{{ $x->paymentAccount->name }}@endif</dd>
            <dt>{{ __('المرجع') }}</dt><dd>{{ $x->reference ?? '—' }}</dd>
            @include('partials.journal-link', ['type' => 'RECEIPT', 'id' => $x->id, 'approved' => $x->status === 'APPROVED'])
        </dl>
        <div class="table-wrap"><table>
            <tr><th>{{ __('المبلغ المستلم') }}</th><th class="num">{{ number_format($x->amount, 2) }}</th></tr>
        </table></div>
    </div>
    <dl class="kv" style="margin-top:10px">
        <dt>{{ __('أدخله') }}</dt><dd>{{ $names[$x->created_by] ?? '—' }}</dd>
        @if($x->approved_at)<dt>{{ __('اعتمده') }}</dt><dd>{{ $names[$x->approved_by] ?? '—' }} — {{ $x->approved_at->format('Y-m-d H:i') }} @if($x->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</dd>@endif
    </dl>
</div>
@include('partials.chatter', ['activity' => $activity])
@endsection
