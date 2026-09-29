@extends('layouts.app')
@section('title', $p->exists ? __('تعديل ').$p->purchase_no : __('فاتورة مورد جديدة'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('فواتير المشتريات'), route('purchases.index')], [$p->exists ? $p->purchase_no : __('جديد'), null]]])
@endsection
@section('content')
@php($rows = old('lines', $lines ?: [['material_id' => '', 'quantity' => 1, 'unit_price' => '']]))
@if($materials->isEmpty() || $suppliers->isEmpty())
    <div class="alert warn">{{ __('أضف المورد والخامات أولًا: الموردون من «الموردون»، والخامات من تطبيق المخزون.') }}</div>
@endif
<form method="post" action="{{ $p->exists ? route('purchases.update', $p) : route('purchases.store') }}" enctype="multipart/form-data">
    @csrf
    @if($p->exists) @method('put') @endif
    <div class="card">
        <div class="grid g3">
            <div class="field"><label>{{ __('المورد *') }}</label>
                <select name="supplier_id" required><option value="">{{ __('— اختر —') }}</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected((string) old('supplier_id', $p->supplier_id) === (string) $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            <div class="field"><label>{{ __('رقم فاتورة المورد *') }}</label><input name="supplier_invoice_no" value="{{ old('supplier_invoice_no', $p->supplier_invoice_no) }}" required dir="ltr"><div class="hint">{{ __('لا تُقبل الفاتورة نفسها من المورد نفسه مرتين.') }}</div></div>
            <div class="field"><label>{{ __('تاريخ الفاتورة *') }}</label><input type="date" name="invoice_date" value="{{ old('invoice_date', $p->invoice_date?->format('Y-m-d')) }}" required></div>
            <div class="field"><label>{{ __('تاريخ الاستحقاق') }}</label><input type="date" name="due_date" value="{{ old('due_date', $p->due_date?->format('Y-m-d')) }}"></div>
            <div class="field"><label>{{ __('صورة الفاتورة') }}</label>
                @if($canAttach)<input type="file" name="document" accept="image/jpeg,image/png,image/webp">@if($p->attachment_id)<div class="hint">{{ __('مرفقة — اختر ملفًا لاستبدالها.') }}</div>@endif
                @else<div class="hint">{{ __('مخزن الصور غير مربوط بعد، فلا تُحفظ الصور حتى لا تضيع. راجع دليل النشر.') }}</div>@endif</div>
        </div>
    </div>
    <div class="card">
        <h2>{{ __('البنود') }} <span class="muted" style="font-weight:400;font-size:13px">{{ __('— الأسعار قبل الضريبة كما في فاتورة المورد') }}</span></h2>
        <div class="table-wrap"><table id="lines">
            <thead><tr><th style="width:45%">{{ __('الخامة') }}</th><th>{{ __('الكمية') }}</th><th>{{ __('سعر الوحدة') }}</th><th class="num">{{ __('الإجمالي') }}</th><th></th></tr></thead>
            <tbody>
            @foreach($rows as $i => $l)
                <tr>
                    <td><select name="lines[{{ $i }}][material_id]" required><option value="">{{ __('— الخامة —') }}</option>@foreach($materials as $m)<option value="{{ $m->id }}" @selected((string) $l['material_id'] === (string) $m->id)>{{ $m->code }} — {{ $m->name }} ({{ $m->uom }})</option>@endforeach</select></td>
                    <td><input name="lines[{{ $i }}][quantity]" type="number" step="0.0001" min="0.0001" value="{{ $l['quantity'] }}" required dir="ltr" class="qty"></td>
                    <td><input name="lines[{{ $i }}][unit_price]" type="number" step="0.0001" min="0" value="{{ $l['unit_price'] }}" required dir="ltr" class="price"></td>
                    <td class="num total">0.00</td>
                    <td><button type="button" class="btn ghost sm del">{{ __('حذف') }}</button></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <button type="button" class="btn ghost sm" id="add" style="margin-top:10px">{{ __('+ إضافة بند') }}</button>
        <div class="grid g3" style="margin-top:16px;max-width:900px">
            <div class="field"><label>{{ __('المجموع') }}</label><input id="subtotal" readonly dir="ltr"></div>
            <div class="field"><label>{{ __('الخصم *') }}</label><input name="discount_amount" id="discount" type="number" step="0.01" min="0" value="{{ old('discount_amount', $p->discount_amount ?? 0) }}" required dir="ltr"></div>
            <div class="field"><label>{{ __('الصافي قبل الضريبة') }}</label><input id="net" readonly dir="ltr"></div>
            <div class="field"><label>{{ __('ضريبة القيمة المضافة كما في الفاتورة *') }}</label><input name="vat_amount" type="number" step="0.01" min="0" value="{{ old('vat_amount', $p->vat_amount) }}" required dir="ltr"><div class="hint">{{ __('تُنقل من فاتورة المورد كما هي؛ النظام لا يحتسب الضريبة.') }}</div></div>
        </div>
        <div class="field"><label>{{ __('ملاحظات') }}</label><textarea name="notes">{{ old('notes', $p->notes) }}</textarea></div>
    </div>
    <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
@push('scripts')
<script>
(() => {
    const body = document.querySelector('#lines tbody');
    const fmt = n => (Math.round(n * 100) / 100).toFixed(2);
    const renumber = () => [...body.rows].forEach((tr, i) => tr.querySelectorAll('input,select').forEach(el => { el.name = el.name.replace(/lines\[\d+\]/, `lines[${i}]`); }));
    function recalc() {
        let sub = 0;
        [...body.rows].forEach(tr => {
            const t = (parseFloat(tr.querySelector('.qty').value) || 0) * (parseFloat(tr.querySelector('.price').value) || 0);
            tr.querySelector('.total').textContent = fmt(t); sub += Math.round(t * 100) / 100;
        });
        document.getElementById('subtotal').value = fmt(sub);
        document.getElementById('net').value = fmt(sub - (parseFloat(document.getElementById('discount').value) || 0));
    }
    document.getElementById('add').addEventListener('click', () => {
        const tr = body.rows[0].cloneNode(true);
        tr.querySelectorAll('input').forEach(i => { i.value = i.classList.contains('qty') ? 1 : ''; });
        tr.querySelector('select').value = '';
        body.appendChild(tr); renumber(); recalc();
    });
    body.addEventListener('click', e => { if (e.target.classList.contains('del') && body.rows.length > 1) { e.target.closest('tr').remove(); renumber(); recalc(); } });
    document.addEventListener('input', recalc); recalc();
})();
</script>
@endpush
