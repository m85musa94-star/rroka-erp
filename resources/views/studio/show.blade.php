@extends('layouts.app')
@section('title', $asset->title)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الاستوديو'), route('studio.index')], [$asset->asset_no, null]]])
@endsection
@section('content')
@php($u = auth()->user())
<div class="rec-bar">
    <div class="actions">
        @if($u->hasPermission('studio.manage'))
            <a class="btn sm" href="{{ route('studio.edit', $asset) }}">{{ __('تعديل') }}</a>
        @endif
        <a class="btn ghost sm" href="{{ $asset->url(false) }}" target="_blank" rel="noopener">{{ __('فتح بالحجم الكامل') }}</a>
        @if($u->hasPermission('studio.manage') && $quotations->isEmpty())
            <form method="post" action="{{ route('studio.destroy', $asset) }}" class="inline" data-confirm="{{ __('حذف الصورة نهائيًا؟') }}">@csrf @method('delete')
                <button class="btn ghost sm">{{ __('حذف') }}</button>
            </form>
        @endif
    </div>
    <span class="badge st-{{ $asset->category }}">{{ __("rroka.studio_category.$asset->category") }}</span>
</div>
<div class="studio-show">
    <div class="card studio-image"><img src="{{ $asset->url(false) }}" alt="{{ $asset->title }}"></div>
    <div class="card">
        <h1 class="rec-title">{{ $asset->title }}</h1>
        <p class="rec-sub">{{ $asset->asset_no }}</p>
        <dl class="kv">
            <dt>{{ __('العميل') }}</dt><dd>@if($asset->client)<a href="{{ route('clients.show', $asset->client) }}">{{ $asset->client->business_name }}</a>@else — @endif</dd>
            <dt>{{ __('المشروع') }}</dt><dd>@if($asset->project)<a href="{{ route('projects.show', $asset->project) }}">{{ $asset->project->project_no }} — {{ $asset->project->title }}</a>@else — @endif</dd>
            <dt>{{ __('الوسوم') }}</dt><dd>{{ $asset->tags ?: '—' }}</dd>
            <dt>{{ __('الأبعاد') }}</dt><dd><bdi dir="ltr">{{ $asset->width && $asset->height ? $asset->width.' × '.$asset->height.' px' : '—' }} · {{ number_format($asset->size_bytes / 1048576, 2) }} MB</bdi></dd>
            <dt>{{ __('رُفعت') }}</dt><dd>{{ $asset->created_at->format('Y-m-d H:i') }}{{ $uploader ? ' — '.$uploader : '' }}</dd>
            @if($asset->notes)<dt>{{ __('ملاحظات') }}</dt><dd>{{ $asset->notes }}</dd>@endif
        </dl>
        <h2 style="margin-top:18px">{{ __('مستخدمة في عروض الأسعار') }}</h2>
        @forelse($quotations as $q)
            <a class="btn ghost sm" href="{{ route('quotations.show', $q) }}">{{ $q->quotation_no }} · {{ __("rroka.status.$q->status") }}</a>
        @empty
            <p class="muted">{{ __('لم تُستخدم بعد.') }}</p>
        @endforelse
    </div>
</div>
@include('partials.chatter', ['activity' => $activity])
@endsection
