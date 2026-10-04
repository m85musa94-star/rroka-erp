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
@if($accounts->isEmpty())
    <div class="alert warn">{{ __('أضف الصناديق والحسابات البنكية والعهد أولًا من تطبيق «الخزينة والعهد».') }}</div>
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
        <div class="field"><label>{{ __('دُفع من *') }}</label>
            <select name="payment_account_id" id="account" required>
                <option value="">{{ __('— اختر الصندوق أو البنك أو العهدة —') }}</option>
                @foreach(['CASH' => __('الصناديق النقدية'), 'BANK' => __('الحسابات البنكية'), 'CUSTODY' => __('عهد الموظفين')] as $kind => $label)
                    @if($accounts->where('kind', $kind)->isNotEmpty())
                        <optgroup label="{{ $label }}">@foreach($accounts->where('kind', $kind) as $a)<option value="{{ $a->id }}" data-kind="{{ $a->kind }}" @selected((string) $v('payment_account_id') === (string) $a->id)>{{ $a->name }}</option>@endforeach</optgroup>
                    @endif
                @endforeach
            </select>
            <div class="hint">{{ __('يُرسل لاحقًا إلى الخزينة المقابلة في دفترة، وهناك تتم مطابقة البنك والنقدية.') }}</div></div>
        <div class="field" id="bank-method"><label>{{ __('طريقة الدفع من البنك') }}</label>
            <select name="payment_method">@foreach(\App\Models\PaymentAccount::METHODS['BANK'] as $m)<option value="{{ $m }}" @selected($v('payment_method') === $m)>{{ __("rroka.payment_method.$m") }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('صورة الإيصال') }}</label>
            @if($canAttach)<input type="file" name="document" accept="image/jpeg,image/png,image/webp">@else<div class="hint">{{ __('مخزن الصور غير مربوط بعد، فلا تُحفظ الصور حتى لا تضيع. راجع دليل النشر.') }}</div>@endif</div>
    </div>
    <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
@push('scripts')
<script>
// The bank method (transfer or card) is asked only for a bank account; the others imply it.
(() => { const a = document.getElementById('account'), b = document.getElementById('bank-method');
    const sync = () => { b.hidden = a.selectedOptions[0]?.dataset.kind !== 'BANK'; };
    a.addEventListener('change', sync); sync(); })();
</script>
@endpush
