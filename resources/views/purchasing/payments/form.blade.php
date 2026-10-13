@extends('layouts.app')
@section('title', $x->exists ? __('تعديل ').$x->payment_no : __('سند صرف لمورد'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('سداد الموردين'), route('supplier-payments.index')], [$x->exists ? $x->payment_no : __('جديد'), null]]])
@endsection
@section('content')
@php($v = fn ($k) => old($k, $x->{$k} instanceof \Carbon\CarbonInterface ? $x->{$k}->format('Y-m-d') : $x->{$k}))
@php($inv = old('invoice', $x->expense_id ? 'E'.$x->expense_id : ($x->purchase_invoice_id ? 'P'.$x->purchase_invoice_id : '')))
@if($accounts->isEmpty())
    <div class="alert warn">{{ __('أضف الصناديق والحسابات البنكية والعهد أولًا من تطبيق «الخزينة والعهد».') }}</div>
@endif
<form method="post" action="{{ $x->exists ? route('supplier-payments.update', $x) : route('supplier-payments.store') }}" enctype="multipart/form-data" class="card">
    @csrf
    @if($x->exists) @method('put') @endif
    <div class="grid g3">
        <div class="field"><label>{{ __('التاريخ *') }}</label><input type="date" name="payment_date" value="{{ $v('payment_date') }}" required></div>
        <div class="field"><label>{{ __('المورد *') }}</label>
            <select name="supplier_id" id="supplier" required><option value="">{{ __('— اختر —') }}</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected((string) $v('supplier_id') === (string) $s->id)>{{ $s->name }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('عن الفاتورة') }}</label>
            <select name="invoice" id="invoice">
                <option value="">{{ __('— دفعة بلا فاتورة (مقدمة) —') }}</option>
                @if($creditExpenses->isNotEmpty())<optgroup label="{{ __('فواتير آجلة (مصروفات)') }}">@foreach($creditExpenses as $e)<option value="E{{ $e->id }}" data-supplier="{{ $e->supplier_id }}" @selected($inv === 'E'.$e->id)>{{ $e->expense_no }} — {{ $e->reference }} — {{ number_format($e->amount + $e->vat_amount, 2) }}@if($e->status === 'DRAFT') ({{ __('مسودة') }})@endif</option>@endforeach</optgroup>@endif
                @if($purchases->isNotEmpty())<optgroup label="{{ __('فواتير مشتريات للمخزون') }}">@foreach($purchases as $p)<option value="P{{ $p->id }}" data-supplier="{{ $p->supplier_id }}" @selected($inv === 'P'.$p->id)>{{ $p->purchase_no }} — {{ $p->supplier_invoice_no }} — {{ number_format($p->total, 2) }}@if($p->status === 'DRAFT') ({{ __('مسودة') }})@endif</option>@endforeach</optgroup>@endif
            </select></div>
        <div class="field"><label>{{ __('المبلغ المدفوع *') }}</label><input name="amount" type="number" step="0.01" min="0.01" value="{{ $v('amount') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('دُفع من *') }}</label>
            <select name="payment_account_id" required>
                <option value="">{{ __('— اختر الصندوق أو البنك أو العهدة —') }}</option>
                @foreach(['CASH' => __('الصناديق النقدية'), 'BANK' => __('الحسابات البنكية'), 'CUSTODY' => __('عهد الموظفين')] as $kind => $label)
                    @if($accounts->where('kind', $kind)->isNotEmpty())
                        <optgroup label="{{ $label }}">@foreach($accounts->where('kind', $kind) as $a)<option value="{{ $a->id }}" @selected((string) $v('payment_account_id') === (string) $a->id)>{{ $a->name }}</option>@endforeach</optgroup>
                    @endif
                @endforeach
            </select></div>
        <div class="field"><label>{{ __('المرجع (رقم الحوالة أو الشيك)') }}</label><input name="reference" value="{{ $v('reference') }}" dir="ltr" maxlength="100"></div>
        <div class="field"><label>{{ __('البيان') }}</label><input name="description" value="{{ $v('description') }}" maxlength="500"></div>
        <div class="field"><label>{{ __('صورة الإيصال') }}</label>
            @if($canAttach)<input type="file" name="document" accept="image/jpeg,image/png,image/webp">@else<div class="hint">{{ __('مخزن الصور غير مربوط بعد، فلا تُحفظ الصور حتى لا تضيع. راجع دليل النشر.') }}</div>@endif</div>
    </div>
    <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
@push('scripts')
<script>
// Only the chosen supplier's invoices are offered.
(() => { const s = document.getElementById('supplier'), i = document.getElementById('invoice');
    const sync = () => { for (const o of i.options) { if (o.dataset.supplier) { o.hidden = o.dataset.supplier !== s.value; if (o.hidden && o.selected) i.value = ''; } } };
    s.addEventListener('change', sync); sync(); })();
</script>
@endpush
