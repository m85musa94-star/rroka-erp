@extends('layouts.app')
@section('title', $x->exists ? __('تعديل ').$x->expense_no : __('مصروف جديد'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('المصروفات'), route('expenses.index')], [$x->exists ? $x->expense_no : __('جديد'), null]]])
@endsection
@section('content')
@php($v = fn ($k) => old($k, $x->{$k} instanceof \Carbon\CarbonInterface ? $x->{$k}->format('Y-m-d') : $x->{$k}))
@if($categories->isEmpty())
    <div class="alert warn">{{ __('أضف تصنيفات المصروفات أولًا من «تصنيفات المصروفات».') }}</div>
@endif
<form method="post" action="{{ $x->exists ? route('expenses.update', $x) : route('expenses.store') }}" enctype="multipart/form-data" class="card">
    @csrf
    @if($x->exists) @method('put') @endif
    <div class="grid g3">
        <div class="field"><label>{{ __('التاريخ *') }}</label><input type="date" name="expense_date" value="{{ $v('expense_date') }}" required></div>
        <div class="field"><label>{{ __('التصنيف *') }}</label>
            <select name="category_id" required><option value="">{{ __('— اختر —') }}</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected((string) $v('category_id') === (string) $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('المشروع') }}</label>
            <select name="project_id"><option value="">{{ __('— مصروف عام للورشة —') }}</option>@foreach($projects as $pr)<option value="{{ $pr->id }}" @selected((string) $v('project_id') === (string) $pr->id)>{{ $pr->project_no }} — {{ $pr->title }}</option>@endforeach</select>
            <div class="hint">{{ __('المصروف على مشروع يدخل في تكلفته الفعلية بعد الاعتماد.') }}</div></div>
        <div class="field"><label>{{ __('المورد') }}</label>
            <select name="supplier_id"><option value="">{{ __('— بلا —') }}</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected((string) $v('supplier_id') === (string) $s->id)>{{ $s->name }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('أو الجهة المستفيدة') }}</label><input name="payee" value="{{ $v('payee') }}" placeholder="{{ __('إن لم تكن موردًا مسجلًا') }}"></div>
        <div class="field"><label>{{ __('رقم الإيصال / المرجع') }}</label><input name="reference" value="{{ $v('reference') }}" dir="ltr"></div>
    </div>
    <div class="field"><label>{{ __('الوصف *') }}</label><input name="description" value="{{ $v('description') }}" required></div>
    <div class="grid g3">
        <div class="field"><label>{{ __('المبلغ قبل الضريبة *') }}</label><input name="amount" type="number" step="0.01" min="0.01" value="{{ $v('amount') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('ضريبة القيمة المضافة كما في الإيصال *') }}</label><input name="vat_amount" type="number" step="0.01" min="0" value="{{ $v('vat_amount') }}" required dir="ltr"><div class="hint">{{ __('اكتب 0 إن لم يكن الإيصال ضريبيًا.') }}</div></div>
        <div class="field"><label>{{ __('طريقة الدفع *') }}</label>
            <select name="payment_method" id="method" required>@foreach(\App\Models\Expense::METHODS as $m)<option value="{{ $m }}" @selected($v('payment_method') === $m)>{{ __("rroka.payment_method.$m") }}</option>@endforeach</select></div>
        <div class="field" id="paid-by"><label>{{ __('دفعه من عهدته') }}</label>
            <select name="paid_by_employee_id"><option value="">{{ __('— بلا —') }}</option>@foreach($employees as $e)<option value="{{ $e->id }}" @selected((string) $v('paid_by_employee_id') === (string) $e->id)>{{ $e->name }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('صورة الإيصال') }}</label>
            @if($canAttach)<input type="file" name="document" accept="image/jpeg,image/png,image/webp">@else<div class="hint">{{ __('مخزن الصور غير مربوط بعد، فلا تُحفظ الصور حتى لا تضيع. راجع دليل النشر.') }}</div>@endif</div>
    </div>
    <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
@push('scripts')
<script>
(() => { const m = document.getElementById('method'), p = document.getElementById('paid-by');
    const sync = () => { p.hidden = m.value !== 'PETTY_CASH'; p.querySelector('select').required = m.value === 'PETTY_CASH'; };
    m.addEventListener('change', sync); sync(); })();
</script>
@endpush
