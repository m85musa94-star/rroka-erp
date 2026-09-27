@extends('layouts.app')
@section('title', $user->exists ? 'تعديل '.$user->name : 'مستخدم جديد')
@section('content')
@php($chosen = old('roles', $user->exists ? $user->roles->pluck('id')->all() : []))
<form method="post" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="card">
    @csrf
    @if($user->exists) @method('put') @endif
    <div class="grid g2">
        <div class="field"><label>الاسم *</label><input name="name" value="{{ old('name', $user->name) }}" required></div>
        <div class="field"><label>البريد الإلكتروني (اسم الدخول) *</label><input name="email" type="email" value="{{ old('email', $user->email) }}" required dir="ltr"></div>
        <div class="field"><label>كلمة المرور {{ $user->exists ? '(اتركها فارغة لعدم التغيير)' : '*' }}</label><input name="password" type="password" {{ $user->exists ? '' : 'required' }} minlength="10" dir="ltr" autocomplete="new-password"><div class="hint">١٠ أحرف على الأقل.</div></div>
        <div class="field"><label>تأكيد كلمة المرور</label><input name="password_confirmation" type="password" dir="ltr" autocomplete="new-password"></div>
    </div>
    @if($user->exists)
        <div class="field"><label style="display:flex;gap:6px;align-items:center;color:var(--ink)">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" style="width:auto" @checked(old('is_active', $user->is_active))> الحساب نشط
        </label></div>
    @endif
    <div class="field"><label>الأدوار</label>
        @forelse($roles as $r)
            <label style="display:flex;gap:6px;align-items:center;color:var(--ink)">
                <input type="checkbox" name="roles[]" value="{{ $r->id }}" style="width:auto" @checked(in_array($r->id, $chosen))> {{ $r->name_ar }}
            </label>
        @empty
            <p class="muted">لا توجد أدوار. أنشئ الأدوار من صفحة "الأدوار والصلاحيات".</p>
        @endforelse
    </div>
    <div class="actions"><button class="btn">حفظ</button><a class="btn ghost" href="{{ route('users.index') }}">إلغاء</a></div>
</form>
@endsection
