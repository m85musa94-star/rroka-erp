@extends('layouts.app')
@section('title', $client->exists ? 'تعديل العميل' : 'عميل جديد')
@section('cp')
    @include('partials.control-panel', ['crumbs' => $client->exists
        ? [['العملاء', route('clients.index')], [$client->business_name, route('clients.show', $client)], ['تعديل', null]]
        : [['العملاء', route('clients.index')], ['جديد', null]]])
@endsection
@section('content')
<form method="post" action="{{ $client->exists ? route('clients.update', $client) : route('clients.store') }}" class="card">
    @csrf
    @if($client->exists) @method('put') @endif
    <div class="grid g2">
        <div class="field"><label>اسم العميل *</label><input name="business_name" value="{{ old('business_name', $client->business_name) }}" required></div>
        <div class="field"><label>النوع</label>
            <select name="client_type">
                @foreach(['INDIVIDUAL', 'COMPANY'] as $t)
                    <option value="{{ $t }}" @selected(old('client_type', $client->client_type ?? 'INDIVIDUAL') === $t)>{{ __("rroka.client_type.$t") }}</option>
                @endforeach
            </select>
        </div>
        <div class="field"><label>الجوال</label><input name="phone" value="{{ old('phone', $client->phone) }}" dir="ltr"></div>
        <div class="field"><label>البريد الإلكتروني</label><input name="email" type="email" value="{{ old('email', $client->email) }}" dir="ltr"></div>
        <div class="field"><label>الرقم الضريبي</label><input name="vat_number" value="{{ old('vat_number', $client->vat_number) }}" dir="ltr" maxlength="15"><div class="hint">١٥ رقمًا، للمنشآت المسجلة في ضريبة القيمة المضافة.</div></div>
        <div class="field"><label>السجل التجاري</label><input name="commercial_reg_no" value="{{ old('commercial_reg_no', $client->commercial_reg_no) }}" dir="ltr"></div>
        <div class="field"><label>المدينة</label><input name="city" value="{{ old('city', $client->city) }}"></div>
        <div class="field"><label>العنوان</label><input name="address" value="{{ old('address', $client->address) }}"></div>
    </div>
    <div class="field"><label>ملاحظات</label><textarea name="notes">{{ old('notes', $client->notes) }}</textarea></div>
    <div class="actions"><button class="btn">حفظ</button><a class="btn ghost" href="{{ url()->previous() }}">إلغاء</a></div>
</form>
@endsection
