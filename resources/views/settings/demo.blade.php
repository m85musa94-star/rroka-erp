@extends('layouts.app')
@section('title', __('البيانات التجريبية'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الإعدادات'), null], [__('البيانات التجريبية'), null]]])
@endsection
@section('content')
<div class="card">
    <h2>{{ __('الغرض') }}</h2>
    <p>{{ __('نماذج افتراضية على كل شاشة للاطلاع والتدريب: عملاء، عروض أسعار، مشاريع، تصاميم، أوامر تصنيع، مخزون، موردون، مشتريات، مصروفات، موظفون، حضور وإجازات.') }}</p>
    <ul>
        <li>{{ __('كل اسم يبدأ بكلمة «تجريبي»، والأرقام افتراضية لا تمثل أسعارًا حقيقية.') }}</li>
        <li>{{ __('نسبة المصروفات غير المباشرة لا تُدخل، لأنها إعداد عام؛ فيظهر الربح «—» مع سبب النقص.') }}</li>
        <li>{{ __('لا يُرسل أي سجل تجريبي إلى دفترة.') }}</li>
        <li>{{ __('احذف البيانات التجريبية قبل بدء العمل الحقيقي. الأرقام التسلسلية التي استُهلكت (مثل أرقام العروض والمشاريع) لا تُعاد.') }}</li>
        <li>{{ __('إذا رُبط سجل حقيقي بسجل تجريبي يُرفض الحذف كله حتى يُزال الربط، فلا يُحذف شيء حقيقي.') }}</li>
    </ul>
</div>

<div class="card">
    <h2>{{ __('الحالة') }}</h2>
    @if($summary)
        <p><span class="badge b-SENT">{{ __('محمّلة') }}</span> {{ __(':n سجلًا تجريبيًا.', ['n' => array_sum($summary)]) }}</p>
        <div class="table-wrap"><table>
            <tr><th>{{ __('البيانات') }}</th><th class="num">{{ __('العدد') }}</th></tr>
            @foreach(\App\Http\Controllers\Web\DemoDataController::LABELS as $table => $label)
                @isset($summary[$table])
                    <tr><td>{{ __($label) }}</td><td class="num">{{ $summary[$table] }}</td></tr>
                @endisset
            @endforeach
        </table></div>
        @if($blockers)
            <div class="alert warn">
                <strong>{{ __('سجلات غير تجريبية مرتبطة بالبيانات التجريبية') }}</strong> — {{ __('هي سبب رفض الحذف. راجعها؛ إن كانت أُنشئت أثناء التجربة فاحذفها مع البيانات التجريبية.') }}
                <ul style="margin:6px 0 0">
                    @foreach($blockers as $table => $ids)
                        <li>{{ isset(\App\Http\Controllers\Web\DemoDataController::LABELS[$table]) ? __(\App\Http\Controllers\Web\DemoDataController::LABELS[$table]) : $table }}:
                            {{ count($ids) }} — <bdi dir="ltr">#{{ implode(', #', array_slice($ids, 0, 20)) }}{{ count($ids) > 20 ? ' …' : '' }}</bdi></li>
                    @endforeach
                </ul>
            </div>
            <form method="post" action="{{ route('demo.destroy') }}" data-confirm="{{ __('حذف البيانات التجريبية مع السجلات المرتبطة بها المذكورة أعلاه؟ لا يمكن التراجع.') }}">@csrf @method('DELETE')
                <input type="hidden" name="with_linked" value="1">
                <button class="btn bad">{{ __('حذف البيانات التجريبية مع السجلات المرتبطة') }}</button>
            </form>
        @else
        <form method="post" action="{{ route('demo.destroy') }}" data-confirm="{{ __('حذف كل البيانات التجريبية؟ لا يمكن التراجع.') }}">@csrf @method('DELETE')
            <button class="btn bad">{{ __('حذف البيانات التجريبية') }}</button>
        </form>
        @endif
    @else
        <p><span class="badge b-CANCELLED">{{ __('غير محمّلة') }}</span></p>
        <form method="post" action="{{ route('demo.store') }}" data-confirm="{{ __('تحميل البيانات التجريبية؟') }}">@csrf
            <button class="btn">{{ __('تحميل البيانات التجريبية') }}</button>
        </form>
    @endif
</div>
@endsection
