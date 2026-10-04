@extends('layouts.app')
@section('title', $card->exists ? __('تعديل بطاقة تكلفة الآلة') : __('بطاقة تكلفة آلة'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('تكلفة الآلات'), route('costing.machine-cards.index')], [$card->exists ? $card->machine->code : __('جديد'), null]]])
@endsection
@section('content')
@php($v = fn ($k) => old($k, $card->{$k}))
<form method="post" action="{{ $card->exists ? route('costing.machine-cards.update', $card) : route('costing.machine-cards.store') }}" class="card">
    @csrf
    @if($card->exists) @method('put') @endif
    <div class="grid g3">
        <div class="field"><label>{{ __('الآلة *') }}</label>
            @if($card->exists)<input value="{{ $card->machine->code }} — {{ $card->machine->name }}" disabled>
            @else<select name="machine_id" required><option value="">{{ __('— اختر —') }}</option>@foreach($machines as $m)<option value="{{ $m->id }}" @selected((string) $v('machine_id') === (string) $m->id)>{{ $m->code }} — {{ $m->name }}</option>@endforeach</select>@endif
        </div>
    </div>
    <h2>{{ __('الإهلاك') }}</h2>
    <div class="grid g3">
        <div class="field"><label>{{ __('تكلفة الشراء *') }}</label><input name="acquisition_cost" type="number" step="0.01" min="0" value="{{ $v('acquisition_cost') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('القيمة المتبقية في نهاية العمر *') }}</label><input name="residual_value" type="number" step="0.01" min="0" value="{{ $v('residual_value') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('العمر الإنتاجي (سنوات) *') }}</label><input name="useful_life_years" type="number" step="0.01" min="0.01" value="{{ $v('useful_life_years') }}" required dir="ltr"></div>
    </div>
    <h2>{{ __('ساعات التشغيل السنوية') }}</h2>
    <div class="grid g3">
        <div class="field"><label>{{ __('النظرية *') }}</label><input name="theoretical_annual_hours" type="number" step="0.01" min="0.01" value="{{ $v('theoretical_annual_hours') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('العملية المتوقعة *') }}</label><input name="practical_annual_hours" type="number" step="0.01" min="0.01" value="{{ $v('practical_annual_hours') }}" required dir="ltr">
            <div class="hint">{{ __('بعد استبعاد الصيانة والتجهيز والتوقف المعتاد. تُقسم عليها كل التكاليف السنوية.') }}</div></div>
    </div>
    <h2>{{ __('الكهرباء') }}</h2>
    <div class="grid g3">
        <div class="field"><label>{{ __('القدرة (kW) *') }}</label><input name="power_kw" type="number" step="0.001" min="0" value="{{ $v('power_kw') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('معامل التحميل المتوقع (0 إلى 1) *') }}</label><input name="load_factor" type="number" step="0.001" min="0" max="1" value="{{ $v('load_factor') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('سعر الكيلوواط ساعة') }}</label><input value="{{ $card->electricity_rate !== null ? number_format($card->electricity_rate, 4) : __('يؤخذ من سعر الكهرباء المعتمد في تاريخ السريان') }}" disabled></div>
    </div>
    <h2>{{ __('تكاليف التشغيل السنوية') }}</h2>
    <div class="grid g3">
        <div class="field"><label>{{ __('الصيانة *') }}</label><input name="annual_maintenance" type="number" step="0.01" min="0" value="{{ $v('annual_maintenance') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('قطع الغيار *') }}</label><input name="annual_spare_parts" type="number" step="0.01" min="0" value="{{ $v('annual_spare_parts') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('تكاليف تشغيل أخرى *') }}</label><input name="annual_other" type="number" step="0.01" min="0" value="{{ $v('annual_other') }}" required dir="ltr"></div>
    </div>
    @include('costing._fields', ['r' => $card])
    <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
