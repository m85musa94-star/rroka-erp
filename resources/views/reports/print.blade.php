@extends('layouts.print')
@section('title', $report->title())
@section('content')
@php($m = $measures[$measure])
@php($fmt = fn ($v) => \App\Reports\Report::format($v, $m['format']))
<style>
    .pv { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
    .pv th { background: var(--soft); font-weight: 600; text-align: start; padding: 1.8mm 1.5mm; border-bottom: 1.5px solid var(--accent); }
    .pv td { padding: 1.4mm 1.5mm; border-bottom: .6px solid #c9c2b8; }
    .pv .num { text-align: end; direction: ltr; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .pv tfoot th { border-top: 1px solid var(--ink); border-bottom: 3px double var(--ink); background: none; }
    .pv tr { break-inside: avoid; }
    .rep-title { font-size: 17pt; font-weight: 700; margin: 0; }
    .rep-sub { color: var(--muted); margin: 1mm 0 5mm; }
</style>
<h1 class="rep-title">{{ $report->title() }}</h1>
<div class="rep-sub">{{ __('إر روكا للأثاث') }} · {{ $m['label'] }} · {{ $report->dateLabel() }}: {{ $range ?? __('كل الفترات') }} · {{ __('طُبع في :d', ['d' => now()->format('Y-m-d H:i')]) }}</div>
<table class="pv">
    <thead><tr><th>{{ $dims[$row]['label'] }}@if($col) / {{ $dims[$col]['label'] }}@endif</th>@foreach($pivot['cols'] as $c)<th class="num">{{ $c['label'] }}</th>@endforeach<th class="num">{{ __('الإجمالي') }}</th></tr></thead>
    <tbody>
    @foreach($pivot['rows'] as $r)
        <tr><td>{{ $r['label'] }}</td>@foreach($pivot['cols'] as $c)<td class="num">{{ $fmt($pivot['cells'][$r['key']][$c['key']] ?? null) }}</td>@endforeach<td class="num"><strong>{{ $fmt($pivot['rowTotals'][$r['key']] ?? null) }}</strong></td></tr>
    @endforeach
    </tbody>
    <tfoot><tr><th>{{ __('الإجمالي') }}</th>@foreach($pivot['cols'] as $c)<th class="num">{{ $fmt($pivot['colTotals'][$c['key']] ?? null) }}</th>@endforeach<th class="num">{{ $fmt($pivot['grand']) }}</th></tr></tfoot>
</table>
@endsection
