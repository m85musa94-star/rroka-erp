@extends('layouts.app')
@section('title', __('الخامات والمخزون'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('الخامات والمخزون'), null]],
        'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('inventory.move') ? route('materials.create') : null,
        'paginator' => $materials,
        'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث بالرمز أو الاسم أو الفئة…'),
    ])
@endsection
@section('content')
@php($rows = $materials ?? $groups->flatten())
@php($q = fn ($v) => $v === null ? '0' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.'))
@if($rows->isEmpty())
    <div class="card empty-state">
        <strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا توجد خامات بعد') }}</strong>
        {{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('أضف الخامات والمستلزمات من زر «جديد»، ثم سجّل استلامها بتكلفتها الفعلية.') }}
    </div>
@else
    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>{{ __('الرمز') }}</th><th>{{ __('الخامة') }}</th><th>{{ __('الوحدة') }}</th><th class="num">{{ __('الرصيد') }}</th><th class="num">{{ __('المحجوز') }}</th><th class="num">{{ __('المتاح') }}</th><th class="num">{{ __('متوسط التكلفة') }}</th><th class="num">{{ __('قيمة الرصيد') }}</th></tr>
            @foreach($groups ?? ['' => $materials] as $title => $items)
                @if($groups)<tr class="grp"><td colspan="8">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td></tr>@endif
                @foreach($items as $m)
                    <tr class="row-link" onclick="location='{{ route('materials.show', $m) }}'">
                        <td class="num"><a href="{{ route('materials.show', $m) }}">{{ $m->code }}</a></td>
                        <td>{{ $m->name }} @unless($m->is_active)<span class="badge b-CANCELLED">{{ __('موقوفة') }}</span>@endunless</td>
                        <td>{{ $m->uom }}</td>
                        <td class="num">{{ $q($m->qty_on_hand) }}</td>
                        <td class="num">{{ $q($m->qty_reserved) }}</td>
                        <td class="num">{{ $q((float) $m->qty_on_hand - (float) $m->qty_reserved) }}</td>
                        <td class="num">{{ $m->avg_unit_cost === null ? '—' : number_format($m->avg_unit_cost, 2) }}</td>
                        <td class="num">{{ $m->avg_unit_cost === null ? '—' : number_format($m->qty_on_hand * $m->avg_unit_cost, 2) }}</td>
                    </tr>
                @endforeach
            @endforeach
        </table></div>
    </div>
    <p class="hint">{{ __('الأرصدة تُحسب آليًا من الحركات ولا تُعدَّل مباشرة. متوسط التكلفة مرجّح بتكلفة الاستلام الفعلية.') }}</p>
@endif
@endsection
