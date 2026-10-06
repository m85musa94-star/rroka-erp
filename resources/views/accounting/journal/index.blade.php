@extends('layouts.app')
@section('title', __('القيود'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('القيود'), null]], 'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('accounting.manage') ? route('accounting.journal.create') : null,
        'paginator' => $entries, 'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث برقم القيد أو البيان أو المرجع…'),
    ])
@endsection
@section('content')
@php($rows = $entries ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا توجد قيود بعد') }}</strong>{{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('ابدأ بدليل الحسابات، ثم القيد الافتتاحي إن وُجدت أرصدة في تاريخ بداية الدفاتر.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الرقم') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('البيان') }}</th><th>{{ __('المرجع') }}</th><th>{{ __('النوع') }}</th><th class="num">{{ __('المبلغ') }}</th><th>{{ __('الحالة') }}</th></tr>
    @foreach($groups ?? ['' => $entries] as $title => $items)
        @if($groups)<tr class="grp"><td colspan="5">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td><td class="num">{{ number_format($items->where('status', 'POSTED')->sum('debit'), 2) }}</td><td></td></tr>@endif
        @foreach($items as $e)
            @php($unbalanced = round((float) $e->debit, 2) !== round((float) $e->credit, 2))
            <tr class="row-link" onclick="location='{{ route('accounting.journal.show', $e) }}'">
                <td><a href="{{ route('accounting.journal.show', $e) }}" dir="ltr">{{ $e->entry_no ?? '#'.$e->id }}</a></td>
                <td class="num">{{ $e->entry_date->format('Y-m-d') }}</td><td>{{ $e->description }}</td><td>{{ $e->reference }}</td>
                <td>{{ __("rroka.journal_source.$e->source_type") }}</td>
                <td class="num">{{ number_format((float) $e->debit, 2) }}</td>
                <td>@include('partials.badge', ['s' => $e->status]) @if($e->selfPosted())<span class="badge b-ON_HOLD">{{ __('ترحيل ذاتي') }}</span>@endif
                    @if($e->status === 'DRAFT' && $unbalanced)<span class="badge b-REJECTED">{{ __('غير متوازن') }}</span>@endif</td>
            </tr>
        @endforeach
    @endforeach
</table></div></div>
<p class="hint">{{ __('مجاميع التجميع تشمل المرحَّلة فقط.') }}</p>
@endif
@endsection
