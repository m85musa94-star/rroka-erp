@extends('layouts.app')
@section('title', __('الموردون'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('الموردون'), null]], 'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('purchases.manage') ? route('suppliers.create') : null,
        'paginator' => $suppliers, 'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث بالاسم أو الرقم الضريبي أو الهاتف…'),
    ])
@endsection
@section('content')
@php($rows = $suppliers ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا يوجد موردون بعد') }}</strong>{{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('أضف الموردين من زر «جديد».') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الرقم') }}</th><th>{{ __('المورد') }}</th><th>{{ __('الرقم الضريبي') }}</th><th>{{ __('الجوال') }}</th><th>{{ __('المدينة') }}</th><th>{{ __('دفترة') }}</th></tr>
    @foreach($groups ?? ['' => $suppliers] as $title => $items)
        @if($groups)<tr class="grp"><td colspan="6">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td></tr>@endif
        @foreach($items as $s)
            <tr class="row-link" onclick="location='{{ route('suppliers.show', $s) }}'">
                <td class="num">{{ $s->supplier_no }}</td>
                <td><a href="{{ route('suppliers.show', $s) }}">{{ $s->name }}</a> @unless($s->is_active)<span class="badge b-CANCELLED">{{ __('موقوف') }}</span>@endunless</td>
                <td class="num">{{ $s->vat_number ?? '—' }}</td><td class="num">{{ $s->phone ?? '—' }}</td><td>{{ $s->city ?? '—' }}</td>
                <td>{!! $s->daftra_supplier_id ? '<span class="badge b-SUCCESS">'.e(__('مرتبط')).'</span>' : '<span class="muted">—</span>' !!}</td>
            </tr>
        @endforeach
    @endforeach
</table></div></div>
@endif
@endsection
