@extends('layouts.app')
@section('title', __($def['label']))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('استيراد البيانات'), route('imports.index')], [__($def['label']), null]]])
@endsection
@section('content')
@php($types = ['text' => 'نص', 'number' => 'رقم', 'date' => 'تاريخ', 'datetime' => 'تاريخ ووقت', 'bool' => 'نعم / لا', 'enum' => 'اختيار من قائمة', 'lookup' => 'من بيانات المنظومة'])
<div class="grid g2">
    <div class="card">
        <h2 class="rec-title" style="font-size:18px">{{ __('١. نزّل القالب واملأه') }}</h2>
        <p>{{ __('القالب فيه ثلاث أوراق: «البيانات» تملؤها، و«الشرح» لكل عمود، و«القوائم» بالقيم الحالية في المنظومة (عملاء، موردون، حسابات…) تظهر كقوائم منسدلة.') }}</p>
        <a class="btn" href="{{ route('imports.template', $type) }}">⇩ {{ __('تنزيل القالب') }}</a>
        @if(! empty($def['note']))<p class="alert info" style="margin-top:12px">{{ __($def['note']) }}</p>@endif
    </div>
    <div class="card">
        <h2 class="rec-title" style="font-size:18px">{{ __('٢. ارفع الملف المعبأ') }}</h2>
        <p>{{ __('يُفحص كل سطر بقواعد الإدخال اليدوي نفسها ولا يُحفظ شيء؛ ترى النتيجة ثم تقرر الاستيراد.') }}</p>
        <form method="post" action="{{ route('imports.upload', $type) }}" enctype="multipart/form-data" class="imp-upload">
            @csrf
            <input type="file" name="file" accept=".xlsx,.csv" required>
            <button class="btn">{{ __('فحص الملف') }}</button>
        </form>
        <p class="hint">{{ __('xlsx أو CSV، حتى 5 ميجابايت و:n سطر.', ['n' => \App\Imports\Importer::MAX_ROWS]) }}</p>
    </div>
</div>

<h2 class="section-title">{{ __('أعمدة القالب') }}</h2>
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('العمود') }}</th><th>{{ __('مطلوب') }}</th><th>{{ __('النوع') }}</th><th>{{ __('القيم المقبولة والملاحظات') }}</th></tr>
    @foreach($columns as $c)
        <tr>
            <td>{{ __($c['label']) }}@if(! empty($c['line'])) <span class="hint">({{ __('سطر') }})</span>@endif</td>
            <td>{{ $c['required'] ? __('نعم') : '' }}</td>
            <td>{{ __($types[$c['type']]) }}</td>
            <td class="imp-msg">
                @if($c['type'] === 'enum'){{ implode(\App\Imports\Importer::sep(), array_map(fn ($l) => __($l), $c['options'])) }}@endif
                @if(! empty($c['extra'])){{ implode(\App\Imports\Importer::sep(), array_map(fn ($l) => __($l), $c['extra'])) }} — @endif
                @if($c['type'] === 'lookup'){{ __('الرقم أو الرمز أو الاسم كما في المنظومة') }}@endif
                @if($c['help']) — {{ __($c['help']) }}@endif
            </td>
        </tr>
    @endforeach
</table></div></div>

@if($batches->isNotEmpty())
<h2 class="section-title">{{ __('الملفات المرفوعة لهذه الشاشة') }}</h2>
@include('imports._batches', ['batches' => $batches])
@endif
@endsection
