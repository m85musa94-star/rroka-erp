@extends('layouts.app')
@section('title', $t->exists ? __('تعديل ').$t->transfer_no : __('تحويل جديد'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('التحويلات وصرف العهد'), route('treasury.transfers.index')], [$t->exists ? $t->transfer_no : __('جديد'), null]]])
@endsection
@section('content')
@php($v = fn ($k) => old($k, $t->{$k} instanceof \Carbon\CarbonInterface ? $t->{$k}->format('Y-m-d') : $t->{$k}))
@php($option = function ($a, $field) use ($v) {
    $label = $a->name.($a->isCustody() ? ' — '.__('في العهدة: :n', ['n' => number_format($a->summary->custody_balance, 2)]) : '');
    return '<option value="'.$a->id.'"'.((string) $v($field) === (string) $a->id ? ' selected' : '').'>'.e($label).'</option>';
})
<form method="post" action="{{ $t->exists ? route('treasury.transfers.update', $t) : route('treasury.transfers.store') }}" class="card">
    @csrf
    @if($t->exists) @method('put') @endif
    <p class="hint" style="margin-top:0">{{ __('صرف عهدة: من الصندوق أو البنك إلى عهدة الموظف. إرجاع عهدة: من عهدة الموظف إلى الصندوق أو البنك. إيداع: من الصندوق إلى البنك.') }}</p>
    <div class="grid g3">
        <div class="field"><label>{{ __('التاريخ *') }}</label><input type="date" name="transfer_date" value="{{ $v('transfer_date') }}" required></div>
        @foreach(['from_account_id' => __('من *'), 'to_account_id' => __('إلى *')] as $field => $label)
            <div class="field"><label>{{ $label }}</label>
                <select name="{{ $field }}" required><option value="">{{ __('— اختر —') }}</option>
                    @foreach(['CASH' => __('الصناديق النقدية'), 'BANK' => __('الحسابات البنكية'), 'CUSTODY' => __('عهد الموظفين')] as $kind => $group)
                        @if($accounts->where('kind', $kind)->isNotEmpty())<optgroup label="{{ $group }}">@foreach($accounts->where('kind', $kind) as $a){!! $option($a, $field) !!}@endforeach</optgroup>@endif
                    @endforeach
                </select></div>
        @endforeach
        <div class="field"><label>{{ __('المبلغ *') }}</label><input name="amount" type="number" step="0.01" min="0.01" value="{{ $v('amount') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('المرجع') }}</label><input name="reference" value="{{ $v('reference') }}" dir="ltr" placeholder="{{ __('رقم سند الصرف أو الحوالة') }}"></div>
        <div class="field"><label>{{ __('ملاحظات') }}</label><input name="notes" value="{{ $v('notes') }}"></div>
    </div>
    <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
