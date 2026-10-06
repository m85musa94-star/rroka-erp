@extends('layouts.app')
@section('title', $e->exists ? __('تعديل قيد') : __('قيد جديد'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('القيود'), route('accounting.journal.index')], [$e->exists ? $e->title() : __('جديد'), null]]])
@endsection
@section('content')
@php($rows = old('lines', $lines ?: [['account_id' => '', 'debit' => '', 'credit' => '', 'description' => '', 'project_id' => '', 'cost_center_id' => ''], ['account_id' => '', 'debit' => '', 'credit' => '', 'description' => '', 'project_id' => '', 'cost_center_id' => '']]))
@if($accounts->isEmpty())
    <div class="alert warn">{{ __('لا توجد حسابات تقبل القيود بعد. أنشئ دليل الحسابات أولًا.') }} <a href="{{ route('accounting.accounts.index') }}">{{ __('دليل الحسابات') }}</a></div>
@endif
<form method="post" action="{{ $e->exists ? route('accounting.journal.update', $e) : route('accounting.journal.store') }}">
    @csrf
    @if($e->exists) @method('put') @endif
    <div class="card">
        <div class="grid g4">
            <div class="field"><label>{{ __('التاريخ *') }}</label><input type="date" name="entry_date" value="{{ old('entry_date', $e->entry_date?->format('Y-m-d')) }}" required></div>
            <div class="field"><label>{{ __('النوع *') }}</label>
                <select name="source_type">@foreach(['MANUAL', 'OPENING'] as $s)<option value="{{ $s }}" @selected(old('source_type', $e->source_type) === $s)>{{ __("rroka.journal_source.$s") }}</option>@endforeach</select>
                <div class="hint">{{ __('الافتتاحي يؤرَّخ بتاريخ بداية الدفاتر.') }}</div></div>
            <div class="field" style="grid-column: span 2"><label>{{ __('البيان *') }}</label><input name="description" value="{{ old('description', $e->description) }}" required></div>
            <div class="field"><label>{{ __('المرجع') }}</label><input name="reference" value="{{ old('reference', $e->reference) }}" placeholder="{{ __('رقم المستند المؤيد') }}"></div>
        </div>
    </div>
    <div class="card">
        <h2>{{ __('السطور') }}</h2>
        <div class="table-wrap"><table id="lines">
            <thead><tr><th style="width:28%">{{ __('الحساب') }}</th><th>{{ __('البيان') }}</th><th>{{ __('المشروع') }}</th><th>{{ __('مركز التكلفة') }}</th><th>{{ __('مدين') }}</th><th>{{ __('دائن') }}</th><th></th></tr></thead>
            <tbody>
            @foreach($rows as $i => $l)
                <tr>
                    <td><select name="lines[{{ $i }}][account_id]"><option value="">{{ __('— الحساب —') }}</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected((string) ($l['account_id'] ?? '') === (string) $a->id)>{{ $a->code }} — {{ $a->label() }}</option>@endforeach</select></td>
                    <td><input name="lines[{{ $i }}][description]" value="{{ $l['description'] ?? '' }}"></td>
                    <td><select name="lines[{{ $i }}][project_id]"><option value="">—</option>@foreach($projects as $p)<option value="{{ $p->id }}" @selected((string) ($l['project_id'] ?? '') === (string) $p->id)>{{ $p->project_no }}</option>@endforeach</select></td>
                    <td><select name="lines[{{ $i }}][cost_center_id]"><option value="">—</option>@foreach($centers as $c)<option value="{{ $c->id }}" @selected((string) ($l['cost_center_id'] ?? '') === (string) $c->id)>{{ $c->code }}</option>@endforeach</select></td>
                    <td><input name="lines[{{ $i }}][debit]" type="number" step="0.01" min="0" value="{{ (float) ($l['debit'] ?? 0) ? $l['debit'] : '' }}" dir="ltr" class="dr"></td>
                    <td><input name="lines[{{ $i }}][credit]" type="number" step="0.01" min="0" value="{{ (float) ($l['credit'] ?? 0) ? $l['credit'] : '' }}" dir="ltr" class="cr"></td>
                    <td><button type="button" class="btn ghost sm del">{{ __('حذف') }}</button></td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr><th colspan="4">{{ __('المجموع') }} <span id="diff" class="muted"></span></th><th class="num" id="tdr">0.00</th><th class="num" id="tcr">0.00</th><th></th></tr></tfoot>
        </table></div>
        <button type="button" class="btn ghost sm" id="add" style="margin-top:10px">{{ __('+ إضافة سطر') }}</button>
        <p class="hint">{{ __('كل سطر مدين أو دائن. يُحفظ القيد مسودة ولو لم يتوازن، ولا يُرحَّل إلا متوازنًا.') }}</p>
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
    const diffLabel = @json(__('الفرق'));
    function recalc() {
        let dr = 0, cr = 0;
        [...body.rows].forEach(tr => { dr += parseFloat(tr.querySelector('.dr').value) || 0; cr += parseFloat(tr.querySelector('.cr').value) || 0; });
        document.getElementById('tdr').textContent = fmt(dr);
        document.getElementById('tcr').textContent = fmt(cr);
        const d = Math.round((dr - cr) * 100) / 100;
        document.getElementById('diff').textContent = d ? `— ${diffLabel} ${fmt(Math.abs(d))}` : '✓';
    }
    document.getElementById('add').addEventListener('click', () => {
        const tr = body.rows[0].cloneNode(true);
        tr.querySelectorAll('input').forEach(i => { i.value = ''; });
        tr.querySelectorAll('select').forEach(s => { s.value = ''; });
        body.appendChild(tr); renumber(); recalc();
    });
    body.addEventListener('click', e => { if (e.target.classList.contains('del') && body.rows.length > 2) { e.target.closest('tr').remove(); renumber(); recalc(); } });
    document.addEventListener('input', recalc); recalc();
})();
</script>
@endpush
