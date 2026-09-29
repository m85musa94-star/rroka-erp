@extends('layouts.app')
@section('title', __('الرئيسية'))
@section('hide_title', true)
@section('content')
@php($u = auth()->user())
<section class="launcher" aria-label="{{ __('التطبيقات') }}">
    @foreach(\App\Support\AppMenu::forUser($u) as $a)
        @if($a['route'])
            <a class="app-tile" href="{{ route($a['route']) }}">
                <span class="app-icon">@include('partials.app-icon', ['key' => $a['key']])</span>
                <span class="app-label">{{ $a['label'] }}</span>
            </a>
        @else
            <div class="app-tile soon" title="{{ __('قيد البناء') }}">
                <span class="app-icon">@include('partials.app-icon', ['key' => $a['key']])</span>
                <span class="app-label">{{ $a['label'] }}</span>
                <span class="soon-tag">{{ __('قريبًا') }}</span>
            </div>
        @endif
    @endforeach
</section>

<h2 class="section-title">{{ __('نظرة عامة') }}</h2>
<div class="grid kpis" style="margin-bottom:18px">
    @if($u->hasPermission('projects.view'))
        <div class="card kpi"><div class="label">{{ __('المشاريع الجارية') }}</div><div class="value">{{ $activeProjects }}</div></div>
        <div class="card kpi"><div class="label">{{ __('قيمة عقود المشاريع الجارية (قبل الضريبة)') }}</div><div class="value">{{ number_format($openContractValue, 2) }}</div></div>
    @endif
    @if($u->hasPermission('quotations.view'))
        <div class="card kpi"><div class="label">{{ __('عروض بانتظار رد العميل') }}</div><div class="value">{{ $awaiting->count() }}</div></div>
        <div class="card kpi"><div class="label">{{ __('عروض في المسودة') }}</div><div class="value">{{ $drafts }}</div></div>
    @endif
    @if($u->hasPermission('clients.view'))
        <div class="card kpi"><div class="label">{{ __('العملاء') }}</div><div class="value">{{ $clients }}</div></div>
    @endif
</div>

@if($u->hasPermission('costing.view') && ($ratesMissing['overhead'] || $ratesMissing['workers'] || $ratesMissing['machines'] || $incompleteCosting))
<div class="card">
    <h2>{{ __('التكلفة غير مكتملة') }}</h2>
    <p class="muted" style="margin-top:0">{{ __('لا يعرض النظام ربحًا مبنيًا على أرقام ناقصة. لإظهار الربح الفعلي أكمل ما يلي:') }}</p>
    <ul>
        @if($ratesMissing['workers'])<li>{{ $ratesMissing['workers'] }} {{ __('عامل بلا أجر ساعة مُدخل.') }}</li>@endif
        @if($ratesMissing['machines'])<li>{{ $ratesMissing['machines'] }} {{ __('آلة بلا تكلفة ساعة مُدخلة.') }}</li>@endif
        @if($ratesMissing['overhead'])<li>{{ __('نسبة المصروفات غير المباشرة لم تُدخل بعد.') }}</li>@endif
        @if($incompleteCosting)<li>{{ $incompleteCosting }} {{ __('مشروع تكلفته غير مكتملة.') }}</li>@endif
    </ul>
    @if($u->hasPermission('settings.cost_rates'))
        <a class="btn sm" href="{{ route('rates.index') }}">{{ __('إدخال معدلات التكلفة') }}</a>
    @endif
</div>
@endif

@if($u->hasPermission('quotations.view'))
<div class="card">
    <h2>{{ __('عروض بانتظار رد العميل') }}</h2>
    @if($awaiting->isEmpty())
        <p class="muted">{{ __('لا توجد عروض مُرسلة بانتظار الرد.') }}</p>
    @else
    <div class="table-wrap"><table>
        <tr><th>{{ __('رقم العرض') }}</th><th>{{ __('العميل') }}</th><th>{{ __('تاريخ الإصدار') }}</th><th>{{ __('صالح حتى') }}</th></tr>
        @foreach($awaiting as $q)
            <tr>
                <td><a href="{{ route('quotations.show', $q) }}">{{ $q->quotation_no }}</a></td>
                <td>{{ $q->client->business_name }}</td>
                <td class="num">{{ $q->issue_date->format('Y-m-d') }}</td>
                <td class="num">{{ $q->valid_until?->format('Y-m-d') ?? '—' }}</td>
            </tr>
        @endforeach
    </table></div>
    @endif
</div>
@endif
@endsection
