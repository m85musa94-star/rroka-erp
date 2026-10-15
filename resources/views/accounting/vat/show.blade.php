@extends('layouts.app')
@section('title', $x->return_no)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الإقرار الضريبي'), route('accounting.vat.index')], [$x->return_no, null]]])
@endsection
@section('content')
@php($u = auth()->user())
@php($path = $x->status === 'CANCELLED' ? ['DRAFT', 'CANCELLED'] : ['DRAFT', 'APPROVED'])
@php($frozen = $x->status === 'APPROVED')
@php($sysOut = $frozen ? (float) $x->sys_output_vat : $r['output'])
@php($sysIn = $frozen ? (float) $x->sys_input_vat : $r['input'] + ($x->include_verify ? $r['verify'] : 0))
<div class="rec-bar">
    <div class="actions">
        @include('partials.delete-button', ['type' => 'vat-returns', 'model' => $x])
        @if($x->status === 'DRAFT')
            @if($u->hasPermission('accounting.manage'))<a class="btn ghost sm" href="{{ route('accounting.vat.edit', $x) }}">{{ __('تعديل') }}</a>@endif
            @if($u->hasPermission('accounting.post'))<form method="post" action="{{ route('accounting.vat.approve', $x) }}" class="inline" data-confirm="{{ __('اعتماد الإقرار؟ تُقفل الفترة ضريبيًا ويُسجَّل قيد التسوية، ولا يمكن تعديله بعدها.') }}">@csrf<button class="btn ok sm">{{ __('اعتماد') }}</button></form>@endif
            @if($u->hasPermission('accounting.manage'))<form method="post" action="{{ route('accounting.vat.cancel', $x) }}" class="inline" data-confirm="{{ __('إلغاء المسودة؟') }}">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>@endif
        @endif
        <a class="btn ghost sm" href="{{ route('accounting.vat.report', ['from' => $x->period_start->toDateString(), 'to' => $x->period_end->toDateString()]) }}">{{ __('التقرير والتفاصيل') }}</a>
        @if($x->attachment)<a class="btn ghost sm" href="{{ $x->attachment->url(false) }}" target="_blank" rel="noopener">{{ __('صورة الإقرار') }}</a>@endif
    </div>
    @include('partials.statusbar', ['path' => $path, 'current' => $x->status, 'bad' => ['CANCELLED']])
</div>
<div class="card">
    <h1 class="rec-title">{{ $x->return_no }}</h1>
    <p class="rec-sub">{{ __('إقرار ضريبة القيمة المضافة') }} · {{ __('من :from إلى :to', ['from' => $x->period_start->format('Y-m-d'), 'to' => $x->period_end->format('Y-m-d')]) }}</p>
    <h2>{{ __('المطابقة الثلاثية') }}</h2>
    <div class="table-wrap"><table>
        <tr><th></th><th class="num">{{ __('ضريبة المخرجات') }}</th><th class="num">{{ __('ضريبة المدخلات') }}</th><th class="num">{{ __('الصافي') }}</th></tr>
        <tr><td>{{ $frozen ? __('من المستندات (مجمّدة عند الاعتماد)') : __('من المستندات (الآن)') }}</td><td class="num">{{ number_format($sysOut, 2) }}</td><td class="num">{{ number_format($sysIn, 2) }}</td><td class="num">{{ number_format($sysOut - $sysIn, 2) }}</td></tr>
        <tr><td>{{ __('المقدَّم للهيئة') }}</td>
            <td class="num">{{ $x->filed_output_vat === null ? '—' : number_format($x->filed_output_vat, 2) }}</td><td class="num">{{ $x->filed_input_vat === null ? '—' : number_format($x->filed_input_vat, 2) }}</td>
            <td class="num">{{ $x->filedNet() === null ? '—' : number_format($x->filedNet(), 2) }}</td></tr>
        <tr><td>{{ __('في الدفاتر (المرحَّل للفترة)') }}</td><td class="num">{{ number_format($r['books']['output'], 2) }}</td><td class="num">{{ number_format($r['books']['input'], 2) }}</td><td class="num">{{ number_format($r['books']['output'] - $r['books']['input'], 2) }}</td></tr>
        @if($x->filed_output_vat !== null)
            @php($d1 = round((float) $x->filed_output_vat - $sysOut, 2))
            @php($d2 = round((float) $x->filed_input_vat - $sysIn, 2))
            <tr class="grp"><td>{{ __('الفرق (المقدَّم − المستندات)') }}</td>
                <td class="num">@if($d1 == 0)<span class="ok-text">✓</span>@else<span class="bad-text">{{ number_format($d1, 2) }}</span>@endif</td>
                <td class="num">@if($d2 == 0)<span class="ok-text">✓</span>@else<span class="bad-text">{{ number_format($d2, 2) }}</span>@endif</td><td></td></tr>
        @endif
    </table></div>
    @if($x->include_verify)<p class="hint">{{ __('أُدرجت ضريبة الموردين بلا رقم ضريبي. السبب:') }} {{ $x->verify_reason }}</p>@elseif((float) ($frozen ? $x->sys_verify_vat : $r['verify']) > 0)<p class="hint">{{ __('ضريبة موردين بلا رقم ضريبي لم تُدرج في الخصم:') }} {{ number_format($frozen ? $x->sys_verify_vat : $r['verify'], 2) }}</p>@endif
    @if($x->notes)<p>{{ $x->notes }}</p>@endif
    <dl class="kv" style="margin-top:10px">
        @include('partials.journal-link', ['type' => 'VAT_RETURN', 'id' => $x->id, 'approved' => $frozen])
        <dt>{{ __('أدخله') }}</dt><dd>{{ $names[$x->created_by] ?? '—' }}</dd>
        @if($x->approved_at)<dt>{{ __('اعتمده') }}</dt><dd>{{ $names[$x->approved_by] ?? '—' }} — {{ $x->approved_at->format('Y-m-d H:i') }}</dd>@endif
    </dl>
</div>
@if($frozen)
<div class="card">
    <h2>{{ __('سداد الضريبة للهيئة') }}</h2>
    @if($x->paid_date)
        <dl class="kv">
            <dt>{{ __('التاريخ') }}</dt><dd>{{ $x->paid_date->format('Y-m-d') }}</dd>
            <dt>{{ __('من') }}</dt><dd>{{ $x->paidAccount->name }}</dd>
            <dt>{{ __('المبلغ') }}</dt><dd class="num">{{ number_format($x->paid_amount, 2) }}</dd>
            <dt>{{ __('المرجع') }}</dt><dd>{{ $x->paid_reference ?? '—' }}</dd>
            @include('partials.journal-link', ['type' => 'VAT_PAYMENT', 'id' => $x->id, 'approved' => true])
        </dl>
    @elseif($x->filedNet() <= 0)
        <p class="muted">{{ __('لا مبلغ مستحق للسداد في هذا الإقرار.') }}</p>
    @elseif($u->hasPermission('accounting.post'))
        <form method="post" action="{{ route('accounting.vat.pay', $x) }}" data-confirm="{{ __('تسجيل السداد؟ يُرحَّل للدفاتر ولا يُعدَّل بعدها.') }}">
            @csrf
            <div class="grid g4">
                <div class="field"><label>{{ __('التاريخ *') }}</label><input type="date" name="paid_date" value="{{ old('paid_date', today()->toDateString()) }}" required></div>
                <div class="field"><label>{{ __('دُفع من *') }}</label><select name="paid_account_id" required><option value="">{{ __('— اختر —') }}</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
                <div class="field"><label>{{ __('المبلغ *') }}</label><input name="paid_amount" type="number" step="0.01" min="0.01" value="{{ old('paid_amount', number_format($x->filedNet(), 2, '.', '')) }}" required dir="ltr"></div>
                <div class="field"><label>{{ __('المرجع (رقم سداد)') }}</label><input name="paid_reference" dir="ltr" maxlength="100"></div>
            </div>
            <button class="btn sm">{{ __('تسجيل السداد') }}</button>
        </form>
    @else
        <p class="muted">{{ __('لم يُسدَّد بعد.') }}</p>
    @endif
</div>
@endif
@include('partials.chatter', ['activity' => $activity])
@endsection
