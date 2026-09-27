@extends('layouts.app')
@section('title', $role->exists ? 'تعديل الدور' : 'دور جديد')
@section('content')
@php($chosen = old('permissions', $selected))
<form method="post" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}" class="card">
    @csrf
    @if($role->exists) @method('put') @endif
    <div class="grid g3">
        <div class="field"><label>اسم الدور *</label><input name="name_ar" value="{{ old('name_ar', $role->name_ar) }}" required placeholder="مثال: مسؤول المبيعات"></div>
        <div class="field"><label>الرمز (إنجليزي) *</label><input name="code" value="{{ old('code', $role->code) }}" required dir="ltr" placeholder="sales_officer" pattern="[a-z][a-z0-9_]*"></div>
        <div class="field"><label>الوصف</label><input name="description" value="{{ old('description', $role->description) }}"></div>
    </div>
    @if($role->code === 'system_admin')
        <p class="alert warn">دور مدير النظام يملك كل الصلاحيات دائمًا.</p>
    @else
    <div class="field"><label>الصلاحيات</label>
        <div class="grid g3">
        @foreach($permissions as $p)
            <label style="display:flex;gap:6px;align-items:center;color:var(--ink)">
                <input type="checkbox" name="permissions[]" value="{{ $p->id }}" style="width:auto" @checked(in_array($p->id, $chosen))> {{ $p->description }}
            </label>
        @endforeach
        </div>
    </div>
    @endif
    <div class="actions"><button class="btn">حفظ</button><a class="btn ghost" href="{{ route('roles.index') }}">إلغاء</a></div>
</form>
@endsection
