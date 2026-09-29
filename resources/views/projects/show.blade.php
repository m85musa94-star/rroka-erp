@extends('layouts.app')
@section('title', $project->project_no.' — '.$project->title)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('المشاريع'), route('projects.index')], [$project->project_no, null]]])
@endsection
@section('content')
@php
    $m = fn ($v) => $v === null ? __('<span class="na">غير مكتمل</span>') : number_format($v, 2);
    $next = match ($project->status) {
        'ACTIVE' => [['IN_PRODUCTION', __('بدء الإنتاج'), '', null], ['ON_HOLD', __('إيقاف مؤقت'), 'ghost', null], ['CANCELLED', __('إلغاء المشروع'), 'ghost', __('إلغاء المشروع نهائيًا؟')]],
        'IN_PRODUCTION' => [['INSTALLATION', __('الانتقال إلى التركيب'), '', null], ['ON_HOLD', __('إيقاف مؤقت'), 'ghost', null], ['CANCELLED', __('إلغاء المشروع'), 'ghost', __('إلغاء المشروع نهائيًا؟')]],
        'INSTALLATION' => [['COMPLETED', __('إكمال المشروع'), 'ok', __('إكمال المشروع؟ لن يمكن تغيير مرحلته بعد ذلك.')], ['ON_HOLD', __('إيقاف مؤقت'), 'ghost', null]],
        'ON_HOLD' => [['ACTIVE', __('استئناف'), '', null], ['CANCELLED', __('إلغاء المشروع'), 'ghost', __('إلغاء المشروع نهائيًا؟')]],
        default => [],
    };
    $path = in_array($project->status, ['ON_HOLD', 'CANCELLED'], true)
        ? ['ACTIVE', $project->status]
        : ['ACTIVE', 'IN_PRODUCTION', 'INSTALLATION', 'COMPLETED'];
@endphp
<div class="rec-bar">
    <div class="actions">
        @if(auth()->user()->hasPermission('projects.manage'))
            @foreach($next as [$to, $label, $style, $confirm])
                <form method="post" action="{{ route('projects.stage', [$project, $to]) }}" class="inline" @if($confirm) data-confirm="{{ __($confirm) }}" @endif>@csrf
                    <button class="btn sm {{ $style }}">{{ $label }}</button>
                </form>
            @endforeach
        @endif
        <a class="btn ghost sm" href="{{ route('quotations.show', $project->quotation) }}">{{ __('عرض السعر') }} {{ $project->quotation->quotation_no }}</a>
    </div>
    @include('partials.statusbar', ['path' => $path, 'current' => $project->status, 'bad' => ['ON_HOLD', 'CANCELLED']])
</div>
<div class="card">
    <h1 class="rec-title">{{ $project->title }}</h1>
    <p class="rec-sub">{{ $project->project_no }}</p>
    <dl class="kv">
        <dt>{{ __('العميل') }}</dt><dd><a href="{{ route('clients.show', $project->client) }}">{{ $project->client->business_name }}</a></dd>
        <dt>{{ __('عرض السعر') }}</dt><dd><a href="{{ route('quotations.show', $project->quotation) }}">{{ $project->quotation->quotation_no }}</a></dd>
        <dt>{{ __('قيمة العقد') }}</dt><dd>{{ number_format($project->contract_value, 2) }} <span class="muted">{{ __('(قبل الضريبة)') }}</span></dd>
        <dt>{{ __('البدء') }}</dt><dd>{{ $project->start_date->format('Y-m-d') }}</dd>
        <dt>{{ __('التسليم المستهدف') }}</dt><dd>{{ $project->target_date?->format('Y-m-d') ?? '—' }}</dd>
        <dt>{{ __('مدير المشروع') }}</dt><dd>{{ $manager ?? '—' }}</dd>
    </dl>
</div>

@if($cost)
<div class="card">
    <h2>{{ __('التكلفة الفعلية والربحية') }}</h2>
    @if($cost->gaps)
        <div class="alert warn">
            {{ __('التكلفة غير مكتملة، لذلك لا يُعرض الربح. الناقص:') }}
            <ul>@foreach($cost->gaps as $g)<li>{{ __("rroka.gaps.$g") }}</li>@endforeach</ul>
        </div>
    @endif
    <div class="table-wrap"><table>
        <tr><th>{{ __('البند') }}</th><th class="num">{{ __('الساعات') }}</th><th class="num">{{ __('المبلغ') }}</th></tr>
        <tr><td>{{ __('الخامات المصروفة (صافي المرتجع)') }}</td><td class="num">—</td><td class="num">{!! $m($cost->material_cost) !!}</td></tr>
        <tr><td>{{ __('العمالة المباشرة') }}</td><td class="num">{{ $cost->labor_hours + 0 }}</td><td class="num">{!! $m($cost->labor_cost) !!}</td></tr>
        <tr><td>{{ __('تشغيل الآلات') }}</td><td class="num">{{ $cost->machine_hours + 0 }}</td><td class="num">{!! $m($cost->machine_cost) !!}</td></tr>
        <tr><td>{{ __('المصروفات غير المباشرة') }} @if($cost->overhead_basis)<span class="muted">({{ $cost->overhead_rate_pct + 0 }}% — {{ __("rroka.overhead_basis.$cost->overhead_basis") }})</span>@endif</td><td class="num">—</td><td class="num">{!! $m($cost->overhead_cost) !!}</td></tr>
        <tr><th>{{ __('إجمالي التكلفة') }}</th><th></th><th class="num">{!! $m($cost->total_cost) !!}</th></tr>
        <tr><th>{{ __('مجمل الربح') }}</th><th></th><th class="num">{!! $m($cost->gross_profit) !!}</th></tr>
        <tr><th>{{ __('هامش مجمل الربح') }}</th><th></th><th class="num">{!! $cost->gross_margin_pct === null ? __('<span class="na">غير مكتمل</span>') : $cost->gross_margin_pct.'%' !!}</th></tr>
    </table></div>
    <p class="hint">{{ __('الربح هنا ربح تشغيلي للمشروع قبل الضريبة، ولا يحل محل القوائم المالية في دفترة.') }}</p>
</div>
@endif
@include('partials.studio-strip', ['scope' => ['project_id' => $project->id]])
@include('partials.chatter', ['activity' => $activity])
@endsection
