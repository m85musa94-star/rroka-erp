@extends('layouts.app')
@section('title', __('إقرار ضريبة القيمة المضافة'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الإقرار الضريبي'), route('accounting.vat.index')], [__('التقرير'), null]]])
@endsection
@section('content')
<form method="get" class="card inline-form">
    <div class="grid g4">
        <div class="field"><label>{{ __('من') }}</label><input type="date" name="from" value="{{ $from }}" required></div>
        <div class="field"><label>{{ __('إلى') }}</label><input type="date" name="to" value="{{ $to }}" required></div>
        <div class="field"><label>&nbsp;</label><button class="btn">{{ __('عرض') }}</button></div>
        <div class="field"><label>&nbsp;</label><span class="actions">
            <a class="btn ghost sm" href="{{ request()->fullUrlWithQuery(['print' => 1]) }}" target="_blank">{{ __('طباعة') }}</a>
            <a class="btn ghost sm" href="{{ request()->fullUrlWithQuery(['export' => 'xlsx', 'from' => $from, 'to' => $to]) }}">{{ __('Excel') }}</a></span></div>
    </div>
</form>
<div class="card">
    <h1 class="rec-title">{{ __('إقرار ضريبة القيمة المضافة') }}</h1>
    <p class="rec-sub">{{ __('من :from إلى :to', ['from' => $from, 'to' => $to]) }} · {{ __('من المستندات المعتمدة') }}</p>
    @include('accounting.vat._figures')
    @if($r['verify'] > 0)<div class="alert warn" style="margin-top:12px">{{ __('ضريبة :v على مستندات لموردين بلا رقم ضريبي مسجّل: لا تدخل في الخصم تلقائيًا. أضف الرقم الضريبي للمورد إن وُجد، أو قرّر إدراجها في الإقرار مع ذكر السبب.', ['v' => number_format($r['verify'], 2)]) }}</div>@endif
    @if($r['flagged']->isNotEmpty())<div class="alert warn">{{ __(':n مستند بنسبة ضريبة غير 15% أو صفر — راجعها قبل التقديم (تظهر بعلامة ⚠ في التفاصيل).', ['n' => $r['flagged']->count()]) }}</div>@endif
</div>
<div class="card">
    <h2>{{ __('المطابقة مع الدفاتر') }}</h2>
    <div class="table-wrap"><table>
        <tr><th></th><th class="num">{{ __('من المستندات') }}</th><th class="num">{{ __('في الدفاتر (المرحَّل)') }}</th><th class="num">{{ __('الفرق') }}</th></tr>
        @foreach(['output' => __('ضريبة المخرجات'), 'input' => __('ضريبة المدخلات (القابلة للخصم + تحتاج تحقق)')] as $k => $label)
            @php($doc = $k === 'output' ? $r['output'] : $r['input'] + $r['verify'])
            @php($diff = round($doc - $r['books'][$k], 2))
            <tr><td>{{ $label }}</td><td class="num">{{ number_format($doc, 2) }}</td><td class="num">{{ number_format($r['books'][$k], 2) }}</td>
                <td class="num">@if($diff == 0)<span class="ok-text">✓</span>@else<span class="bad-text">{{ number_format($diff, 2) }}</span>@endif</td></tr>
        @endforeach
    </table></div>
    <p class="hint">{{ __('الفرق يعني مستندًا معتمدًا لم يُرحَّل، أو قيدًا يدويًا على حساب الضريبة. راجع «مستندات لم تُرحَّل».') }}</p>
</div>
<div class="card">
    <h2>{{ __('التفاصيل') }}</h2>
    @foreach($docs as $k => $list)
        @continue($list->isEmpty())
        <details><summary><strong>{{ __("rroka.vat_bucket.$k") }}</strong> <span class="muted">({{ $list->count() }})</span></summary>
        <div class="table-wrap"><table>
            <tr><th>{{ __('المستند') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('الطرف') }}</th><th>{{ __('الرقم الضريبي') }}</th><th class="num">{{ __('المبلغ قبل الضريبة') }}</th><th class="num">{{ __('الضريبة') }}</th></tr>
            @foreach($list as $d)
                <tr><td>@if(! $d->rate_ok)⚠ @endif<a href="{{ \App\Models\JournalEntry::documentUrl($d->source_type, $d->source_id) }}">{{ $d->doc_no }}</a></td><td class="num">{{ $d->doc_date }}</td>
                    <td>{{ $d->party }}</td><td dir="ltr">{{ $d->party_vat_no ?? '—' }}</td><td class="num">{{ number_format($d->amount, 2) }}</td><td class="num">{{ number_format($d->vat, 2) }}</td></tr>
            @endforeach
        </table></div></details>
    @endforeach
</div>
<p class="hint">{{ __('أرقام الخانات في نموذج الهيئة لا تُعرض هنا لأنها لم تُطابَق بعد مع النموذج الرسمي الحالي؛ انقل كل بند إلى خانته المقابلة عند التقديم. الإقرار يراجعه محاسب مؤهل قبل تقديمه.') }}</p>
@endsection
