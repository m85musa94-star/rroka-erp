@extends('layouts.app')
@section('title', __('تكلفة الموظفين'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('محرك التكلفة'), route('costing.index')], [__('تكلفة الموظفين'), null]], 'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('settings.cost_rates') ? route('costing.employee-cards.create') : null,
        'paginator' => $cards, 'total' => $groups?->flatten()->count(), 'placeholder' => __('بحث باسم الموظف…'),
    ])
@endsection
@section('content')
@php($rows = $cards ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا توجد بطاقات تكلفة بعد') }}</strong>{{ __('لكل عامل إنتاج بطاقة: تكلفته الشهرية وساعاته الإنتاجية العملية، فينتج أجر ساعته.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الموظف') }}</th><th class="num">{{ __('الإصدار') }}</th><th>{{ __('ساري من') }}</th><th class="num">{{ __('التكلفة الشهرية') }}</th><th class="num">{{ __('الساعات العملية') }}</th><th class="num">{{ __('تكلفة الساعة') }}</th><th>{{ __('الحالة') }}</th></tr>
    @foreach($groups ?? ['' => $cards] as $title => $items)
        @if($groups)<tr class="grp"><td colspan="7">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td></tr>@endif
        @foreach($items as $c)
            <tr class="row-link" onclick="location='{{ route('costing.employee-cards.show', $c) }}'">
                <td><a href="{{ route('costing.employee-cards.show', $c) }}">{{ $c->employee->name }}</a></td><td class="num">{{ $c->version }}</td>
                <td class="num">{{ $c->effective_from->format('Y-m-d') }}</td><td class="num">{{ number_format($c->monthly_cost, 2) }}</td>
                <td class="num">{{ number_format($c->practical_hours, 2) }}</td><td class="num"><strong>{{ number_format($c->hourly_rate, 2) }}</strong></td>
                <td>@include('partials.badge', ['s' => $c->status]) @if($c->estimated)<span class="badge b-ON_HOLD">{{ __('تقديري') }}</span>@endif @if($c->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</td>
            </tr>
        @endforeach
    @endforeach
</table></div></div>
@endif
@endsection
