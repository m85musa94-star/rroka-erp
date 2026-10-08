@extends('layouts.app')
@section('title', __('التقارير'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('التقارير'), null]]])
@endsection
@section('content')
@php
    $sections = collect(\App\Reports\ReportRegistry::sections())->map(fn ($label) => ['label' => $label, 'items' => []])->all();
    foreach ($financial as $k => $r) {
        $sections['finance']['items'][] = ['url' => route('accounting.reports.show', $k), 'title' => $r['title'], 'desc' => $r['description'], 'icon' => 'accounting', 'tag' => __('قائمة مالية')];
    }
    foreach ($reports as $r) {
        $sections[\App\Reports\ReportRegistry::sectionOf($r->key())]['items'][] = ['url' => route('reports.show', $r->key()), 'title' => $r->title(), 'desc' => $r->description(), 'icon' => \App\Reports\ReportRegistry::iconOf($r->key()), 'tag' => null];
    }
    $sections = array_filter($sections, fn ($s) => $s['items']);
@endphp
<nav class="report-jump" aria-label="{{ __('أقسام التقارير') }}">
    @foreach($sections as $k => $s)<a class="sec-{{ $k }}" href="#sec-{{ $k }}">{{ $s['label'] }} <span>{{ count($s['items']) }}</span></a>@endforeach
</nav>
@foreach($sections as $k => $s)
    <section class="report-section sec-{{ $k }}" id="sec-{{ $k }}">
        <h2 class="report-band"><span>{{ $s['label'] }}</span> <span class="band-count">{{ count($s['items']) }}</span></h2>
        <div class="report-list">
            @foreach($s['items'] as $i)
                <a class="card report-card" href="{{ $i['url'] }}">
                    <span class="report-icon">@include('partials.app-icon', ['key' => $i['icon'], 'size' => 24])</span>
                    <strong>{{ $i['title'] }} @if($i['tag'])<span class="badge">{{ $i['tag'] }}</span>@endif</strong>
                    <span class="muted">{{ $i['desc'] }}</span>
                </a>
            @endforeach
        </div>
    </section>
@endforeach
@endsection
