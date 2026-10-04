@extends('layouts.app')
@section('title', __('التسعير والضريبة'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('محرك التكلفة'), route('costing.index')], [__('التسعير والضريبة'), null]]])
@endsection
@section('content')
@php($u = auth()->user())
<div class="card">
    <h2>{{ __('سياسة التسعير') }}</h2>
    <p class="hint" style="margin-top:0">{{ __('الطريقة والنسبة المستهدفة تُقترح على كل تقدير جديد ويمكن تعديلها لكل بند. الهامش يُحسب من السعر: السعر = التكلفة ÷ (1 − الهامش). والإضافة (Markup) تُحسب من التكلفة: السعر = التكلفة × (1 + النسبة). المصطلحان مختلفان.') }}</p>
    <div class="table-wrap"><table>
        <tr><th class="num">{{ __('الإصدار') }}</th><th>{{ __('ساري من') }}</th><th>{{ __('الطريقة') }}</th><th class="num">{{ __('النسبة المستهدفة') }}</th><th class="num">{{ __('الحد الأدنى للهامش') }}</th><th>{{ __('المصدر') }}</th><th>{{ __('الحالة') }}</th><th></th></tr>
        @forelse($policies as $p)
            <tr><td class="num">{{ $p->version }}</td><td class="num">{{ $p->effective_from->format('Y-m-d') }}</td><td>{{ __("rroka.pricing_method.$p->pricing_method") }}</td>
                <td class="num">{{ number_format($p->target_pct, 2) }}%</td><td class="num">{{ $p->min_margin_pct !== null ? number_format($p->min_margin_pct, 2).'%' : '—' }}</td>
                <td>{{ $p->source }} @if($p->estimated)<span class="badge b-ON_HOLD">{{ __('تقديري') }}</span>@endif</td>
                <td>@include('partials.badge', ['s' => $p->status]) @if($p->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</td>
                <td>@if($p->isDraft())
                    @if($u->hasPermission('cost_rates.approve'))<form method="post" action="{{ route('costing.approve', ['pricing', $p->id]) }}" class="inline" data-confirm="{{ __('اعتماد السجل؟ يصبح نهائيًا ولا يُعدَّل، وأي تغيير لاحق يكون بإصدار جديد.') }}">@csrf<button class="btn ok sm">{{ __('اعتماد') }}</button></form>@endif
                    @if($u->hasPermission('settings.cost_rates'))<form method="post" action="{{ route('costing.cancel', ['pricing', $p->id]) }}" class="inline">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>@endif
                @endif</td></tr>
        @empty
            <tr><td colspan="8" class="muted">{{ __('لم تُحدد سياسة تسعير بعد؛ تُدخل الطريقة والنسبة في كل تقدير.') }}</td></tr>
        @endforelse
    </table></div>
    @if($u->hasPermission('settings.cost_rates'))
    <form method="post" action="{{ route('costing.pricing.store') }}" style="margin-top:12px">@csrf
        <div class="grid g3">
            <div class="field"><label>{{ __('الطريقة *') }}</label><select name="pricing_method" required>@foreach(\App\Models\PricingPolicy::METHODS as $m)<option value="{{ $m }}">{{ __("rroka.pricing_method.$m") }}</option>@endforeach</select></div>
            <div class="field"><label>{{ __('النسبة المستهدفة % *') }}</label><input name="target_pct" type="number" step="0.01" min="0" required dir="ltr"></div>
            <div class="field"><label>{{ __('الحد الأدنى للهامش %') }}</label><input name="min_margin_pct" type="number" step="0.01" min="0" max="99.99" dir="ltr"><div class="hint">{{ __('تحذير إن نزل هامش السعر بعد التكاليف الكاملة عنه.') }}</div></div>
        </div>
        @include('costing._fields', ['r' => new \App\Models\PricingPolicy(['effective_from' => today()])])
        <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button></div>
    </form>
    @endif
</div>

<div class="card">
    <h2>{{ __('نسب ضريبة القيمة المضافة') }}</h2>
    <p class="hint" style="margin-top:0">{{ __('تُختار النسبة في عرض السعر فتُحسب الضريبة تلقائيًا على الصافي بعد الخصم؛ إن لم تُختر نسبة يكون العرض بلا ضريبة. الضريبة لا تدخل الإيراد ولا الربح، والإقرار الضريبي في دفترة.') }}</p>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الاسم') }}</th><th class="num">{{ __('النسبة') }}</th><th>{{ __('الحالة') }}</th><th></th></tr>
        @forelse($vatRates as $v)
            <tr><td>{{ $v->name }}</td><td class="num">{{ number_format($v->rate_pct, 2) }}%</td>
                <td>{!! $v->is_active ? '<span class="badge b-ACTIVE">'.e(__('نشط')).'</span>' : '<span class="badge b-CANCELLED">'.e(__('موقوف')).'</span>' !!}</td>
                <td>@if($u->hasPermission('settings.cost_rates'))<form method="post" action="{{ route('costing.vat.toggle', $v) }}" class="inline">@csrf<button class="btn ghost sm">{{ $v->is_active ? __('إيقاف') : __('تفعيل') }}</button></form>@endif</td></tr>
        @empty
            <tr><td colspan="4" class="muted">{{ __('لم تُضف نسب ضريبة بعد.') }}</td></tr>
        @endforelse
    </table></div>
    @if($u->hasPermission('settings.cost_rates'))
    <form method="post" action="{{ route('costing.vat.store') }}" class="inline-form" style="display:flex;gap:8px;align-items:end;margin-top:10px;flex-wrap:wrap">@csrf
        <div class="field"><label>{{ __('الاسم *') }}</label><input name="name" required placeholder="{{ __('مثال: ضريبة القيمة المضافة') }}"></div>
        <div class="field"><label>{{ __('النسبة % *') }}</label><input name="rate_pct" type="number" step="0.01" min="0" max="99.99" required dir="ltr"></div>
        <button class="btn sm">{{ __('إضافة') }}</button>
    </form>
    <p class="hint">{{ __('النسبة لا تُعدَّل بعد إضافتها (حتى لا تتغير عروض سابقة)؛ عند تغيّر النظام أضف نسبة جديدة وأوقف القديمة.') }}</p>
    @endif
</div>
@endsection
