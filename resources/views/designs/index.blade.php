@extends('layouts.app')
@section('title', __('التصاميم'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('التصاميم'), null]],
        'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('designs.manage') ? route('designs.create', array_filter(request()->only('project_id'))) : null,
        'paginator' => $designs,
        'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث بعنوان التصميم أو المشروع…'),
    ])
@endsection
@section('content')
@php($rows = $designs ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state">
        <strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا توجد تصاميم بعد') }}</strong>
        {{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('التصميم يتبع مشروعًا؛ أنشئه من زر «جديد» أو من صفحة المشروع.') }}
    </div>
@else
    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>{{ __('التصميم') }}</th><th>{{ __('المشروع') }}</th><th>{{ __('العميل') }}</th><th class="num">{{ __('آخر نسخة') }}</th><th>{{ __('حالتها') }}</th><th>{{ __('المُصدَر للإنتاج') }}</th></tr>
            @foreach($groups ?? ['' => $designs] as $title => $items)
                @if($groups)<tr class="grp"><td colspan="6">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td></tr>@endif
                @foreach($items as $d)
                    @php($last = $d->versions->first())
                    @php($rel = $d->versions->firstWhere('status', 'RELEASED_FOR_PRODUCTION'))
                    <tr class="row-link" onclick="location='{{ route('design-versions.show', $last) }}'">
                        <td><a href="{{ route('design-versions.show', $last) }}">{{ $d->title }}</a></td>
                        <td>{{ $d->project->project_no }}</td>
                        <td>{{ $d->project->client->business_name }}</td>
                        <td class="num">v{{ $last->version_no }}</td>
                        <td>@include('partials.badge', ['s' => $last->status])</td>
                        <td>{{ $rel ? 'v'.$rel->version_no : '—' }}</td>
                    </tr>
                @endforeach
            @endforeach
        </table></div>
    </div>
@endif
@endsection
