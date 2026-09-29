@extends('layouts.app')
@section('title', $quotation->exists ? __('تعديل ').$quotation->quotation_no : __('عرض سعر جديد'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => $quotation->exists
        ? [[__('عروض الأسعار'), route('quotations.index')], [$quotation->quotation_no, route('quotations.show', $quotation)], [__('تعديل'), null]]
        : [[__('عروض الأسعار'), route('quotations.index')], [__('جديد'), null]]])
@endsection
@section('content')
@php($rows = old('lines', $lines ?: [['description' => '', 'quantity' => 1, 'unit' => __('قطعة'), 'unit_price' => '']]))
<form method="post" action="{{ $quotation->exists ? route('quotations.update', $quotation) : route('quotations.store') }}">
    @csrf
    @if($quotation->exists) @method('put') @endif
    <div class="card">
        <div class="grid g3">
            <div class="field"><label>{{ __('العميل *') }}</label>
                <select name="client_id" required>
                    <option value="">{{ __('— اختر —') }}</option>
                    @foreach($clients as $c)
                        <option value="{{ $c->id }}" @selected((string) old('client_id', $quotation->client_id) === (string) $c->id)>{{ $c->business_name }} ({{ $c->client_no }})</option>
                    @endforeach
                </select>
                @if($clients->isEmpty())<div class="hint">{{ __('أضف عميلًا أولًا من صفحة العملاء.') }}</div>@endif
            </div>
            <div class="field"><label>{{ __('تاريخ الإصدار *') }}</label><input type="date" name="issue_date" value="{{ old('issue_date', $quotation->issue_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required></div>
            <div class="field"><label>{{ __('صالح حتى') }}</label><input type="date" name="valid_until" value="{{ old('valid_until', $quotation->valid_until?->format('Y-m-d')) }}"></div>
        </div>
    </div>

    <div class="card">
        <h2>{{ __('البنود') }} <span class="muted" style="font-weight:400;font-size:13px">{{ __('— الأسعار قبل ضريبة القيمة المضافة؛ الضريبة تُحتسب في دفترة') }}</span></h2>
        <div class="table-wrap"><table id="lines">
            <thead><tr><th style="width:40%">{{ __('الوصف') }}</th><th>{{ __('الكمية') }}</th><th>{{ __('الوحدة') }}</th><th>{{ __('سعر الوحدة') }}</th><th class="num">{{ __('الإجمالي') }}</th><th></th></tr></thead>
            <tbody>
            @foreach($rows as $i => $l)
                <tr>
                    <td><input name="lines[{{ $i }}][description]" value="{{ $l['description'] }}" required></td>
                    <td><input name="lines[{{ $i }}][quantity]" type="number" step="0.001" min="0.001" value="{{ $l['quantity'] }}" required dir="ltr" class="qty"></td>
                    <td><input name="lines[{{ $i }}][unit]" value="{{ $l['unit'] ?? __('قطعة') }}" required></td>
                    <td><input name="lines[{{ $i }}][unit_price]" type="number" step="0.01" min="0" value="{{ $l['unit_price'] }}" required dir="ltr" class="price"></td>
                    <td class="num total">0.00</td>
                    <td><button type="button" class="btn ghost sm del">{{ __('حذف') }}</button></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <button type="button" class="btn ghost sm" id="add" style="margin-top:10px">{{ __('+ إضافة بند') }}</button>

        <div class="grid g3" style="margin-top:16px;max-width:720px">
            <div class="field"><label>{{ __('المجموع') }}</label><input id="subtotal" readonly dir="ltr"></div>
            <div class="field"><label>{{ __('الخصم') }}</label><input name="discount_amount" id="discount" type="number" step="0.01" min="0" value="{{ old('discount_amount', $quotation->discount_amount ?? 0) }}" required dir="ltr"></div>
            <div class="field"><label>{{ __('الصافي قبل الضريبة') }}</label><input id="net" readonly dir="ltr"></div>
        </div>
        <div class="field"><label>{{ __('ملاحظات') }}</label><textarea name="notes">{{ old('notes', $quotation->notes) }}</textarea></div>
    </div>
    <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
@push('scripts')
<script>
(() => {
    const body = document.querySelector('#lines tbody');
    const fmt = n => (Math.round(n * 100) / 100).toFixed(2);
    function renumber() {
        [...body.rows].forEach((tr, i) => tr.querySelectorAll('input').forEach(inp => {
            inp.name = inp.name.replace(/lines\[\d+\]/, `lines[${i}]`);
        }));
    }
    function recalc() {
        let sub = 0;
        [...body.rows].forEach(tr => {
            const t = (parseFloat(tr.querySelector('.qty').value) || 0) * (parseFloat(tr.querySelector('.price').value) || 0);
            tr.querySelector('.total').textContent = fmt(t);
            sub += Math.round(t * 100) / 100;
        });
        document.getElementById('subtotal').value = fmt(sub);
        document.getElementById('net').value = fmt(sub - (parseFloat(document.getElementById('discount').value) || 0));
    }
    document.getElementById('add').addEventListener('click', () => {
        const tr = body.rows[0].cloneNode(true);
        tr.querySelectorAll('input').forEach(i => { i.value = i.classList.contains('qty') ? 1 : (i.name.includes('[unit]') ? '{{ __('قطعة') }}' : ''); });
        body.appendChild(tr); renumber(); recalc();
    });
    body.addEventListener('click', e => {
        if (e.target.classList.contains('del') && body.rows.length > 1) { e.target.closest('tr').remove(); renumber(); recalc(); }
    });
    document.addEventListener('input', recalc);
    recalc();
})();
</script>
@endpush
