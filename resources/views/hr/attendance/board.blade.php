@extends('layouts.app')
@section('title', __('الحضور اليوم'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الحضور'), null], [__('اليوم :date', ['date' => today()->format('Y-m-d')]), null]]])
@endsection
@section('content')
@php($colors = ['#8E5486', '#2A5F7E', '#E8833A', '#1e7a4a', '#5AB2F2', '#b3261e'])
@php($present = $open->count())
<div class="stats" style="margin:0 0 14px">
    <div class="stat"><div class="k">{{ __('حاضرون الآن') }}</div><div class="v">{{ $present }}</div></div>
    <div class="stat"><div class="k">{{ __('في إجازة اليوم') }}</div><div class="v">{{ $onLeave->count() }}</div></div>
    <div class="stat"><div class="k">{{ __('الموظفون النشطون') }}</div><div class="v">{{ $employees->count() }}</div></div>
</div>
@if($employees->isEmpty())
    <div class="card empty-state"><strong>{{ __('لا يوجد موظفون نشطون') }}</strong>{{ __('أضف الموظفين من تطبيق الموظفين.') }}</div>
@else
<div class="kb-cards">
    @foreach($employees as $e)
        @php($o = $open->get($e->id))
        @php($hours = $todayRows->get($e->id)?->sum(fn ($a) => $a->check_out ? (float) $a->worked_hours : now()->diffInMinutes($a->check_in, true) / 60) ?? 0)
        <div class="kb-card" style="display:flex;gap:12px;align-items:center">
            <span class="kb-avatar" style="background: {{ $colors[$e->id % count($colors)] }}">{{ mb_substr($e->name, 0, 1) }}</span>
            <span style="min-width:0;flex:1">
                <span class="kb-title" style="display:block">{{ $e->name }}</span>
                <span class="kb-meta">
                    @if($onLeave->has($e->id))<span class="badge b-ON_HOLD">{{ __('في إجازة') }}</span>
                    @elseif($o)<span class="badge b-ACTIVE">{{ __('حاضر منذ :t', ['t' => $o->check_in->timezone(config('app.timezone'))->format('H:i')]) }}</span>
                    @else<span class="muted">{{ __('غير حاضر') }}</span>@endif
                    <bdi dir="ltr" style="white-space:nowrap">{{ number_format($hours, 2) }} h</bdi>
                </span>
            </span>
            @unless($onLeave->has($e->id))
                <form method="post" action="{{ route('attendance.toggle', $e) }}">@csrf
                    <button class="btn sm {{ $o ? 'bad' : 'ok' }}">{{ $o ? __('انصراف') : __('حضور') }}</button></form>
            @endunless
        </div>
    @endforeach
</div>
@endif
@endsection
