@extends('layouts.print')
@section('title', __('إقرار ضريبة القيمة المضافة'))
@section('content')
<style>
    .fin-report { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
    .fin-report th { text-align: start; font-weight: 600; border-bottom: 1.5px solid var(--accent); padding: 1.6mm 1.5mm; background: var(--soft); }
    .fin-report th.num, .fin-report td.num { text-align: end; direction: ltr; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .fin-report td { padding: 1.3mm 1.5mm; border-bottom: .75px solid #a9a197; }
    .fin-report .fr-heading td { font-weight: 700; padding-top: 3mm; border-bottom: none; }
    .fin-report .fr-total td { font-weight: 700; border-top: 1px solid #2b2620; }
    .fin-report .fr-grand td { font-weight: 700; border-top: 1px solid #2b2620; border-bottom: 3px double #2b2620; }
    .rep-title { font-size: 17pt; font-weight: 700; margin: 0; }
    .rep-sub { color: var(--muted); margin: 1mm 0 5mm; }
    .rep-foot { font-size: 8pt; color: var(--muted); margin-top: 4mm; }
</style>
<h1 class="rep-title">{{ __('إقرار ضريبة القيمة المضافة') }}</h1>
<div class="rep-sub">{{ $company }} · {{ __('من :from إلى :to', ['from' => $from, 'to' => $to]) }} · {{ __('طُبع في :d', ['d' => now()->format('Y-m-d H:i')]) }}</div>
@include('accounting.vat._figures')
@if($r['verify'] > 0)<p class="rep-foot">⚠ {{ __('ضريبة :v على مستندات لموردين بلا رقم ضريبي مسجّل: لا تدخل في الخصم تلقائيًا. أضف الرقم الضريبي للمورد إن وُجد، أو قرّر إدراجها في الإقرار مع ذكر السبب.', ['v' => number_format($r['verify'], 2)]) }}</p>@endif
<p class="rep-foot">{{ __('من المستندات المعتمدة') }}. {{ __('الإقرار يراجعه محاسب مؤهل قبل تقديمه.') }}</p>
@endsection
