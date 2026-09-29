@extends('layouts.app')
@section('title', __('سجلات الحضور'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('الحضور'), route('attendance.index')], [__('السجلات'), null]],
        'lv' => $lv, 'paginator' => $rows, 'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث باسم الموظف…'),
    ])
@endsection
@section('content')
@php($tz = config('app.timezone'))
@php($list = $rows ?? $groups->flatten())
<div class="card">
    <h2>{{ __('تسجيل يدوي أو تصحيح') }}</h2>
    <form method="post" action="{{ route('attendance.store') }}" class="inline-form">@csrf
        <select name="employee_id" required><option value="">{{ __('— الموظف —') }}</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select>
        <input type="datetime-local" name="check_in" required title="{{ __('الحضور') }}">
        <input type="datetime-local" name="check_out" title="{{ __('الانصراف') }}">
        <input name="notes" placeholder="{{ __('السبب (نسي البصمة، مهمة خارجية…)') }}">
        <button class="btn sm">{{ __('تسجيل') }}</button>
    </form>
</div>
@if($list->isEmpty())
    <div class="card empty-state"><strong>{{ __('لا توجد سجلات') }}</strong>{{ __('غيّر البحث أو أزل الفلاتر.') }}</div>
@else
<div class="card" style="padding:0">
    <div class="table-wrap"><table>
        <tr><th>{{ __('الموظف') }}</th><th>{{ __('الحضور') }}</th><th>{{ __('الانصراف') }}</th><th class="num">{{ __('الساعات') }}</th><th>{{ __('ملاحظات') }}</th><th></th></tr>
        @foreach($groups ?? ['' => $rows] as $title => $items)
            @if($groups)<tr class="grp"><td colspan="3">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td><td class="num">{{ number_format($items->sum('worked_hours'), 2) }}</td><td colspan="2"></td></tr>@endif
            @foreach($items as $a)
                <tr>
                    <td>{{ $a->employee->name }}</td>
                    <td class="num">{{ $a->check_in->timezone($tz)->format('Y-m-d H:i') }}</td>
                    <td class="num">{!! $a->check_out ? e($a->check_out->timezone($tz)->format('Y-m-d H:i')) : '<span class="badge b-ACTIVE">'.e(__('حاضر')).'</span>' !!}</td>
                    <td class="num">{{ $a->worked_hours !== null ? number_format($a->worked_hours, 2) : '—' }}</td>
                    <td>{{ $a->notes ?? '' }}</td>
                    <td><details class="inline-details"><summary class="btn ghost sm">{{ __('تصحيح') }}</summary>
                        <form method="post" action="{{ route('attendance.update', $a) }}" class="inline-form" style="margin-top:6px">@csrf @method('put')
                            <input type="hidden" name="employee_id" value="{{ $a->employee_id }}">
                            <input type="datetime-local" name="check_in" value="{{ $a->check_in->timezone($tz)->format('Y-m-d\TH:i') }}" required>
                            <input type="datetime-local" name="check_out" value="{{ $a->check_out?->timezone($tz)->format('Y-m-d\TH:i') }}">
                            <input name="notes" value="{{ $a->notes }}" placeholder="{{ __('سبب التصحيح') }}" required>
                            <button class="btn sm">{{ __('حفظ') }}</button>
                        </form></details></td>
                </tr>
            @endforeach
        @endforeach
    </table></div>
</div>
<p class="hint">{{ __('كل تسجيل وتصحيح محفوظ في سجل التدقيق مع اسم من قام به.') }}</p>
@endif
@endsection
