@extends('layouts.app')
@section('title', __('سعر الكهرباء'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('محرك التكلفة'), route('costing.index')], [__('سعر الكهرباء'), null]]])
@endsection
@section('content')
@php($u = auth()->user())
<p class="hint" style="margin-top:0">{{ __('سعر الكيلوواط ساعة من فاتورة الكهرباء. يُستخدم في تكلفة ساعة كل آلة (القدرة × معامل التحميل × السعر)، ثم تُطرح كهرباء الآلات تلقائيًا من فاتورة المصنع العامة حتى لا تُحسب مرتين.') }}</p>
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الإصدار') }}</th><th>{{ __('ساري من') }}</th><th class="num">{{ __('ريال/كيلوواط ساعة') }}</th><th>{{ __('المصدر') }}</th><th>{{ __('الحالة') }}</th><th></th></tr>
    @forelse($rates as $r)
        <tr>
            <td class="num">{{ $r->version }}</td><td class="num">{{ $r->effective_from->format('Y-m-d') }}</td><td class="num">{{ number_format($r->rate_per_kwh, 4) }}</td>
            <td>{{ $r->source }} @if($r->estimated)<span class="badge b-ON_HOLD">{{ __('تقديري') }}</span>@endif</td>
            <td>@include('partials.badge', ['s' => $r->status]) @if($r->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</td>
            <td>@if($r->isDraft())
                @if($u->hasPermission('cost_rates.approve'))<form method="post" action="{{ route('costing.approve', ['energy', $r->id]) }}" class="inline" data-confirm="{{ __('اعتماد السجل؟ يصبح نهائيًا ولا يُعدَّل، وأي تغيير لاحق يكون بإصدار جديد.') }}">@csrf<button class="btn ok sm">{{ __('اعتماد') }}</button></form>@endif
                @if($u->hasPermission('settings.cost_rates'))<form method="post" action="{{ route('costing.cancel', ['energy', $r->id]) }}" class="inline">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>@endif
            @endif</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">{{ __('لم يُدخل سعر الكهرباء بعد.') }}</td></tr>
    @endforelse
</table></div></div>
@if($u->hasPermission('settings.cost_rates'))
<form method="post" action="{{ route('costing.energy.store') }}" class="card">@csrf
    <h2>{{ __('سعر جديد') }}</h2>
    @php($r = new \App\Models\EnergyRate(['effective_from' => today()]))
    <div class="field" style="max-width:260px"><label>{{ __('ريال لكل كيلوواط ساعة *') }}</label><input name="rate_per_kwh" type="number" step="0.0001" min="0.0001" required dir="ltr" value="{{ old('rate_per_kwh') }}"></div>
    @include('costing._fields', ['r' => $r])
    <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button></div>
</form>
@endif
@endsection
