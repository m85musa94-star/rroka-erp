@extends('layouts.app')
@section('title', $c->contract_no)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('العقود'), route('contracts.index')], [$c->contract_no, null]]])
@endsection
@section('content')
@php($path = $c->status === 'CANCELLED' ? ['DRAFT', 'CANCELLED'] : ['DRAFT', 'RUNNING', 'EXPIRED'])
<div class="rec-bar">
    <div class="actions">
        @if($c->status === 'DRAFT')
            <a class="btn ghost sm" href="{{ route('contracts.edit', $c) }}">{{ __('تعديل') }}</a>
            <form method="post" action="{{ route('contracts.transition', [$c, 'start']) }}" class="inline" data-confirm="{{ __('تفعيل العقد؟ تُجمَّد شروطه بعد التفعيل.') }}">@csrf<button class="btn sm">{{ __('تفعيل العقد') }}</button></form>
        @endif
        @if($c->status === 'RUNNING')
            <details class="inline-details"><summary class="btn ghost sm">{{ __('إنهاء العقد') }}</summary>
                <form method="post" action="{{ route('contracts.transition', [$c, 'close']) }}" class="inline-form" style="margin-top:6px">@csrf
                    <input type="date" name="end_date" value="{{ ($c->end_date ?? now())->format('Y-m-d') }}" required><button class="btn sm">{{ __('تسجيل الانتهاء') }}</button></form>
            </details>
        @endif
        @if(in_array($c->status, ['DRAFT', 'RUNNING'], true))
            <form method="post" action="{{ route('contracts.transition', [$c, 'cancel']) }}" class="inline" data-confirm="{{ __('إلغاء العقد؟') }}">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>
        @endif
    </div>
    @include('partials.statusbar', ['path' => $path, 'current' => $c->status, 'bad' => ['CANCELLED']])
</div>
<div class="card">
    <h1 class="rec-title">{{ $c->contract_no }}</h1>
    <p class="rec-sub"><a href="{{ route('employees.show', $c->employee) }}">{{ $c->employee->name }}</a> · {{ __("rroka.contract_type.$c->contract_type") }}</p>
    <div class="grid g2">
        <dl class="kv">
            <dt>{{ __('البداية') }}</dt><dd>{{ $c->start_date->format('Y-m-d') }}</dd>
            <dt>{{ __('النهاية') }}</dt><dd>{{ $c->end_date?->format('Y-m-d') ?? '—' }} @if($c->endingSoon())<span class="badge b-ON_HOLD">{{ __('ينتهي قريبًا') }}</span>@endif</dd>
            <dt>{{ __('ساعات العمل الأسبوعية') }}</dt><dd>{{ $c->weekly_hours !== null ? $c->weekly_hours + 0 : '—' }}</dd>
        </dl>
        <div class="table-wrap"><table>
            <tr><td>{{ __('الراتب الأساسي') }}</td><td class="num">{{ number_format($c->basic_salary, 2) }}</td></tr>
            <tr><td>{{ __('بدل السكن') }}</td><td class="num">{{ number_format($c->housing_allowance, 2) }}</td></tr>
            <tr><td>{{ __('بدل النقل') }}</td><td class="num">{{ number_format($c->transport_allowance, 2) }}</td></tr>
            <tr><td>{{ __('بدلات أخرى') }}</td><td class="num">{{ number_format($c->other_allowance, 2) }}</td></tr>
            <tr><th>{{ __('الإجمالي الشهري') }}</th><th class="num">{{ number_format($c->monthlyGross(), 2) }}</th></tr>
        </table></div>
    </div>
    @if($c->notes)<p class="muted">{{ $c->notes }}</p>@endif
    <p class="hint">{{ __('الإجمالي الشهري مجموع بنود العقد فقط، ولا يشمل التأمينات الاجتماعية ولا أي استقطاع. لا تُحسب الرواتب في هذا النظام.') }}</p>
</div>
@include('partials.chatter', ['activity' => $activity])
@endsection
