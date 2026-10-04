@extends('layouts.app')
@section('title', __('أسعار المواد المعيارية'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('محرك التكلفة'), route('costing.index')], [__('أسعار المواد المعيارية'), null]]])
@endsection
@section('content')
@php($u = auth()->user())
<p class="hint" style="margin-top:0">{{ __('السعر المعياري هو ما تُحسب به تكلفة المواد قبل التصنيع. آخر سعر شراء ومتوسط تكلفة المخزون معروضان كدليل فقط؛ لا يُعتمد سعر إلا بإدخالك واعتماده.') }}</p>
<form method="get" class="card" style="display:flex;gap:8px;align-items:end">
    <div class="field" style="flex:1"><label>{{ __('بحث') }}</label><input name="q" value="{{ $q }}" placeholder="{{ __('الرمز أو الاسم') }}"></div><button class="btn sm">{{ __('بحث') }}</button>
</form>
@if($drafts->isNotEmpty())
<div class="card">
    <h2>{{ __('بانتظار الاعتماد') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الخامة') }}</th><th>{{ __('ساري من') }}</th><th class="num">{{ __('السعر') }}</th><th>{{ __('الأساس') }}</th><th>{{ __('المصدر') }}</th><th></th></tr>
        @foreach($drafts as $d)
            <tr><td>{{ $d->material->code }} — {{ $d->material->name }}</td><td class="num">{{ $d->effective_from->format('Y-m-d') }}</td><td class="num">{{ number_format($d->unit_price, 4) }}</td>
                <td>{{ __("rroka.price_basis.$d->price_basis") }}</td><td>{{ $d->source }} @if($d->estimated)<span class="badge b-ON_HOLD">{{ __('تقديري') }}</span>@endif</td>
                <td>@if($u->hasPermission('cost_rates.approve'))<form method="post" action="{{ route('costing.approve', ['prices', $d->id]) }}" class="inline">@csrf<button class="btn ok sm">{{ __('اعتماد') }}</button></form>@endif
                    @if($u->hasPermission('settings.cost_rates'))<form method="post" action="{{ route('costing.cancel', ['prices', $d->id]) }}" class="inline">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>@endif</td></tr>
        @endforeach
    </table></div>
</div>
@endif
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الخامة') }}</th><th>{{ __('الفئة') }}</th><th class="num">{{ __('السعر المعياري الساري') }}</th><th class="num">{{ __('الهالك المعياري %') }}</th><th class="num">{{ __('آخر سعر شراء') }}</th><th class="num">{{ __('متوسط تكلفة المخزون') }}</th>@if($u->hasPermission('settings.cost_rates'))<th>{{ __('سعر معياري جديد') }}</th>@endif</tr>
    @forelse($materials as $row)
        <tr>
            <td>{{ $row->m->code }} — {{ $row->m->name }} <span class="muted">/ {{ $row->m->uom }}</span></td>
            <td>{{ $row->m->category ?? '—' }}</td>
            <td class="num">{!! $row->standard !== null ? number_format($row->standard, 4) : '<span class="na">'.e(__('غير مُدخل')).'</span>' !!}</td>
            <td class="num">{!! $row->waste !== null ? number_format($row->waste, 2) : '<span class="na">'.e(__('غير مُدخل')).'</span>' !!}</td>
            <td class="num">@if($row->last){{ number_format($row->last->unit_price, 4) }}<div class="hint">{{ $row->last->purchase_no }} · {{ $row->last->invoice_date }}</div>@else—@endif</td>
            <td class="num">{{ $row->avg !== null ? number_format($row->avg, 4) : '—' }}</td>
            @if($u->hasPermission('settings.cost_rates'))
            <td><form method="post" action="{{ route('costing.prices.store') }}" style="display:grid;grid-template-columns:100px 130px 140px 1fr auto;gap:6px">@csrf
                <input type="hidden" name="material_id" value="{{ $row->m->id }}">
                <input name="unit_price" type="number" step="0.0001" min="0.0001" required dir="ltr" placeholder="{{ __('السعر') }}" value="{{ $row->last?->unit_price }}">
                <input type="date" name="effective_from" value="{{ today()->format('Y-m-d') }}" required>
                <select name="price_basis" required>@foreach(\App\Models\MaterialStandardPrice::BASES as $b)<option value="{{ $b }}" @selected($b === ($row->last ? 'LAST_PURCHASE' : 'MANUAL'))>{{ __("rroka.price_basis.$b") }}</option>@endforeach</select>
                <input name="source" required placeholder="{{ __('المصدر') }}" value="{{ $row->last ? __('فاتورة المورد :no', ['no' => $row->last->purchase_no]) : '' }}">
                <button class="btn sm">{{ __('حفظ') }}</button>
            </form></td>
            @endif
        </tr>
    @empty
        <tr><td colspan="7" class="muted">{{ __('لا خامات.') }}</td></tr>
    @endforelse
</table></div></div>
@if($history->isNotEmpty())
<div class="card">
    <h2>{{ __('السجل') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الخامة') }}</th><th class="num">{{ __('الإصدار') }}</th><th>{{ __('ساري من') }}</th><th class="num">{{ __('السعر') }}</th><th>{{ __('الأساس') }}</th><th>{{ __('الحالة') }}</th></tr>
        @foreach($history as $h)
            <tr><td>{{ $h->material->code }}</td><td class="num">{{ $h->version }}</td><td class="num">{{ $h->effective_from->format('Y-m-d') }}</td><td class="num">{{ number_format($h->unit_price, 4) }}</td>
                <td>{{ __("rroka.price_basis.$h->price_basis") }}</td><td>@include('partials.badge', ['s' => $h->status]) @if($h->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</td></tr>
        @endforeach
    </table></div>
</div>
@endif
@endsection
