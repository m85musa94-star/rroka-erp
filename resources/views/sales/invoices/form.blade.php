@extends('layouts.app')
@php($credit = $x->kind === 'CREDIT_NOTE')
@section('title', $x->exists ? __('تعديل ').$x->invoice_no : ($credit ? __('إشعار دائن جديد') : __('فاتورة عميل جديدة')))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('فواتير العملاء'), route('sales.invoices.index')], [$x->exists ? $x->invoice_no : __('جديد'), null]]])
@endsection
@section('content')
@php($v = fn ($k) => old($k, $x->{$k} instanceof \Carbon\CarbonInterface ? $x->{$k}->format('Y-m-d') : $x->{$k}))
<div class="alert info">{{ __('الفاتورة الضريبية تصدر من دفترة. سجّلها هنا كما صدرت تمامًا: رقمها في دفترة، وتاريخها، والمبلغ قبل الضريبة، والضريبة.') }}</div>
<form method="post" action="{{ $x->exists ? route('sales.invoices.update', $x) : route('sales.invoices.store') }}" enctype="multipart/form-data" class="card">
    @csrf
    @if($x->exists) @method('put') @endif
    <input type="hidden" name="kind" value="{{ $x->kind }}">
    @if($credit)
        <input type="hidden" name="original_invoice_id" value="{{ $x->original_invoice_id }}">
        <p class="rec-sub">{{ __('إشعار دائن على الفاتورة') }} <a href="{{ route('sales.invoices.show', $original) }}">{{ $original->invoice_no }}</a> (<bdi dir="ltr">{{ $original->daftra_invoice_no }}</bdi>) — {{ $original->client->business_name }}@if($original->project) · {{ $original->project->project_no }}@endif</p>
    @endif
    <div class="grid g3">
        <div class="field"><label>{{ $credit ? __('رقم الإشعار في دفترة *') : __('رقم الفاتورة في دفترة *') }}</label><input name="daftra_invoice_no" value="{{ $v('daftra_invoice_no') }}" required dir="ltr" maxlength="60"></div>
        <div class="field"><label>{{ __('التاريخ *') }}</label><input type="date" name="invoice_date" value="{{ $v('invoice_date') }}" required></div>
        @unless($credit)
        <div class="field"><label>{{ __('العميل *') }}</label>
            <select name="client_id" id="client" required><option value="">{{ __('— اختر —') }}</option>@foreach($clients as $c)<option value="{{ $c->id }}" @selected((string) $v('client_id') === (string) $c->id)>{{ $c->business_name }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('المشروع') }}</label>
            <select name="project_id" id="project"><option value="">{{ __('— بلا مشروع —') }}</option>@foreach($projects as $pr)<option value="{{ $pr->id }}" data-client="{{ $pr->client_id }}" @selected((string) $v('project_id') === (string) $pr->id)>{{ $pr->project_no }} — {{ $pr->title }}</option>@endforeach</select>
            <div class="hint">{{ __('الإيراد يُسجَّل على المشروع ليظهر ربحه.') }}</div></div>
        @endunless
    </div>
    <div class="field"><label>{{ __('الوصف *') }}</label><input name="description" value="{{ $v('description') }}" required maxlength="500"></div>
    <div class="grid g3">
        <div class="field"><label>{{ __('المبلغ قبل الضريبة *') }}</label><input name="amount" type="number" step="0.01" min="0.01" value="{{ $v('amount') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('ضريبة القيمة المضافة كما في دفترة *') }}</label><input name="vat_amount" type="number" step="0.01" min="0" value="{{ $v('vat_amount') ?? 0 }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('صورة الفاتورة') }}</label>
            @if($canAttach)<input type="file" name="document" accept="image/jpeg,image/png,image/webp">@else<div class="hint">{{ __('مخزن الصور غير مربوط بعد، فلا تُحفظ الصور حتى لا تضيع. راجع دليل النشر.') }}</div>@endif</div>
    </div>
    <div class="field"><label>{{ __('ملاحظات') }}</label><textarea name="notes" rows="2">{{ $v('notes') }}</textarea></div>
    <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
@push('scripts')
<script>
// Only the chosen client's projects are offered.
(() => { const c = document.getElementById('client'), p = document.getElementById('project'); if (!c || !p) return;
    const sync = () => { for (const o of p.options) { if (o.dataset.client) { o.hidden = o.dataset.client !== c.value; if (o.hidden && o.selected) p.value = ''; } } };
    c.addEventListener('change', sync); sync(); })();
</script>
@endpush
