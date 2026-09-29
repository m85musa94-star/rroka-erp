@extends('layouts.app')
@section('title', $c->exists ? __('تعديل ').$c->contract_no : __('عقد جديد'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('العقود'), route('contracts.index')], [$c->exists ? $c->contract_no : __('جديد'), null]]])
@endsection
@section('content')
@php($v = fn ($k) => old($k, $c->{$k} instanceof \Carbon\CarbonInterface ? $c->{$k}->format('Y-m-d') : $c->{$k}))
<form method="post" action="{{ $c->exists ? route('contracts.update', $c) : route('contracts.store') }}" class="card">
    @csrf
    @if($c->exists) @method('put') @endif
    <div class="grid g3">
        <div class="field"><label>{{ __('الموظف *') }}</label>
            <select name="employee_id" required @disabled($c->exists)><option value="">{{ __('— اختر —') }}</option>@foreach($employees as $e)<option value="{{ $e->id }}" @selected((string) $v('employee_id') === (string) $e->id)>{{ $e->name }} ({{ $e->employee_no }})</option>@endforeach</select>
            @if($c->exists)<input type="hidden" name="employee_id" value="{{ $c->employee_id }}">@endif</div>
        <div class="field"><label>{{ __('نوع العقد *') }}</label>
            <select name="contract_type" required>@foreach(['FIXED_TERM', 'INDEFINITE'] as $t)<option value="{{ $t }}" @selected($v('contract_type') === $t)>{{ __("rroka.contract_type.$t") }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('ساعات العمل الأسبوعية') }}</label><input name="weekly_hours" type="number" step="0.5" min="0.5" max="168" value="{{ $v('weekly_hours') }}" dir="ltr"></div>
        <div class="field"><label>{{ __('تاريخ البداية *') }}</label><input type="date" name="start_date" value="{{ $v('start_date') }}" required></div>
        <div class="field"><label>{{ __('تاريخ النهاية') }}</label><input type="date" name="end_date" value="{{ $v('end_date') }}"><div class="hint">{{ __('إلزامي للعقد محدد المدة.') }}</div></div>
    </div>
    <h2 style="margin-top:14px">{{ __('الأجر الشهري') }} <span class="muted" style="font-weight:400;font-size:13px">{{ __('— من العقد الموقّع؛ اكتب 0 لما لا يُصرف') }}</span></h2>
    <div class="grid g3">
        <div class="field"><label>{{ __('الراتب الأساسي *') }}</label><input name="basic_salary" type="number" step="0.01" min="0.01" value="{{ $v('basic_salary') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('بدل السكن *') }}</label><input name="housing_allowance" type="number" step="0.01" min="0" value="{{ $v('housing_allowance') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('بدل النقل *') }}</label><input name="transport_allowance" type="number" step="0.01" min="0" value="{{ $v('transport_allowance') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('بدلات أخرى *') }}</label><input name="other_allowance" type="number" step="0.01" min="0" value="{{ $v('other_allowance') }}" required dir="ltr"></div>
    </div>
    <div class="field"><label>{{ __('ملاحظات') }}</label><textarea name="notes">{{ $v('notes') }}</textarea></div>
    <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
