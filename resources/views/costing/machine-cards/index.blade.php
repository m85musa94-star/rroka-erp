@extends('layouts.app')
@section('title', __('تكلفة الآلات'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('محرك التكلفة'), route('costing.index')], [__('تكلفة الآلات'), null]], 'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('settings.cost_rates') ? route('costing.machine-cards.create') : null,
        'paginator' => $cards, 'total' => $groups?->flatten()->count(), 'placeholder' => __('بحث برمز الآلة أو اسمها…'),
    ])
@endsection
@section('content')
@php($canEdit = auth()->user()->hasPermission('settings.cost_rates'))
@php($rows = $cards ?? $groups->flatten())
<div class="card" style="padding:0">
    <h2 style="padding:16px 16px 0">{{ __('الآلات ومراكز تكلفتها') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الرمز') }}</th><th>{{ __('الآلة') }}</th><th>{{ __('مركز التكلفة') }}</th><th></th></tr>
        @forelse($machines as $m)
            <tr><td class="num">{{ $m->code }}</td><td>{{ $m->name }}</td>
                <td>@if($canEdit)<select form="mc{{ $m->id }}" name="cost_center_id"><option value="">—</option>@foreach($centers as $c)<option value="{{ $c->id }}" @selected($m->cost_center_id === $c->id)>{{ $c->name }}</option>@endforeach</select>@else{{ $m->costCenter?->name ?? '—' }}@endif</td>
                <td>@if($canEdit)<form id="mc{{ $m->id }}" method="post" action="{{ route('costing.machines.update', $m) }}" class="inline">@csrf @method('put')<button class="btn ghost sm">{{ __('حفظ') }}</button></form>
                    <a class="btn ghost sm" href="{{ route('costing.machine-cards.create', ['machine_id' => $m->id]) }}">{{ __('بطاقة تكلفة') }}</a>@endif</td></tr>
        @empty
            <tr><td colspan="4" class="muted">{{ __('لم تُضف آلات بعد.') }}</td></tr>
        @endforelse
    </table></div>
    @if($canEdit)
    <form method="post" action="{{ route('costing.machines.store') }}" class="inline-form" style="display:flex;gap:8px;align-items:end;padding:0 16px 16px;flex-wrap:wrap">@csrf
        <div class="field"><label>{{ __('الرمز *') }}</label><input name="code" required dir="ltr" style="width:120px"></div>
        <div class="field"><label>{{ __('اسم الآلة *') }}</label><input name="name" required></div>
        <div class="field"><label>{{ __('مركز التكلفة') }}</label><select name="cost_center_id"><option value="">—</option>@foreach($centers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
        <button class="btn sm">{{ __('إضافة آلة') }}</button>
    </form>
    @endif
</div>
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا توجد بطاقات تكلفة آلات بعد') }}</strong>{{ __('بطاقة الآلة تحسب تكلفة ساعتها من الإهلاك والكهرباء والصيانة وقطع الغيار.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الآلة') }}</th><th class="num">{{ __('الإصدار') }}</th><th>{{ __('ساري من') }}</th><th class="num">{{ __('إهلاك') }}</th><th class="num">{{ __('كهرباء') }}</th><th class="num">{{ __('صيانة وقطع غيار وأخرى') }}</th><th class="num">{{ __('تكلفة الساعة') }}</th><th>{{ __('الحالة') }}</th></tr>
    @foreach($groups ?? ['' => $cards] as $title => $items)
        @if($groups)<tr class="grp"><td colspan="8">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td></tr>@endif
        @foreach($items as $c)
            <tr class="row-link" onclick="location='{{ route('costing.machine-cards.show', $c) }}'">
                <td><a href="{{ route('costing.machine-cards.show', $c) }}">{{ $c->machine->code }} — {{ $c->machine->name }}</a></td><td class="num">{{ $c->version }}</td>
                <td class="num">{{ $c->effective_from->format('Y-m-d') }}</td><td class="num">{{ number_format($c->depreciation_per_hour, 2) }}</td>
                <td class="num">{{ $c->electricity_per_hour !== null ? number_format($c->electricity_per_hour, 2) : '—' }}</td>
                <td class="num">{{ number_format($c->maintenance_per_hour + $c->spare_parts_per_hour + $c->other_per_hour, 2) }}</td>
                <td class="num"><strong>{{ $c->hourly_rate !== null ? number_format($c->hourly_rate, 2) : '—' }}</strong></td>
                <td>@include('partials.badge', ['s' => $c->status]) @if($c->estimated)<span class="badge b-ON_HOLD">{{ __('تقديري') }}</span>@endif</td>
            </tr>
        @endforeach
    @endforeach
</table></div></div>
@endif
@endsection
