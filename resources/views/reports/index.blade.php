@extends('layouts.app')
@section('title', __('التقارير'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('التقارير'), null]]])
@endsection
@section('content')
<div class="report-list">
    @foreach($reports as $r)
        <a class="card report-card" href="{{ route('reports.show', $r->key()) }}">
            <strong>{{ $r->title() }}</strong>
            <span class="muted">{{ $r->description() }}</span>
        </a>
    @endforeach
</div>
@endsection
