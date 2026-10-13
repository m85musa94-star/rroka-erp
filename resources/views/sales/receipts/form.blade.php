@extends('layouts.app')
@section('title', $x->exists ? __('تعديل ').$x->receipt_no : __('سند قبض جديد'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('سندات القبض'), route('sales.receipts.index')], [$x->exists ? $x->receipt_no : __('جديد'), null]]])
@endsection
@section('content')
@php($v = fn ($k) => old($k, $x->{$k} instanceof \Carbon\CarbonInterface ? $x->{$k}->format('Y-m-d') : $x->{$k}))
@if($accounts->isEmpty())
    <div class="alert warn">{{ __('أضف الصناديق والحسابات البنكية والعهد أولًا من تطبيق «الخزينة والعهد».') }}</div>
@endif
<form method="post" action="{{ $x->exists ? route('sales.receipts.update', $x) : route('sales.receipts.store') }}" enctype="multipart/form-data" class="card">
    @csrf
    @if($x->exists) @method('put') @endif
    <div class="grid g3">
        <div class="field"><label>{{ __('التاريخ *') }}</label><input type="date" name="receipt_date" value="{{ $v('receipt_date') }}" required></div>
        <div class="field"><label>{{ __('العميل *') }}</label>
            <select name="client_id" id="client" required><option value="">{{ __('— اختر —') }}</option>@foreach($clients as $c)<option value="{{ $c->id }}" @selected((string) $v('client_id') === (string) $c->id)>{{ $c->business_name }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('على الفاتورة') }}</label>
            <select name="sales_invoice_id" id="invoice"><option value="">{{ __('— دفعة بلا فاتورة (مقدمة) —') }}</option>@foreach($invoices as $i)<option value="{{ $i->id }}" data-client="{{ $i->client_id }}" @selected((string) $v('sales_invoice_id') === (string) $i->id)>{{ $i->daftra_invoice_no }} — {{ number_format($i->total, 2) }}@if($i->status === 'DRAFT') ({{ __('مسودة') }})@endif</option>@endforeach</select></div>
        <div class="field"><label>{{ __('المشروع') }}</label>
            <select name="project_id" id="project"><option value="">{{ __('— من الفاتورة أو بلا مشروع —') }}</option>@foreach($projects as $pr)<option value="{{ $pr->id }}" data-client="{{ $pr->client_id }}" @selected((string) $v('project_id') === (string) $pr->id)>{{ $pr->project_no }} — {{ $pr->title }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('المبلغ المستلم *') }}</label><input name="amount" type="number" step="0.01" min="0.01" value="{{ $v('amount') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('استُلم في *') }}</label>
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
// Only the chosen client's invoices and projects are offered.
(() => { const c = document.getElementById('client');
    const sync = () => { for (const s of [document.getElementById('invoice'), document.getElementById('project')]) {
        for (const o of s.options) { if (o.dataset.client) { o.hidden = o.dataset.client !== c.value; if (o.hidden && o.selected) s.value = ''; } } } };
    c.addEventListener('change', sync); sync(); })();
</script>
@endpush
