@extends('layouts.app')
@section('title', $e->exists ? __('تعديل ').$e->name : __('موظف جديد'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => $e->exists
        ? [[__('الموظفون'), route('employees.index')], [$e->name, route('employees.show', $e)], [__('تعديل'), null]]
        : [[__('الموظفون'), route('employees.index')], [__('جديد'), null]]])
@endsection
@section('content')
@php($v = fn ($k) => old($k, $e->{$k} instanceof \Carbon\CarbonInterface ? $e->{$k}->format('Y-m-d') : $e->{$k}))
<form method="post" action="{{ $e->exists ? route('employees.update', $e) : route('employees.store') }}">
    @csrf
    @if($e->exists) @method('put') @endif
    <div class="card">
        <h2>{{ __('بيانات العمل') }}</h2>
        <div class="grid g3">
            <div class="field"><label>{{ __('الاسم الكامل *') }}</label><input name="name" value="{{ $v('name') }}" required></div>
            <div class="field"><label>{{ __('المسمى الوظيفي') }}</label>
                <select name="job_id"><option value="">{{ __('— بلا —') }}</option>@foreach($jobs as $j)<option value="{{ $j->id }}" @selected((string) $v('job_id') === (string) $j->id)>{{ $j->name }}</option>@endforeach</select></div>
            <div class="field"><label>{{ __('المهنة') }}</label><input name="trade" value="{{ $v('trade') }}" placeholder="{{ __('المهنة (نجار، دهّان…)') }}"></div>
            <div class="field"><label>{{ __('القسم') }}</label>
                <select name="department_id"><option value="">{{ __('— بلا —') }}</option>@foreach($departments as $d)<option value="{{ $d->id }}" @selected((string) $v('department_id') === (string) $d->id)>{{ $d->name }}</option>@endforeach</select></div>
            <div class="field"><label>{{ __('المدير المباشر') }}</label>
                <select name="manager_id"><option value="">{{ __('— بلا —') }}</option>@foreach($managers as $m)<option value="{{ $m->id }}" @selected((string) $v('manager_id') === (string) $m->id)>{{ $m->name }}</option>@endforeach</select></div>
            <div class="field"><label>{{ __('نوع التوظيف') }}</label>
                <select name="employment_type"><option value="">{{ __('— بلا —') }}</option>@foreach(['FULL_TIME', 'PART_TIME', 'CONTRACTOR'] as $t)<option value="{{ $t }}" @selected($v('employment_type') === $t)>{{ __("rroka.employment_type.$t") }}</option>@endforeach</select></div>
            <div class="field"><label>{{ __('تاريخ التعيين') }}</label><input type="date" name="hire_date" value="{{ $v('hire_date') }}"></div>
            <div class="field"><label>{{ __('هاتف العمل') }}</label><input name="work_phone" value="{{ $v('work_phone') }}" dir="ltr"></div>
            <div class="field"><label>{{ __('بريد العمل') }}</label><input name="work_email" type="email" value="{{ $v('work_email') }}" dir="ltr"></div>
        </div>
        <label class="perm-item"><input type="hidden" name="is_direct_labor" value="0"><input type="checkbox" name="is_direct_labor" value="1" style="width:auto" @checked($v('is_direct_labor'))> {{ __('عمالة مباشرة في الإنتاج (تُحمَّل ساعاته على المشاريع)') }}</label>
        <div class="field" style="margin-top:10px"><label>{{ __('حساب المستخدم المرتبط') }}</label>
            <select name="user_id"><option value="">{{ __('— بلا —') }}</option>@foreach($users as $usr)<option value="{{ $usr->id }}" @selected((string) $v('user_id') === (string) $usr->id)>{{ $usr->name }} ({{ $usr->email }})</option>@endforeach</select>
            <div class="hint">{{ __('يتيح للموظف طلب إجازاته بنفسه، ويمنعه من اعتماد طلباته.') }}</div></div>
    </div>
    <div class="card">
        <h2>{{ __('البيانات الشخصية') }} <span class="muted" style="font-weight:400;font-size:13px">{{ __('— لا يراها إلا من يملك صلاحية إدارة ملفات الموظفين') }}</span></h2>
        <div class="grid g3">
            <div class="field"><label>{{ __('الجنسية') }}</label><input name="nationality" value="{{ $v('nationality') }}"></div>
            <div class="field"><label>{{ __('نوع الهوية') }}</label>
                <select name="id_type"><option value="">{{ __('— بلا —') }}</option>@foreach(['NATIONAL_ID', 'IQAMA', 'PASSPORT'] as $t)<option value="{{ $t }}" @selected($v('id_type') === $t)>{{ __("rroka.doc_type.$t") }}</option>@endforeach</select></div>
            <div class="field"><label>{{ __('رقم الهوية') }}</label><input name="id_number" value="{{ $v('id_number') }}" dir="ltr"></div>
            <div class="field"><label>{{ __('تاريخ الميلاد') }}</label><input type="date" name="birth_date" value="{{ $v('birth_date') }}"></div>
            <div class="field"><label>{{ __('الجنس') }}</label>
                <select name="gender"><option value="">{{ __('— بلا —') }}</option><option value="M" @selected($v('gender') === 'M')>{{ __('ذكر') }}</option><option value="F" @selected($v('gender') === 'F')>{{ __('أنثى') }}</option></select></div>
            <div class="field"><label>{{ __('الجوال الشخصي') }}</label><input name="mobile" value="{{ $v('mobile') }}" dir="ltr"></div>
            <div class="field"><label>{{ __('الآيبان (IBAN)') }}</label><input name="iban" value="{{ $v('iban') }}" dir="ltr" placeholder="SA…"><div class="hint">{{ __('آيبان سعودي: SA ثم 22 خانة.') }}</div></div>
            <div class="field"><label>{{ __('جهة الاتصال في الطوارئ') }}</label><input name="emergency_contact" value="{{ $v('emergency_contact') }}"></div>
            <div class="field"><label>{{ __('هاتف الطوارئ') }}</label><input name="emergency_phone" value="{{ $v('emergency_phone') }}" dir="ltr"></div>
        </div>
        <div class="field"><label>{{ __('العنوان') }}</label><input name="address" value="{{ $v('address') }}"></div>
        <div class="field"><label>{{ __('ملاحظات') }}</label><textarea name="notes">{{ $v('notes') }}</textarea></div>
    </div>
    <div class="actions"><button class="btn">{{ __('حفظ') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
