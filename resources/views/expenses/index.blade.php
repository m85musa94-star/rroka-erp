@extends('layouts.app')
@section('title', __('المصروفات'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('المصروفات'), null]], 'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('expenses.manage') ? route('expenses.create', array_filter(request()->only('project_id'))) : null,
        'paginator' => $expenses, 'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث بالرقم أو الوصف أو الجهة أو المرجع…'),
    ])
@endsection
@section('content')
@php($rows = $expenses ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا توجد مصروفات بعد') }}</strong>{{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('سجّل كل مصروف مرة واحدة هنا مع صورة إيصاله.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الرقم') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('التصنيف') }}</th><th>{{ __('الوصف') }}</th><th>{{ __('المشروع') }}</th><th>{{ __('دُفع من') }}</th><th class="num">{{ __('المبلغ قبل الضريبة') }}</th><th>{{ __('الحالة') }}</th></tr>
    @foreach($groups ?? ['' => $expenses] as $title => $items)
        @if($groups)<tr class="grp"><td colspan="6">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td><td class="num">{{ number_format($items->where('status', 'APPROVED')->sum('amount'), 2) }}</td><td></td></tr>@endif
        @foreach($items as $x)
            <tr class="row-link" onclick="location='{{ route('expenses.show', $x) }}'">
                <td><a href="{{ route('expenses.show', $x) }}">{{ $x->expense_no }}</a></td><td class="num">{{ $x->expense_date->format('Y-m-d') }}</td>
                <td>{{ $x->category->name }}</td><td>{{ $x->description }}</td><td>{{ $x->project?->project_no ?? '—' }}</td><td>{{ $x->paymentAccount?->name ?? '—' }}</td>
                <td class="num">{{ number_format($x->amount, 2) }}</td>
                <td>@include('partials.badge', ['s' => $x->status]) @if($x->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</td>
            </tr>
        @endforeach
    @endforeach
</table></div></div>
<p class="hint">{{ __('مجاميع التجميع تشمل المعتمدة فقط.') }}</p>
@endif
@endsection
