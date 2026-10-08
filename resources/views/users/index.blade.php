@extends('layouts.app')
@section('title', __('المستخدمون'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الإعدادات'), null], [__('المستخدمون'), null]]])
@endsection
@section('content')
<form method="post" action="{{ route('users.theme') }}" class="card inline-form" data-confirm="{{ __('تطبيق هذا المظهر على كل المستخدمين؟ يستطيع كل مستخدم تغييره لنفسه بعدها.') }}">@csrf
    <strong>{{ __('مظهر الألوان لكل المستخدمين') }}</strong>
    <select name="theme">@foreach(\App\Support\Theme::MODES as $m)<option value="{{ $m }}">{{ __(['system' => 'تلقائي (حسب الجهاز)', 'light' => 'فاتح', 'dark' => 'داكن'][$m]) }}</option>@endforeach</select>
    <button class="btn ghost sm">{{ __('تطبيق على الجميع') }}</button>
    <span class="hint">{{ __('كل مستخدم يستطيع تغييره لنفسه من زر «المظهر» أعلى الصفحة.') }}</span>
</form>
<div class="card">
    <div class="actions" style="justify-content:space-between;margin-bottom:12px"><p class="muted" style="margin:0">{{ __('كل مستخدم يدخل ببريده وكلمة مروره، ويرى ما تسمح به أدواره فقط.') }}</p><a class="btn" href="{{ route('users.create') }}">{{ __('+ مستخدم جديد') }}</a></div>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الاسم') }}</th><th>{{ __('البريد') }}</th><th>{{ __('الأدوار') }}</th><th>{{ __('الحالة') }}</th><th></th></tr>
        @foreach($users as $usr)
            <tr>
                <td>{{ $usr->name }}</td><td dir="ltr" class="ltr-in">{{ $usr->email }}</td>
                <td>{{ $usr->roles->pluck('name_ar')->map(fn ($n) => __($n))->join(__('، ')) ?: '—' }}</td>
                <td>{!! $usr->is_active ? __('<span class="badge b-ACTIVE">نشط</span>') : __('<span class="badge b-CANCELLED">موقوف</span>') !!}</td>
                <td><a class="btn ghost sm" href="{{ route('users.edit', $usr) }}">{{ __('تعديل') }}</a></td>
            </tr>
        @endforeach
    </table></div>
</div>
@endsection
