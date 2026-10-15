@extends('layouts.app')
@section('title', $x->txn_no)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('حركات المالك'), route('accounting.owner.index')], [$x->txn_no, null]]])
@endsection
@section('content')
@php($u = auth()->user())
@php($path = $x->status === 'CANCELLED' ? ['DRAFT', 'CANCELLED'] : ['DRAFT', 'APPROVED'])
<div class="rec-bar">
    <div class="actions">
        @include('partials.delete-button', ['type' => 'owner-transactions', 'model' => $x])
        @if($x->status === 'DRAFT')
            @if($u->hasPermission('accounting.manage'))<a class="btn ghost sm" href="{{ route('accounting.owner.edit', $x) }}">{{ __('تعديل') }}</a>@endif
            @if($u->hasPermission('accounting.post'))<form method="post" action="{{ route('accounting.owner.approve', $x) }}" class="inline" data-confirm="{{ __('اعتماد حركة المالك؟ تُرحَّل للدفاتر ولا يمكن تعديلها بعدها.') }}">@csrf<button class="btn ok sm">{{ __('اعتماد') }}</button></form>@endif
            @if($u->hasPermission('accounting.manage'))<form method="post" action="{{ route('accounting.owner.cancel', $x) }}" class="inline" data-confirm="{{ __('إلغاء المسودة؟') }}">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>@endif
        @endif
        @if($x->attachment)<a class="btn ghost sm" href="{{ $x->attachment->url(false) }}" target="_blank" rel="noopener">{{ __('صورة الإيصال') }}</a>@endif
    </div>
    @include('partials.statusbar', ['path' => $path, 'current' => $x->status, 'bad' => ['CANCELLED']])
</div>
<div class="card">
    <h1 class="rec-title">{{ $x->txn_no }}</h1>
    <p class="rec-sub">{{ __("rroka.owner_kind.$x->kind") }} · {{ $x->description }}</p>
    <div class="grid g2">
        <dl class="kv">
            <dt>{{ __('التاريخ') }}</dt><dd>{{ $x->txn_date->format('Y-m-d') }}</dd>
            <dt>{{ __('الحساب') }}</dt><dd>@if($u->hasPermission('treasury.view') || $u->hasPermission('treasury.manage'))<a href="{{ route('treasury.accounts.show', $x->paymentAccount) }}">{{ $x->paymentAccount->name }}</a>@else{{ $x->paymentAccount->name }}@endif</dd>
            <dt>{{ __('المرجع') }}</dt><dd>{{ $x->reference ?? '—' }}</dd>
            @include('partials.journal-link', ['type' => 'OWNER', 'id' => $x->id, 'approved' => $x->status === 'APPROVED'])
        </dl>
        <div class="table-wrap"><table><tr><th>{{ __('المبلغ') }}</th><th class="num">{{ number_format($x->amount, 2) }}</th></tr></table></div>
    </div>
    <dl class="kv" style="margin-top:10px">
        <dt>{{ __('أدخله') }}</dt><dd>{{ $names[$x->created_by] ?? '—' }}</dd>
        @if($x->approved_at)<dt>{{ __('اعتمده') }}</dt><dd>{{ $names[$x->approved_by] ?? '—' }} — {{ $x->approved_at->format('Y-m-d H:i') }} @if($x->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</dd>@endif
    </dl>
</div>
@include('partials.chatter', ['activity' => $activity])
@endsection
