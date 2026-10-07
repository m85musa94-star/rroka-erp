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

@php($done = collect($setup)->where('done', true)->count())
<div class="home-board">
    @if($setup && $done < count($setup))
    <section class="card setup-card" aria-labelledby="setup-title">
        <div class="setup-head">
            <h2 id="setup-title">{{ __('البدء بالمنظومة') }}</h2>
            <span class="muted">{{ __(':d من :n خطوات', ['d' => $done, 'n' => count($setup)]) }}</span>
        </div>
        <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="{{ count($setup) }}" aria-valuenow="{{ $done }}"><span style="width: {{ round(100 * $done / count($setup)) }}%"></span></div>
        <ol class="setup-steps">
            @foreach($setup as $i => $s)
                <li @class(['done' => $s['done']])>
                    <span class="step-mark" aria-hidden="true">{{ $s['done'] ? '✓' : $i + 1 }}</span>
                    <a href="{{ $s['url'] }}">{{ $s['label'] }}</a>
                    @if($s['done'])<span class="sr-only">{{ __('مكتملة') }}</span>@else<span class="hint">{{ $s['hint'] }}</span>@endif
                </li>
            @endforeach
        </ol>
    </section>
    @endif

    <section aria-labelledby="todo-title">
        <h2 class="section-title" id="todo-title">{{ __('ما ينتظر إجراءً منك') }}</h2>
        @if(empty($actions))
            <div class="card empty-state"><strong>{{ __('لا شيء ينتظر إجراءً منك الآن') }}</strong>
                {{ __('ستظهر هنا المستندات التي تنتظر اعتمادك، والعروض بانتظار رد العميل، وما يحتاج متابعة — كل بطاقة رابط مباشر لسجلاتها.') }}</div>
        @else
            <div class="action-grid">
                @foreach($actions as $a)
                    <a class="card action-card tone-{{ $a['tone'] }}" href="{{ $a['url'] }}">
                        <span class="action-count">{{ $a['count'] }}</span>
                        <span class="action-label">{{ $a['label'] }}</span>
                        @if($a['amount'] !== null)<span class="action-amount">{{ number_format($a['amount'], 2) }}</span>@endif
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</div>
@endsection
