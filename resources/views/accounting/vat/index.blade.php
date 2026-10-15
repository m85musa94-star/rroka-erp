@extends('layouts.app')
@section('title', __('الإقرار الضريبي'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الإقرار الضريبي'), null]]])
@endsection
@section('content')
@php($u = auth()->user())
<div class="card">
    <div class="section-title"><h2>{{ __('الفترات الضريبية') }}</h2>
        <a class="btn ghost sm" href="{{ route('accounting.vat.report') }}">{{ __('تقرير لفترة مخصصة') }}</a></div>
    @if(! $settings->vat_period)
        <div class="alert warn">{{ __('اختر دورية الإقرار كما هي في حسابك على بوابة الهيئة (شهرية أو ربعية) لتظهر الفترات.') }}</div>
    @endif
    @if($u->hasPermission('accounting.close'))
    <form method="post" action="{{ route('accounting.vat.settings') }}" class="inline">
        @csrf @method('put')
        <label class="muted">{{ __('دورية الإقرار') }}</label>
        <select name="vat_period">@foreach(['MONTHLY' => __('شهرية'), 'QUARTERLY' => __('ربعية')] as $k => $l)<option value="{{ $k }}" @selected($settings->vat_period === $k)>{{ $l }}</option>@endforeach</select>
        <button class="btn ghost sm">{{ __('حفظ') }}</button>
    </form>
    @endif
    <p class="hint">{{ __('مقفلة ضريبيًا حتى:') }} <strong>{{ $settings->vat_locked_until ?? __('لا شيء') }}</strong> — {{ __('لا يُعتمد مستند بضريبة بتاريخ داخل فترة قُدِّم إقرارها.') }}</p>
    @if($periods)
    <div class="table-wrap"><table>
        <tr><th>{{ __('الفترة') }}</th><th>{{ __('من') }}</th><th>{{ __('إلى') }}</th><th>{{ __('الإقرار') }}</th><th></th></tr>
        @foreach($periods as $p)
            <tr><td><bdi dir="ltr">{{ $p['label'] }}</bdi></td><td class="num">{{ $p['from'] }}</td><td class="num">{{ $p['to'] }}</td>
                <td>@if($p['return'])<a href="{{ route('accounting.vat.show', $p['return']->id) }}">{{ $p['return']->return_no }}</a> @include('partials.badge', ['s' => $p['return']->status])
                    @if($p['return']->status === 'APPROVED')<span class="badge {{ $p['return']->paid_date ? 'b-APPROVED' : 'b-ON_HOLD' }}">{{ $p['return']->paid_date ? __('مسدَّد') : __('غير مسدَّد') }}</span>@endif
                    @else<span class="muted">{{ $p['ended'] ? __('لم يُسجَّل') : __('الفترة جارية') }}</span>@endif</td>
                <td><a class="btn ghost sm" href="{{ route('accounting.vat.report', ['from' => $p['from'], 'to' => $p['to']]) }}">{{ __('التقرير') }}</a>
                    @if(! $p['return'] && $u->hasPermission('accounting.manage'))<a class="btn sm" href="{{ route('accounting.vat.create', ['from' => $p['from'], 'to' => $p['to']]) }}">{{ __('تسجيل الإقرار المقدَّم') }}</a>@endif</td></tr>
        @endforeach
    </table></div>
    @endif
</div>
@if($returns->isNotEmpty())
<div class="card" style="padding:0">
    <h2 style="padding:16px 16px 0">{{ __('الإقرارات المسجلة') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الرقم') }}</th><th>{{ __('الفترة') }}</th><th class="num">{{ __('المخرجات المقدَّمة') }}</th><th class="num">{{ __('المدخلات المقدَّمة') }}</th><th class="num">{{ __('الصافي') }}</th><th>{{ __('الحالة') }}</th></tr>
        @foreach($returns as $x)
            <tr class="row-link" onclick="location='{{ route('accounting.vat.show', $x) }}'"><td><a href="{{ route('accounting.vat.show', $x) }}">{{ $x->return_no }}</a></td>
                <td class="num">{{ $x->period_start->format('Y-m-d') }} → {{ $x->period_end->format('Y-m-d') }}</td>
                <td class="num">{{ $x->filed_output_vat === null ? '—' : number_format($x->filed_output_vat, 2) }}</td><td class="num">{{ $x->filed_input_vat === null ? '—' : number_format($x->filed_input_vat, 2) }}</td>
                <td class="num">{{ $x->filedNet() === null ? '—' : number_format($x->filedNet(), 2) }}</td><td>@include('partials.badge', ['s' => $x->status])</td></tr>
        @endforeach
    </table></div>
</div>
@endif
@if($u->hasPermission('accounting.close') && $settings->vat_locked_until)
<div class="card">
    <h2>{{ __('إعادة فتح فترة ضريبية') }}</h2>
    <p class="hint">{{ __('للحالات الاستثنائية فقط (مستند متأخر يخص فترة قُدِّم إقرارها). السبب يُسجَّل، ويلزم بعدها تصحيح الإقرار لدى الهيئة.') }}</p>
    <form method="post" action="{{ route('accounting.vat.reopen') }}" data-confirm="{{ __('إعادة فتح الفترة؟') }}">
        @csrf
        <div class="grid g3">
            <div class="field"><label>{{ __('تبقى مقفلة حتى') }}</label><input type="date" name="until"><div class="hint">{{ __('اتركه فارغًا لفتح كل الفترات.') }}</div></div>
            <div class="field" style="grid-column: span 2"><label>{{ __('السبب *') }}</label><input name="reason" required maxlength="500"></div>
        </div>
        <button class="btn ghost sm">{{ __('إعادة الفتح') }}</button>
    </form>
    @if($events->isNotEmpty())
        <div class="table-wrap" style="margin-top:10px"><table>
            <tr><th>{{ __('الوقت') }}</th><th>{{ __('من') }}</th><th>{{ __('إلى') }}</th><th>{{ __('السبب') }}</th><th>{{ __('بواسطة') }}</th></tr>
            @foreach($events as $e)<tr><td class="num">{{ \Illuminate\Support\Carbon::parse($e->created_at)->format('Y-m-d H:i') }}</td><td class="num">{{ $e->locked_before ?? '—' }}</td><td class="num">{{ $e->locked_after ?? '—' }}</td><td>{{ $e->reason }}</td><td>{{ $e->name }}</td></tr>@endforeach
        </table></div>
    @endif
</div>
@endif
@endsection
