@extends('layouts.app')
@section('title', __('الإجازات'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('الإجازات'), null]],
        'lv' => $lv, 'newUrl' => route('leaves.create'), 'newLabel' => __('طلب إجازة'),
        'paginator' => $requests, 'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث باسم الموظف…'),
    ])
@endsection
@section('content')
@if($me && $balances->isNotEmpty())
    <div class="stats" style="margin:0 0 14px">
        @foreach($balances as $b)<div class="stat"><div class="k">{{ __('رصيدي: :type', ['type' => $b->type->name]) }}</div><div class="v">{{ $b->balance + 0 }}</div></div>@endforeach
    </div>
@endif
@php($rows = $requests ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا توجد طلبات إجازة') }}</strong>{{ __('قدّم طلبًا من زر «طلب إجازة».') }}</div>
@else
<div class="card" style="padding:0">
    <div class="table-wrap"><table>
        <tr><th>{{ __('الموظف') }}</th><th>{{ __('النوع') }}</th><th>{{ __('من') }}</th><th>{{ __('إلى') }}</th><th class="num">{{ __('الأيام') }}</th><th>{{ __('الحالة') }}</th><th></th></tr>
        @foreach($groups ?? ['' => $requests] as $title => $items)
            @if($groups)<tr class="grp"><td colspan="4">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td><td class="num">{{ $items->where('status', 'APPROVED')->sum('days') + 0 }}</td><td colspan="2"></td></tr>@endif
            @foreach($items as $r)
                <tr>
                    <td>{{ $r->employee->name }}</td><td>{{ $r->type->name }}</td>
                    <td class="num">{{ $r->date_from->format('Y-m-d') }}</td><td class="num">{{ $r->date_to->format('Y-m-d') }}</td>
                    <td class="num">{{ $r->days + 0 }}</td>
                    <td>@include('partials.badge', ['s' => $r->status]) @if($r->refusal_reason)<span class="muted">{{ $r->refusal_reason }}</span>@endif</td>
                    <td>
                        @if($approver && $r->status === 'SUBMITTED')
                            <form method="post" action="{{ route('leaves.decide', [$r, 'approve']) }}" class="inline">@csrf<button class="btn ok sm">{{ __('اعتماد') }}</button></form>
                            <details class="inline-details"><summary class="btn ghost sm">{{ __('رفض') }}</summary>
                                <form method="post" action="{{ route('leaves.decide', [$r, 'refuse']) }}" class="inline-form" style="margin-top:6px">@csrf
                                    <input name="refusal_reason" required placeholder="{{ __('سبب الرفض') }}"><button class="btn bad sm">{{ __('رفض') }}</button></form></details>
                        @endif
                        @if(($r->status === 'SUBMITTED' && $me && $r->employee_id === $me->id) || ($approver && $r->status === 'APPROVED'))
                            <form method="post" action="{{ route('leaves.decide', [$r, 'cancel']) }}" class="inline" data-confirm="{{ __('إلغاء طلب الإجازة؟') }}">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
        @endforeach
    </table></div>
</div>
@endif
@endsection
