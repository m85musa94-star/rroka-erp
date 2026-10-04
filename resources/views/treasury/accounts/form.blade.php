@extends('layouts.app')
@section('title', $a->exists ? __('تعديل ').$a->name : __('حساب جديد'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الخزينة والعهد'), route('treasury.accounts.index')], [$a->exists ? $a->name : __('جديد'), null]]])
@endsection
@section('content')
@php($v = fn ($k) => old($k, $a->{$k}))
@php($kind = old('kind', $a->kind))
<form method="post" action="{{ $a->exists ? route('treasury.accounts.update', $a) : route('treasury.accounts.store') }}" class="card">
    @csrf
    @if($a->exists) @method('put') @endif
    <div class="grid g3">
        <div class="field"><label>{{ __('النوع *') }}</label>
            @if($a->exists)
                <input value="{{ __("rroka.account_kind.$a->kind") }}" disabled><div class="hint">{{ __('النوع لا يتغير بعد الإنشاء.') }}</div>
            @else
                <select name="kind" id="kind" required>@foreach(\App\Models\PaymentAccount::KINDS as $k)<option value="{{ $k }}" @selected($kind === $k)>{{ __("rroka.account_kind.$k") }}</option>@endforeach</select>
            @endif
        </div>
        <div class="field"><label>{{ __('الاسم *') }}</label><input name="name" value="{{ $v('name') }}" required placeholder="{{ __('مثال: صندوق الورشة، بنك الراجحي، عهدة أحمد') }}"></div>
        <div class="field"><label>{{ __('رقم الخزينة المقابلة في دفترة') }}</label><input name="daftra_treasury_ref" value="{{ $v('daftra_treasury_ref') }}" dir="ltr">
            <div class="hint">{{ __('تُرسل حركات هذا الحساب إليها، وتتم المطابقة هناك. يُملأ بعد التحقق من ربط دفترة.') }}</div></div>
    </div>
    <div class="grid g3" data-kind="BANK">
        <div class="field"><label>{{ __('البنك') }}</label><input name="bank_name" value="{{ $v('bank_name') }}"></div>
        <div class="field"><label>{{ __('الآيبان') }}</label><input name="iban" value="{{ $v('iban') }}" dir="ltr" placeholder="SA.."></div>
    </div>
    <div class="grid g3" data-kind="CUSTODY">
        <div class="field"><label>{{ __('الموظف صاحب العهدة *') }}</label>
            @if($a->exists)
                <input value="{{ $a->employee?->name }}" disabled><div class="hint">{{ __('صاحب العهدة لا يتغير؛ لموظف آخر تُفتح عهدة جديدة.') }}</div>
            @else
                <select name="employee_id"><option value="">{{ __('— اختر —') }}</option>@foreach($employees as $e)<option value="{{ $e->id }}" @selected((string) $v('employee_id') === (string) $e->id)>{{ $e->name }}</option>@endforeach</select>
            @endif
        </div>
        <div class="field"><label>{{ __('الحد الأعلى للعهدة') }}</label><input name="custody_limit" type="number" step="0.01" min="0.01" value="{{ $v('custody_limit') }}" dir="ltr">
            <div class="hint">{{ __('اختياري. لا يُعتمد صرف يجعل العهدة تتجاوزه.') }}</div></div>
    </div>
    <div class="field"><label>{{ __('ملاحظات') }}</label><textarea name="notes">{{ $v('notes') }}</textarea></div>
    @if($a->exists)
        <label class="perm-item"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $a->is_active))> {{ __('نشط') }}</label>
        <div class="hint">{{ __('إغلاق العهدة يتطلب تسويتها إلى الصفر.') }}</div>
    @endif
    <div class="actions"><button class="btn">{{ __('حفظ') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
@push('scripts')
<script>
// Show only the fields of the chosen kind.
(() => { const k = document.getElementById('kind'), current = @json($kind);
    const sync = () => document.querySelectorAll('[data-kind]').forEach(el => { el.hidden = el.dataset.kind !== (k ? k.value : current); });
    if (k) { k.addEventListener('change', sync); } sync(); })();
</script>
@endpush
