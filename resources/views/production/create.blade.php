@extends('layouts.app')
@section('title', __('أمر تصنيع جديد'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('أوامر التصنيع'), route('production.index')], [__('جديد'), null]]])
@endsection
@section('content')
@if($versions->isEmpty())
    <div class="card empty-state"><strong>{{ __('لا توجد نسخ تصميم مُصدرة للإنتاج') }}</strong>{{ __('أمر التصنيع لا يُنشأ إلا على نسخة تصميم وافق عليها العميل وأُصدرت للإنتاج، في مشروع نشط.') }}</div>
@else
<form method="post" action="{{ route('production.store') }}" class="card">
    @csrf
    <div class="grid g2">
        <div class="field"><label>{{ __('نسخة التصميم المُصدرة *') }}</label>
            <select name="design_version_id" required>
                @foreach($versions as $v)
                    <option value="{{ $v->id }}" @selected((string) old('design_version_id', $selected) === (string) $v->id)>{{ $v->design->project->project_no }} — {{ $v->design->title }} v{{ $v->version_no }}</option>
                @endforeach
            </select>
            <div class="hint">{{ __('المشروع يُؤخذ من التصميم، والمكونات من قائمة مواد النسخة.') }}</div></div>
        <div class="field"><label>{{ __('ملاحظات') }}</label><input name="notes" value="{{ old('notes') }}"></div>
        <div class="field"><label>{{ __('البدء المخطط') }}</label><input type="date" name="planned_start" value="{{ old('planned_start', now()->format('Y-m-d')) }}"></div>
        <div class="field"><label>{{ __('الانتهاء المخطط') }}</label><input type="date" name="planned_end" value="{{ old('planned_end') }}"></div>
    </div>
    <div class="actions"><button class="btn">{{ __('إنشاء') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endif
@endsection
