@extends('layouts.app')
@section('title', $role->exists ? __('تعديل الدور: ').$role->name_ar : __('دور جديد'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الأدوار والصلاحيات'), route('roles.index')], [$role->exists ? $role->name_ar : __('جديد'), null]]])
@endsection
@section('content')
@php($chosen = array_map('intval', old('permissions', $selected)))
@php($isAdmin = $role->code === 'system_admin')
<form method="post" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}">
    @csrf
    @if($role->exists) @method('put') @endif
    <div class="card">
        <div class="grid g2">
            <div class="field"><label>{{ __('اسم الدور *') }}</label><input name="name_ar" value="{{ old('name_ar', $role->name_ar) }}" required placeholder="{{ __('مثال: مسؤول المبيعات، مشرف الإنتاج، أمين المستودع') }}"></div>
            <div class="field"><label>{{ __('الوصف') }}</label><input name="description" value="{{ old('description', $role->description) }}" placeholder="{{ __('اختياري: ما مسؤوليات هذا الدور؟') }}"></div>
        </div>
    </div>

    @if($isAdmin)
        <p class="alert warn">{{ __('دور مدير النظام يملك كل الصلاحيات دائمًا، ولا تُعدَّل صلاحياته.') }}</p>
    @else
    <div class="card">
        <h2>{{ __('الصلاحيات') }} <span class="muted" style="font-weight:400;font-size:13px">{{ __('— حدّد ما يستطيع صاحب هذا الدور فعله') }}</span></h2>
        <div class="perm-groups">
            @foreach($groups as $g)
                <fieldset class="perm-group">
                    <legend>
                        <label class="perm-all"><input type="checkbox" class="js-all"> {{ $g['label'] }}</label>
                    </legend>
                    @foreach($g['items'] as $p)
                        <label class="perm-item">
                            <input type="checkbox" name="permissions[]" value="{{ $p->id }}" @checked(in_array($p->id, $chosen))>
                            {{ __($p->description) }}
                        </label>
                    @endforeach
                </fieldset>
            @endforeach
        </div>
    </div>
    @endif
    <div class="actions"><button class="btn">{{ __('حفظ') }}</button><a class="btn ghost" href="{{ route('roles.index') }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
@push('scripts')
<script>
document.querySelectorAll('.perm-group').forEach(g => {
    const all = g.querySelector('.js-all'), items = [...g.querySelectorAll('input[name="permissions[]"]')];
    const sync = () => { const n = items.filter(i => i.checked).length; all.checked = n === items.length; all.indeterminate = n > 0 && n < items.length; };
    all.addEventListener('change', () => { items.forEach(i => i.checked = all.checked); sync(); });
    items.forEach(i => i.addEventListener('change', sync)); sync();
});
</script>
@endpush
