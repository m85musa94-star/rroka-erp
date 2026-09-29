@extends('layouts.app')
@section('title', __('العقود'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('الموظفون'), route('employees.index')], [__('العقود'), null]],
        'lv' => $lv, 'newUrl' => route('contracts.create'),
        'paginator' => $contracts, 'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث برقم العقد أو اسم الموظف…'),
    ])
@endsection
@section('content')
@php($rows = $contracts ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا توجد عقود بعد') }}</strong>{{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('أدخل عقود الموظفين من واقع العقود الموقّعة.') }}</div>
@else
    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>{{ __('الرقم') }}</th><th>{{ __('الموظف') }}</th><th>{{ __('نوع العقد') }}</th><th>{{ __('البداية') }}</th><th>{{ __('النهاية') }}</th><th class="num">{{ __('الإجمالي الشهري') }}</th><th>{{ __('الحالة') }}</th></tr>
            @foreach($groups ?? ['' => $contracts] as $title => $items)
                @if($groups)<tr class="grp"><td colspan="7">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td></tr>@endif
                @foreach($items as $c)
                    <tr class="row-link" onclick="location='{{ route('contracts.show', $c) }}'">
                        <td><a href="{{ route('contracts.show', $c) }}">{{ $c->contract_no }}</a></td>
                        <td>{{ $c->employee->name }}</td>
                        <td>{{ __("rroka.contract_type.$c->contract_type") }}</td>
                        <td class="num">{{ $c->start_date->format('Y-m-d') }}</td>
                        <td class="num"><span @class(['na' => $c->endingSoon()])>{{ $c->end_date?->format('Y-m-d') ?? '—' }}</span></td>
                        <td class="num">{{ number_format($c->monthlyGross(), 2) }}</td>
                        <td>@include('partials.badge', ['s' => $c->status])</td>
                    </tr>
                @endforeach
            @endforeach
        </table></div>
    </div>
@endif
@endsection
