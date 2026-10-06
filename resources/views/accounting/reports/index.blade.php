@extends('layouts.app')
@section('title', __('التقارير المالية'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('المحاسبة'), route('accounting.journal.index')], [__('التقارير المالية'), null]]])
@endsection
@section('content')
<div class="report-list">
    @foreach($reports as $k => $r)
        <a class="card report-card" href="{{ route('accounting.reports.show', $k) }}">
            <strong>{{ $r['title'] }}</strong>
            <span class="muted">{{ $r['description'] }}</span>
        </a>
    @endforeach
</div>
@endsection
