@extends('layouts.app')
@section('title', __('مشروع سابق'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('المشاريع'), route('projects.index')], [__('مشروع سابق'), null]]])
@endsection
@section('content')
<div class="alert info">{{ __('لمشاريع ما قبل تشغيل المنظومة فقط. يُنشأ عرض سعر معتمد بالقيمة المتفق عليها، معفى من ورقة التكلفة بالسبب الذي تكتبه، ولا يُرسل إلى دفترة. بعدها تُحمَّل مصروفات المشروع عليه، وتُسجَّل فواتيره وتحصيلاته.') }}</div>
<form method="post" action="{{ route('projects.past.store') }}" class="card">
    @csrf
    <div class="grid g3">
        <div class="field"><label>{{ __('العميل *') }}</label>
            <select name="client_id" required><option value="">{{ __('— اختر —') }}</option>@foreach($clients as $c)<option value="{{ $c->id }}" @selected((string) old('client_id') === (string) $c->id)>{{ $c->business_name }}</option>@endforeach</select>
            <div class="hint">{{ __('إن لم يكن العميل مسجلًا، أضفه أولًا من تطبيق العملاء.') }}</div></div>
        <div class="field"><label>{{ __('عنوان المشروع *') }}</label><input name="title" value="{{ old('title') }}" required maxlength="255" placeholder="{{ __('مثال: مطبخ فيلا حي النرجس') }}"></div>
        <div class="field"><label>{{ __('قيمة العقد قبل الضريبة *') }}</label><input name="contract_value" type="number" step="0.01" min="0.01" value="{{ old('contract_value') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('نسبة الضريبة') }}</label>
            <select name="vat_rate_id"><option value="">{{ __('— بلا ضريبة —') }}</option>@foreach($vatRates as $r)<option value="{{ $r->id }}" @selected((string) old('vat_rate_id') === (string) $r->id)>{{ $r->name }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('تاريخ البدء *') }}</label><input type="date" name="start_date" value="{{ old('start_date') }}" required></div>
        <div class="field"><label>{{ __('تاريخ التسليم المستهدف') }}</label><input type="date" name="target_date" value="{{ old('target_date') }}"></div>
        <div class="field"><label>{{ __('المرحلة الحالية *') }}</label>
            <select name="stage" id="stage" required>@foreach(\App\Http\Controllers\Web\ProjectController::PAST_STAGES as $st)<option value="{{ $st }}" @selected(old('stage', 'COMPLETED') === $st)>{{ __("rroka.status.$st") }}</option>@endforeach</select></div>
        <div class="field" id="completed"><label>{{ __('تاريخ التسليم الفعلي *') }}</label><input type="date" name="completed_on" value="{{ old('completed_on') }}"></div>
        <div class="field"><label>{{ __('مدير المشروع') }}</label>
            <select name="manager_id"><option value="">—</option>@foreach($users as $u)<option value="{{ $u->id }}" @selected((string) old('manager_id') === (string) $u->id)>{{ $u->name }}</option>@endforeach</select></div>
    </div>
    <div class="field"><label>{{ __('سبب الإعفاء من ورقة التكلفة *') }}</label><input name="reason" value="{{ old('reason', __('مشروع سابق لتشغيل المنظومة؛ السعر متفق عليه قبل اعتماد معدلات التكلفة.')) }}" required maxlength="500"></div>
    <div class="actions"><button class="btn">{{ __('إنشاء المشروع السابق') }}</button><a class="btn ghost" href="{{ route('projects.index') }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
@push('scripts')
<script>
(() => { const s = document.getElementById('stage'), c = document.getElementById('completed');
    const sync = () => { c.hidden = s.value !== 'COMPLETED'; c.querySelector('input').required = !c.hidden; };
    s.addEventListener('change', sync); sync(); })();
</script>
@endpush
