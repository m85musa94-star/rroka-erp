@extends('layouts.app')
@section('title', $x->exists ? __('تعديل ').$x->txn_no : __('حركة مالك جديدة'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('حركات المالك'), route('accounting.owner.index')], [$x->exists ? $x->txn_no : __('جديد'), null]]])
@endsection
@section('content')
@php($v = fn ($k) => old($k, $x->{$k} instanceof \Carbon\CarbonInterface ? $x->{$k}->format('Y-m-d') : $x->{$k}))
@if($accounts->isEmpty())
    <div class="alert warn">{{ __('أضف الصناديق والحسابات البنكية والعهد أولًا من تطبيق «الخزينة والعهد».') }}</div>
@endif
<form method="post" action="{{ $x->exists ? route('accounting.owner.update', $x) : route('accounting.owner.store') }}" enctype="multipart/form-data" class="card">
    @csrf
    @if($x->exists) @method('put') @endif
    <div class="grid g3">
        <div class="field"><label>{{ __('النوع *') }}</label>
            <select name="kind" required>@foreach(\App\Models\OwnerTransaction::KINDS as $k)<option value="{{ $k }}" @selected($v('kind') === $k)>{{ __("rroka.owner_kind.$k") }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('التاريخ *') }}</label><input type="date" name="txn_date" value="{{ $v('txn_date') }}" required></div>
        <div class="field"><label>{{ __('المبلغ *') }}</label><input name="amount" type="number" step="0.01" min="0.01" value="{{ $v('amount') }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('الحساب *') }}</label>
            <select name="payment_account_id" required>
                <option value="">{{ __('— اختر الصندوق أو البنك أو العهدة —') }}</option>
                @foreach(['CASH' => __('الصناديق النقدية'), 'BANK' => __('الحسابات البنكية'), 'CUSTODY' => __('عهد الموظفين')] as $kind => $label)
                    @if($accounts->where('kind', $kind)->isNotEmpty())
                        <optgroup label="{{ $label }}">@foreach($accounts->where('kind', $kind) as $a)<option value="{{ $a->id }}" @selected((string) $v('payment_account_id') === (string) $a->id)>{{ $a->name }}</option>@endforeach</optgroup>
                    @endif
                @endforeach
            </select>
            <div class="hint">{{ __('التمويل: الحساب الذي دخل فيه المبلغ. السحب: الحساب الذي خرج منه.') }}</div></div>
        <div class="field"><label>{{ __('المرجع (رقم الحوالة أو الشيك)') }}</label><input name="reference" value="{{ $v('reference') }}" dir="ltr" maxlength="100"></div>
        <div class="field"><label>{{ __('صورة الإيصال') }}</label>
            @if($canAttach)<input type="file" name="document" accept="image/jpeg,image/png,image/webp">@else<div class="hint">{{ __('مخزن الصور غير مربوط بعد، فلا تُحفظ الصور حتى لا تضيع. راجع دليل النشر.') }}</div>@endif</div>
    </div>
    <div class="field"><label>{{ __('البيان *') }}</label><input name="description" value="{{ $v('description') }}" required maxlength="500" placeholder="{{ __('مثال: تمويل رأس مال التشغيل') }}"></div>
    <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
