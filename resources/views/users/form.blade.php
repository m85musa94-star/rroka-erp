@extends('layouts.app')
@section('title', $user->exists ? __('تعديل ').$user->name : __('مستخدم جديد'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('المستخدمون'), route('users.index')], [$user->exists ? $user->name : __('جديد'), null]]])
@endsection
@section('content')
@php($chosen = old('roles', $user->exists ? $user->roles->pluck('id')->all() : []))
<form method="post" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="card">
    @csrf
    @if($user->exists) @method('put') @endif
    <div class="grid g2">
        <div class="field"><label>{{ __('الاسم *') }}</label><input name="name" value="{{ old('name', $user->name) }}" required></div>
        <div class="field"><label>{{ __('البريد الإلكتروني (اسم الدخول) *') }}</label><input name="email" type="email" value="{{ old('email', $user->email) }}" required dir="ltr"></div>
        <div class="field"><label>{{ __('كلمة المرور') }} {{ $user->exists ? __('(اتركها فارغة لعدم التغيير)') : '*' }}</label><input name="password" type="password" {{ $user->exists ? '' : 'required' }} minlength="10" dir="ltr" autocomplete="new-password"><div class="hint">{{ __('١٠ أحرف على الأقل.') }}</div></div>
        <div class="field"><label>{{ __('تأكيد كلمة المرور') }}</label><input name="password_confirmation" type="password" dir="ltr" autocomplete="new-password"></div>
    </div>
    @if($user->exists)
        <div class="field"><label style="display:flex;gap:6px;align-items:center;color:var(--ink)">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" style="width:auto" @checked(old('is_active', $user->is_active))> {{ __('الحساب نشط') }}
        </label></div>
    @endif
    <div class="field" style="max-width:320px"><label>{{ __('مظهر الألوان') }}</label>
        <select name="theme">@foreach(\App\Support\Theme::MODES as $m)<option value="{{ $m }}" @selected(old('theme', $user->theme ?? 'system') === $m)>{{ __(['system' => 'تلقائي (حسب الجهاز)', 'light' => 'فاتح', 'dark' => 'داكن'][$m]) }}</option>@endforeach</select>
        <div class="hint">{{ __('يستطيع المستخدم تغييره بنفسه في أي وقت من زر «المظهر» أعلى الصفحة.') }}</div></div>
    <div class="field"><label>{{ __('الأدوار') }}</label>
        @forelse($roles as $r)
            <label style="display:flex;gap:6px;align-items:center;color:var(--ink)">
                <input type="checkbox" name="roles[]" value="{{ $r->id }}" style="width:auto" @checked(in_array($r->id, $chosen))> {{ __($r->name_ar) }}
            </label>
        @empty
            <p class="muted">{{ __('لا توجد أدوار بعد.') }} <a href="{{ route('roles.create') }}">{{ __('أنشئ دورًا أولًا') }}</a> {{ __('ثم ارجع إلى هنا.') }}</p>
        @endforelse
    </div>
    <div class="actions"><button class="btn">{{ __('حفظ') }}</button><a class="btn ghost" href="{{ route('users.index') }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
