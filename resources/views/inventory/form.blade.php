@extends('layouts.app')
@section('title', $material->exists ? __('تعديل ').$material->code : __('خامة جديدة'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => $material->exists
        ? [[__('الخامات والمخزون'), route('materials.index')], [$material->code, route('materials.show', $material)], [__('تعديل'), null]]
        : [[__('الخامات والمخزون'), route('materials.index')], [__('جديد'), null]]])
@endsection
@section('content')
<form method="post" action="{{ $material->exists ? route('materials.update', $material) : route('materials.store') }}" class="card">
    @csrf
    @if($material->exists) @method('put') @endif
    <div class="grid g2">
        <div class="field"><label>{{ __('الرمز *') }}</label><input name="code" value="{{ old('code', $material->code) }}" required dir="ltr" placeholder="MDF-18"></div>
        <div class="field"><label>{{ __('الاسم *') }}</label><input name="name" value="{{ old('name', $material->name) }}" required placeholder="{{ __('مثال: لوح MDF سماكة 18 مم') }}"></div>
        <div class="field"><label>{{ __('الفئة') }}</label><input name="category" value="{{ old('category', $material->category) }}" list="cats" placeholder="{{ __('مثال: ألواح، إكسسوارات، دهانات') }}">
            <datalist id="cats">@foreach($categories as $c)<option value="{{ $c }}">@endforeach</datalist></div>
        <div class="field"><label>{{ __('وحدة القياس *') }}</label><input name="uom" value="{{ old('uom', $material->uom) }}" required placeholder="{{ __('لوح، حبة، لتر، متر') }}"></div>
    </div>
    <label class="perm-item"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" style="width:auto" @checked(old('is_active', $material->is_active))> {{ __('نشطة (تظهر في قوائم المواد)') }}</label>
    <div class="actions"><button class="btn">{{ __('حفظ') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
