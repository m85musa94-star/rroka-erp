@extends('layouts.app')
@section('title', $x->exists ? __('تعديل ').$x->return_no : __('تسجيل الإقرار المقدَّم'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الإقرار الضريبي'), route('accounting.vat.index')], [$x->exists ? $x->return_no : __('جديد'), null]]])
@endsection
@section('content')
@php($v = fn ($k) => old($k, $x->{$k} instanceof \Carbon\CarbonInterface ? $x->{$k}->format('Y-m-d') : $x->{$k}))
<div class="card">
    <h2>{{ __('الأرقام من المستندات المعتمدة') }}</h2>
    @include('accounting.vat._figures')
</div>
<form method="post" action="{{ $x->exists ? route('accounting.vat.update', $x) : route('accounting.vat.store') }}" enctype="multipart/form-data" class="card">
    @csrf
    @if($x->exists) @method('put') @endif
    <h2>{{ __('الإقرار كما قُدِّم للهيئة') }}</h2>
    <div class="grid g4">
        <div class="field"><label>{{ __('من *') }}</label><input type="date" name="period_start" value="{{ $v('period_start') }}" required></div>
        <div class="field"><label>{{ __('إلى *') }}</label><input type="date" name="period_end" value="{{ $v('period_end') }}" required></div>
        <div class="field"><label>{{ __('ضريبة المخرجات المقدَّمة') }}</label><input name="filed_output_vat" type="number" step="0.01" min="0" value="{{ $v('filed_output_vat') }}" dir="ltr" placeholder="{{ number_format($r['output'], 2, '.', '') }}"></div>
        <div class="field"><label>{{ __('ضريبة المدخلات المقدَّمة') }}</label><input name="filed_input_vat" type="number" step="0.01" min="0" value="{{ $v('filed_input_vat') }}" dir="ltr" placeholder="{{ number_format($r['input'], 2, '.', '') }}"></div>
    </div>
    @if($r['verify'] > 0)
    <div class="field"><label><input type="checkbox" name="include_verify" value="1" @checked(old('include_verify', $x->include_verify))> {{ __('أدرجتُ في الإقرار ضريبة الموردين بلا رقم ضريبي (:v)', ['v' => number_format($r['verify'], 2)]) }}</label>
        <input name="verify_reason" value="{{ $v('verify_reason') }}" placeholder="{{ __('السبب (إلزامي عند الإدراج)') }}" maxlength="500"></div>
    @endif
    <div class="grid g2">
        <div class="field"><label>{{ __('ملاحظات (تصحيحات فترات سابقة وغيرها)') }}</label><textarea name="notes" rows="2">{{ $v('notes') }}</textarea></div>
        <div class="field"><label>{{ __('صورة الإقرار المقدَّم') }}</label>
            @if($canAttach)<input type="file" name="document" accept="image/jpeg,image/png,image/webp">@else<div class="hint">{{ __('مخزن الصور غير مربوط بعد، فلا تُحفظ الصور حتى لا تضيع. راجع دليل النشر.') }}</div>@endif</div>
    </div>
    <p class="hint">{{ __('اكتب الأرقام كما قدّمتها فعلًا في بوابة الهيئة، حتى لو اختلفت عن أرقام المنظومة؛ الفرق يظهر في المطابقة.') }}</p>
    <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
