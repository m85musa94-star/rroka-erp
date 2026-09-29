@extends('layouts.app')
@section('title', __('تصميم جديد'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('التصاميم'), route('designs.index')], [__('جديد'), null]]])
@endsection
@section('content')
<form method="post" action="{{ route('designs.store') }}" class="card">
    @csrf
    <div class="grid g2">
        <div class="field"><label>{{ __('المشروع *') }}</label>
            <select name="project_id" required>
                <option value="">{{ __('— اختر —') }}</option>
                @foreach($projects as $p)
                    <option value="{{ $p->id }}" @selected((string) old('project_id', $selected) === (string) $p->id)>{{ $p->project_no }} — {{ $p->title }}</option>
                @endforeach
            </select></div>
        <div class="field"><label>{{ __('عنوان التصميم *') }}</label><input name="title" value="{{ old('title') }}" required placeholder="{{ __('مثال: مطبخ — الجدار الرئيسي') }}"></div>
        <div class="field"><label>{{ __('رابط ملف التصميم') }}</label><input name="file_url" type="url" value="{{ old('file_url') }}" dir="ltr" placeholder="https://"><div class="hint">{{ __('رابط الملف في التخزين السحابي (رسم، PDF، ملف تصميم).') }}</div></div>
        <div class="field"><label>{{ __('ملاحظات النسخة') }}</label><input name="change_notes" value="{{ old('change_notes') }}"></div>
    </div>
    <div class="actions"><button class="btn">{{ __('إنشاء') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
