@extends('layouts.app')
@section('title', $s->exists ? __('تعديل ').$s->name : __('مورد جديد'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الموردون'), route('suppliers.index')], [$s->exists ? $s->name : __('جديد'), null]]])
@endsection
@section('content')
@php($v = fn ($k) => old($k, $s->{$k}))
<form method="post" action="{{ $s->exists ? route('suppliers.update', $s) : route('suppliers.store') }}" class="card">
    @csrf
    @if($s->exists) @method('put') @endif
    <div class="grid g3">
        <div class="field"><label>{{ __('اسم المورد *') }}</label><input name="name" value="{{ $v('name') }}" required></div>
        <div class="field"><label>{{ __('الرقم الضريبي') }}</label><input name="vat_number" value="{{ $v('vat_number') }}" dir="ltr" maxlength="15"><div class="hint">{{ __('١٥ رقمًا، كما في فاتورة المورد الضريبية.') }}</div></div>
        <div class="field"><label>{{ __('السجل التجاري') }}</label><input name="commercial_reg_no" value="{{ $v('commercial_reg_no') }}" dir="ltr"></div>
        <div class="field"><label>{{ __('الجوال') }}</label><input name="phone" value="{{ $v('phone') }}" dir="ltr"></div>
        <div class="field"><label>{{ __('البريد الإلكتروني') }}</label><input name="email" type="email" value="{{ $v('email') }}" dir="ltr"></div>
        <div class="field"><label>{{ __('المدينة') }}</label><input name="city" value="{{ $v('city') }}"></div>
        <div class="field"><label>{{ __('الآيبان (IBAN)') }}</label><input name="iban" value="{{ $v('iban') }}" dir="ltr" placeholder="SA…"></div>
        <div class="field"><label>{{ __('العنوان') }}</label><input name="address" value="{{ $v('address') }}"></div>
    </div>
    <div class="field"><label>{{ __('ملاحظات') }}</label><textarea name="notes">{{ $v('notes') }}</textarea></div>
    <label class="perm-item"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" style="width:auto" @checked($v('is_active'))> {{ __('نشط') }}</label>
    <div class="actions"><button class="btn">{{ __('حفظ') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
