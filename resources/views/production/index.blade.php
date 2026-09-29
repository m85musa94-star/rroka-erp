@extends('layouts.app')
@section('title', __('أوامر التصنيع'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('أوامر التصنيع'), null]],
        'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('production.manage') ? route('production.create') : null,
        'paginator' => $orders,
        'total' => $groups?->flatten()->count() ?? $columns?->flatten()->count(),
        'placeholder' => __('بحث برقم الأمر أو المشروع…'),
    ])
@endsection
@section('content')
@if($columns)
    <div class="kanban">
        @foreach($columns as $st => $items)
            <div class="kb-col">
                <div class="kb-head"><span>@include('partials.badge', ['s' => $st]) <span class="muted" style="font-weight:400">{{ $items->count() }}</span></span></div>
                @forelse($items as $o)
                    @php($late = $o->planned_end && $o->planned_end->lt(today()) && in_array($o->status, ['PLANNED', 'IN_PROGRESS'], true))
                    <a class="kb-card" href="{{ route('production.show', $o) }}">
                        <div class="kb-title">{{ $o->designVersion->design->title }} <span class="muted">v{{ $o->designVersion->version_no }}</span></div>
                        <div class="kb-meta"><span>{{ $o->order_no }}</span><span>{{ $o->project->project_no }}</span></div>
                        <div class="kb-meta"><span></span><span @class(['na' => $late])>{{ $o->planned_end ? __('حتى ').$o->planned_end->format('Y-m-d') : '' }}</span></div>
                    </a>
                @empty
                    <div class="kb-empty">{{ __('لا أوامر') }}</div>
                @endforelse
            </div>
        @endforeach
    </div>
@else
    @php($rows = $orders ?? $groups->flatten())
    @if($rows->isEmpty())
        <div class="card empty-state">
            <strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا توجد أوامر تصنيع بعد') }}</strong>
            {{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('أمر التصنيع يُنشأ على نسخة تصميم مُصدرة للإنتاج.') }}
        </div>
    @else
    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>{{ __('الرقم') }}</th><th>{{ __('التصميم') }}</th><th>{{ __('المشروع') }}</th><th>{{ __('البدء المخطط') }}</th><th>{{ __('الانتهاء المخطط') }}</th><th>{{ __('الحالة') }}</th></tr>
            @foreach($groups ?? ['' => $orders] as $title => $items)
                @if($groups)<tr class="grp"><td colspan="6">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td></tr>@endif
                @foreach($items as $o)
                    @php($late = $o->planned_end && $o->planned_end->lt(today()) && in_array($o->status, ['PLANNED', 'IN_PROGRESS'], true))
                    <tr class="row-link" onclick="location='{{ route('production.show', $o) }}'">
                        <td><a href="{{ route('production.show', $o) }}">{{ $o->order_no }}</a></td>
                        <td>{{ $o->designVersion->design->title }} <span class="muted">v{{ $o->designVersion->version_no }}</span></td>
                        <td>{{ $o->project->project_no }} — {{ $o->project->title }}</td>
                        <td class="num">{{ $o->planned_start?->format('Y-m-d') ?? '—' }}</td>
                        <td class="num"><span @class(['na' => $late])>{{ $o->planned_end?->format('Y-m-d') ?? '—' }}</span></td>
                        <td>@include('partials.badge', ['s' => $o->status])</td>
                    </tr>
                @endforeach
            @endforeach
        </table></div>
    </div>
    @endif
@endif
@endsection
