@extends('layouts.app')
@section('title', $t->transfer_no)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('التحويلات وصرف العهد'), route('treasury.transfers.index')], [$t->transfer_no, null]]])
@endsection
@section('content')
@php($u = auth()->user())
@php($path = $t->status === 'CANCELLED' ? ['DRAFT', 'CANCELLED'] : ['DRAFT', 'APPROVED'])
<div class="rec-bar">
    <div class="actions">
        @if($t->status === 'DRAFT')
            @if($u->hasPermission('treasury.manage'))<a class="btn ghost sm" href="{{ route('treasury.transfers.edit', $t) }}">{{ __('تعديل') }}</a>@endif
            @if($u->hasPermission('treasury.approve'))<form method="post" action="{{ route('treasury.transfers.approve', $t) }}" class="inline" data-confirm="{{ __('اعتماد التحويل؟ لا يمكن تعديله بعدها.') }}">@csrf<button class="btn ok sm">{{ __('اعتماد') }}</button></form>@endif
            @if($u->hasPermission('treasury.manage'))<form method="post" action="{{ route('treasury.transfers.cancel', $t) }}" class="inline" data-confirm="{{ __('إلغاء المسودة؟') }}">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>@endif
        @endif
    </div>
    @include('partials.statusbar', ['path' => $path, 'current' => $t->status, 'bad' => ['CANCELLED']])
</div>
<div class="card">
    <h1 class="rec-title">{{ $t->transfer_no }}</h1>
    <p class="rec-sub">{{ __('rroka.transfer_purpose.'.$t->purpose()) }}</p>
    <div class="grid g2">
        <dl class="kv">
            <dt>{{ __('التاريخ') }}</dt><dd>{{ $t->transfer_date->format('Y-m-d') }}</dd>
            <dt>{{ __('من') }}</dt><dd><a href="{{ route('treasury.accounts.show', $t->from) }}">{{ $t->from->name }}</a></dd>
            <dt>{{ __('إلى') }}</dt><dd><a href="{{ route('treasury.accounts.show', $t->to) }}">{{ $t->to->name }}</a></dd>
            <dt>{{ __('المرجع') }}</dt><dd>{{ $t->reference ?? '—' }}</dd>
            @if($t->notes)<dt>{{ __('ملاحظات') }}</dt><dd>{{ $t->notes }}</dd>@endif
            @include('partials.journal-link', ['type' => 'TRANSFER', 'id' => $t->id, 'approved' => $t->status === 'APPROVED'])
        </dl>
        <div class="acct-figure">
            <span class="label">{{ __('المبلغ') }}</span>
            <span class="value num">{{ number_format($t->amount, 2) }}</span>
            @foreach([$t->from, $t->to] as $acc)
                @if($acc->isCustody())<span class="hint">{{ __(':name — في العهدة الآن: :n', ['name' => $acc->name, 'n' => number_format($acc->summary->custody_balance, 2)]) }}@if($acc->custody_limit) {{ __('(الحد :n)', ['n' => number_format($acc->custody_limit, 2)]) }}@endif</span>@endif
            @endforeach
        </div>
    </div>
    <dl class="kv" style="margin-top:10px">
        <dt>{{ __('أدخله') }}</dt><dd>{{ $names[$t->created_by] ?? '—' }}</dd>
        @if($t->approved_at)<dt>{{ __('اعتمده') }}</dt><dd>{{ $names[$t->approved_by] ?? '—' }} — {{ $t->approved_at->format('Y-m-d H:i') }} @if($t->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</dd>@endif
        <dt>{{ __('دفترة') }}</dt><dd>{{ $t->daftra_transfer_id ? __('مرتبط (رقم :no)', ['no' => $t->daftra_transfer_id]) : __('لم يُرسل — الإرسال موقوف حتى التحقق من ربط دفترة') }}</dd>
    </dl>
</div>
@include('partials.chatter', ['activity' => $activity])
@endsection
