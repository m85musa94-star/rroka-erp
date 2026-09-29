@extends('layouts.app')
@section('title', __('الربط مع دفترة'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الإعدادات'), null], [__('الربط مع دفترة'), null]]])
@endsection
@section('content')
<div class="card">
    <h2>{{ __('حالة الربط') }}</h2>
    <dl class="kv">
        <dt>{{ __('بيانات الربط') }}</dt>
        <dd>{!! $configured ? '<span class="badge b-SUCCESS">'.e(__('مُدخلة')).'</span>' : '<span class="badge b-CANCELLED">'.e(__('غير مُدخلة')).'</span>' !!}</dd>
        <dt>{{ __('النطاق الفرعي') }}</dt><dd dir="ltr" class="ltr-in">{{ $subdomain ? $subdomain.'.daftra.com' : '—' }}</dd>
        <dt>{{ __('إرسال العملاء') }}</dt><dd>{{ $configured ? __('مفعّل') : __('موقوف') }}</dd>
        <dt>{{ __('إرسال عروض الأسعار') }}</dt><dd>{{ $estimateVerified ? __('مفعّل') : __('موقوف حتى التحقق من الحقول') }}</dd>
        <dt>{{ __('التقارير المالية من دفترة') }}</dt><dd>{{ __('لم تُبنَ بعد — تنتظر نتيجة الفحص') }}</dd>
    </dl>
    @unless($configured)
        <p class="hint">{{ __('تُدخل بيانات الربط في منصة الاستضافة ضمن متغيرات البيئة: DAFTRA_SUBDOMAIN و DAFTRA_API_KEY و DAFTRA_TOKEN، ثم يُعاد النشر.') }}</p>
    @endunless
</div>

<div class="card">
    <h2>{{ __('فحص نقاط القراءة') }}</h2>
    <p>{{ __('يقرأ سجلًا واحدًا من كل نوع في حسابك بدفترة، ويعرض أسماء الحقول وأنواعها فقط — لا أسماء عملاء ولا مبالغ. لا يكتب شيئًا في دفترة ولا هنا.') }}</p>
    <p class="hint">{{ __('الغرض: التحقق من الحقول المتاحة قبل بناء التقارير المالية عليها، فلا يُبنى رقم على افتراض.') }}</p>
    @if($configured)
        <form method="post" action="{{ route('daftra.probe') }}">@csrf<button class="btn">{{ __('تشغيل الفحص') }}</button></form>
    @endif
</div>

@if($results)
<div class="card">
    <h2>{{ __('النتيجة') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('البيانات') }}</th><th>{{ __('المسار') }}</th><th>{{ __('النتيجة') }}</th><th class="num">{{ __('عدد الحقول') }}</th></tr>
        @foreach($results as $r)
            <tr>
                <td>{{ __($r['label']) }}</td>
                <td dir="ltr" class="ltr-in">GET {{ $r['path'] }}</td>
                <td>
                    @if($r['ok'])<span class="badge b-SUCCESS">{{ __('متاح') }}</span>
                    @elseif($r['status'] === 404)<span class="badge b-CANCELLED">{{ __('غير موجود') }}</span>
                    @elseif(in_array($r['status'], [401, 403]))<span class="badge b-FAILED">{{ __('مرفوض — صلاحية المفتاح') }}</span>
                    @else<span class="badge b-FAILED">{{ __('خطأ') }}</span>@endif
                    <span dir="ltr">{{ $r['status'] ?? $r['error'] }}</span>
                </td>
                <td class="num">{{ count($r['fields']) }}</td>
            </tr>
        @endforeach
    </table></div>
    <h2>{{ __('انسخ هذا النص وأرسله للمراجعة') }}</h2>
    <textarea readonly rows="16" dir="ltr" style="width:100%;font-family:monospace;font-size:12px" onclick="this.select()">{{ $text }}</textarea>
</div>
@endif
@endsection
