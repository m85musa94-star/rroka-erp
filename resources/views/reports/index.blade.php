@extends('layouts.app')
@section('title', __('التقارير'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('التقارير'), null]]])
@endsection
@section('content')
@if($financial)
    <h2 class="report-group">{{ __('التقارير المالية') }}</h2>
    <div class="report-list">
        @foreach($financial as $k => $r)
            <a class="card report-card" href="{{ route('accounting.reports.show', $k) }}">
                <span class="report-icon">@include('partials.app-icon', ['key' => 'accounting', 'size' => 24])</span>
                <strong>{{ $r['title'] }}</strong>
                <span class="muted">{{ $r['description'] }}</span>
            </a>
        @endforeach
    </div>
@endif
@if($reports)
    <h2 class="report-group">{{ __('تحليلات التشغيل') }}</h2>
    <div class="report-list">
        @foreach($reports as $r)
            <a class="card report-card" href="{{ route('reports.show', $r->key()) }}">
                <span class="report-icon">@include('partials.app-icon', ['key' => ['quotations' => 'quotations', 'projects' => 'projects', 'profitability' => 'costing', 'purchases' => 'purchasing', 'expenses' => 'expenses', 'consumption' => 'inventory'][$r->key()] ?? 'reports', 'size' => 24])</span>
                <strong>{{ $r->title() }}</strong>
                <span class="muted">{{ $r->description() }}</span>
            </a>
        @endforeach
    </div>
@endif
@endsection
