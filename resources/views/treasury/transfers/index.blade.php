@extends('layouts.app')
@section('title', __('التحويلات وصرف العهد'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('التحويلات وصرف العهد'), null]], 'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('treasury.manage') ? route('treasury.transfers.create') : null,
        'paginator' => $transfers, 'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث بالرقم أو المرجع أو الملاحظات…'),
    ])
@endsection
@section('content')
@php($rows = $transfers ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا توجد تحويلات بعد') }}</strong>{{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('صرف العهدة للموظف، وإرجاع المتبقي منها، وإيداع النقدية في البنك — كلها تحويلات.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الرقم') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('النوع') }}</th><th>{{ __('من') }}</th><th>{{ __('إلى') }}</th><th class="num">{{ __('المبلغ') }}</th><th>{{ __('الحالة') }}</th></tr>
    @foreach($groups ?? ['' => $transfers] as $title => $items)
        @if($groups)<tr class="grp"><td colspan="5">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td><td class="num">{{ number_format($items->where('status', 'APPROVED')->sum('amount'), 2) }}</td><td></td></tr>@endif
        @foreach($items as $t)
            <tr class="row-link" onclick="location='{{ route('treasury.transfers.show', $t) }}'">
                <td><a href="{{ route('treasury.transfers.show', $t) }}">{{ $t->transfer_no }}</a></td><td class="num">{{ $t->transfer_date->format('Y-m-d') }}</td>
                <td>{{ __('rroka.transfer_purpose.'.$t->purpose()) }}</td><td>{{ $t->from->name }}</td><td>{{ $t->to->name }}</td>
                <td class="num">{{ number_format($t->amount, 2) }}</td>
                <td>@include('partials.badge', ['s' => $t->status]) @if($t->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</td>
            </tr>
        @endforeach
    @endforeach
</table></div></div>
@if($groups)<p class="hint">{{ __('مجاميع التجميع تشمل المعتمدة فقط.') }}</p>@endif
@endif
@endsection
