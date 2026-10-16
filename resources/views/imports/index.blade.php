@extends('layouts.app')
@section('title', __('استيراد البيانات'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('استيراد البيانات'), null]]])
@endsection
@section('content')
<div class="card">
    <p style="margin:0">{{ __('لكل شاشة إدخال قالب Excel: نزّله، املأه، ثم ارفعه. يُفحص كل سطر بقواعد الإدخال اليدوي نفسها قبل حفظ أي شيء، والاستيراد يتم للملف كله أو لا شيء. المستندات تُنشأ مسودات للمراجعة والاعتماد.') }}</p>
</div>
@if(! $sections)
    <div class="card empty-state"><strong>{{ __('لا توجد قوالب متاحة') }}</strong>{{ __('ليست لديك صلاحية الإدخال في أي شاشة.') }}</div>
@else
<div class="imp-sections">
    @foreach(\App\Imports\ImportRegistry::SECTIONS as $section => $title)
        @continue(empty($sections[$section]))
        <div class="card">
            <h2>{{ __($title) }}</h2>
            <ul class="imp-list">
                @foreach($sections[$section] as $key => $def)
                    <li>
                        <a href="{{ route('imports.show', $key) }}">{{ __($def['label']) }}</a>
                        <span class="acts"><a href="{{ route('imports.template', $key) }}">{{ __('القالب') }}</a> · <a href="{{ route('imports.show', $key) }}">{{ __('رفع') }}</a></span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</div>
@endif

@if($batches->isNotEmpty())
<h2 class="section-title">{{ __('آخر الملفات المرفوعة') }}</h2>
@include('imports._batches', ['batches' => $batches, 'withType' => true])
@endif
@endsection
