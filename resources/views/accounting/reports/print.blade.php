@extends('layouts.print')
@section('title', $def['title'])
@section('content')
@php($periodText = $r['asOf'] ? __('في :d', ['d' => $r['columns'][0]['to'] ?? $o->to]) : __('من :from إلى :to', ['from' => $o->from, 'to' => $o->to]))
<style>
    .fin-report { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
    .fin-report th { text-align: start; font-weight: 600; border-bottom: 1.5px solid var(--accent); padding: 1.6mm 1.5mm; background: var(--soft); }
    .fin-report th.num, .fin-report td.num { text-align: end; direction: ltr; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .fin-report td { padding: 1.3mm 1.5mm; border-bottom: .75px solid #a9a197; }
    .fin-report tr { break-inside: avoid; }
    .fin-report .code { color: var(--muted); margin-inline-end: 2mm; }
    .fin-report .fr-section td, .fin-report .fr-heading td { font-weight: 700; }
    .fin-report .fr-heading td { padding-top: 3mm; border-bottom: none; font-size: 10.5pt; }
    .fin-report .fr-total td { font-weight: 700; border-top: 1px solid var(--ink); }
    .fin-report .fr-grand td { font-weight: 700; border-top: 1px solid var(--ink); border-bottom: 3px double var(--ink); }
    .fin-report .fr-detail td { font-size: 8.5pt; color: var(--muted); }
    .fin-report .fr-note { font-size: 8pt; color: var(--muted); }
    .fin-report .badge { font-size: 7.5pt; border: 1px solid var(--muted); border-radius: 1mm; padding: 0 1mm; }
    .rep-title { font-size: 17pt; font-weight: 700; margin: 0; }
    .rep-sub { color: var(--muted); margin: 1mm 0 5mm; }
    .rep-check { font-size: 9pt; margin: 0 0 3mm; }
    .rep-foot { font-size: 8pt; color: var(--muted); margin-top: 4mm; }
</style>
<h1 class="rep-title">{{ $def['title'] }}</h1>
<div class="rep-sub">{{ $company }} · {{ $periodText }} · {{ __('طُبع في :d', ['d' => now()->format('Y-m-d H:i')]) }}</div>
@foreach($r['checks'] as $c)
    <p class="rep-check">{{ $c['ok'] ? '✓ '.($key === 'balance-sheet' ? __('متوازنة') : __('الميزان متوازن')) : __('غير متوازن — الفرق :d', ['d' => number_format($c['diff'], 2)]) }}</p>
@endforeach
@foreach($r['warnings'] as $w)<p class="rep-check">⚠ {{ $w }}</p>@endforeach
@include('accounting.reports._table', ['links' => false])
<p class="rep-foot">{{ __('من القيود المرحَّلة فقط. السالب بين قوسين. السنة المالية ميلادية (يناير–ديسمبر)، وبداية الدفاتر :d.', ['d' => $o->booksStart ?? '—']) }}</p>
@endsection
