@extends('layouts.app')
@section('title', $report->title())
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('التقارير'), route('reports.index')], [$report->title(), null]],
        'lv' => $lv,
        'noSearch' => true,
        'total' => null,
    ])
@endsection
@section('content')
@php
    $d = fn ($x) => '<bdi dir="ltr">'.e($x).'</bdi>';
    $rangeHtml = match (true) {
        $from && $to => __('من ').$d($from).__(' إلى ').$d($to),
        (bool) $from => __('من ').$d($from),
        (bool) $to => __('حتى ').$d($to),
        default => null,
    };
    $m = $measures[$measure];
    $fmt = fn ($v) => \App\Reports\Report::format($v, $m['format']);
    $base = request()->except(['export']);
    // Validated categorical palette; the light/dark steps live in app.css (--series-1..7).
    $palette = array_map(fn ($i) => "var(--series-$i)", range(1, 7));
@endphp

<p class="report-range">
    {{ $report->dateLabel() }}: <strong>{!! $rangeHtml ?? __('كل الفترات') !!}</strong>
    @if($swapped)<span class="muted">{{ __('— عُكس التاريخان لأن تاريخ البداية كان بعد تاريخ النهاية.') }}</span>@endif
</p>
@php
    $today = \Carbon\CarbonImmutable::today();
    $quick = [
        __('هذا الشهر') => [$today->startOfMonth(), $today->endOfMonth()],
        __('الشهر الماضي') => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
        __('هذا الربع') => [$today->startOfQuarter(), $today->endOfQuarter()],
        __('هذه السنة') => [$today->startOfYear(), $today->endOfYear()],
        __('السنة الماضية') => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()],
    ];
@endphp
<div class="quick-dates">
    @foreach($quick as $label => [$qf, $qt])
        <a href="{{ request()->fullUrlWithQuery(['from' => $qf->toDateString(), 'to' => $qt->toDateString()]) }}" @class(['on' => $from === $qf->toDateString() && $to === $qt->toDateString()])>{{ $label }}</a>
    @endforeach
</div>
<form method="get" class="card report-bar">
    @foreach($lv->active as $f)<input type="hidden" name="f[]" value="{{ $f }}">@endforeach
    <input type="hidden" name="v" value="{{ $lv->view }}">
    <label>{{ __('من تاريخ') }}
        <input type="date" name="from" value="{{ $from }}" onchange="this.form.submit()" aria-label="{{ __('من تاريخ') }}">
    </label>
    <label>{{ __('إلى تاريخ') }}
        <input type="date" name="to" value="{{ $to }}" onchange="this.form.submit()" aria-label="{{ __('إلى تاريخ') }}">
    </label>
    <label>{{ __('القيمة') }}
        <select name="m" onchange="this.form.submit()">
            @foreach($measures as $k => $def)<option value="{{ $k }}" @selected($k === $measure)>{{ $def['label'] }}</option>@endforeach
        </select>
    </label>
    <label>{{ __('الصفوف') }}
        <select name="rows" onchange="this.form.submit()">
            @foreach($dims as $k => $d)<option value="{{ $k }}" @selected($k === $row)>{{ $d['label'] }}</option>@endforeach
        </select>
    </label>
    <label>{{ __('الأعمدة') }}
        <select name="cols" onchange="this.form.submit()">
            <option value="">{{ __('— بلا —') }}</option>
            @foreach($dims as $k => $d)@if($k !== $row)<option value="{{ $k }}" @selected($k === $col)>{{ $d['label'] }}</option>@endif @endforeach
        </select>
    </label>
    <div class="actions" style="margin-inline-start:auto">
        @if($from || $to)
            <a class="btn ghost sm" href="{{ request()->fullUrlWithQuery(['from' => null, 'to' => null]) }}">{{ __('مسح التاريخ') }}</a>
        @endif
        @if($col)
            <a class="btn ghost sm" href="{{ request()->fullUrlWithQuery(['rows' => $col, 'cols' => $row]) }}" title="{{ __('تبديل الصفوف والأعمدة') }}">{{ __('⇄ تبديل') }}</a>
        @endif
        <a class="btn ghost sm" href="{{ request()->fullUrlWithQuery(['print' => 1, 'autoprint' => 1]) }}" target="_blank" rel="noopener">{{ __('PDF / طباعة') }}</a>
        <a class="btn ghost sm" href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}">{{ __('Excel') }}</a>
        <a class="btn ghost sm" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}">CSV</a>
    </div>
</form>

@php
    $grandV = $pivot['grand'];
    $canShare = $m['additive'] && $grandV !== null && (float) $grandV > 0;
    $top = collect($pivot['rows'])->sortByDesc(fn ($r) => (float) ($pivot['rowTotals'][$r['key']] ?? 0))->first();
    $topV = $top ? ($pivot['rowTotals'][$top['key']] ?? null) : null;
    $kpis = $pivot['rows'] ? [
        [$m['label'], $fmt($grandV), $rangeHtml ? strip_tags($rangeHtml) : __('كل الفترات'), null],
        [__('عدد :d', ['d' => $dims[$row]['label']]), (string) count($pivot['rows']), null, null],
        [__('الأعلى'), $top['label'] ?? '—', $topV !== null ? $fmt($topV).($canShare ? ' · '.number_format($topV / $grandV * 100, 1).'%' : '') : null, null],
    ] : [];
@endphp
@include('partials.kpi-strip', ['kpis' => $kpis])

@if(! $pivot['rows'])
    <div class="card empty-state"><strong>{{ __('لا توجد بيانات') }}</strong>{{ __('لا توجد سجلات تطابق الفلاتر المختارة.') }}</div>
@elseif($lv->view === 'graph')
    @php
        $series = $col && $m['additive'] ? array_slice($pivot['cols'], 0, 7) : [];
        $values = collect($pivot['rows'])->map(fn ($r) => $pivot['rowTotals'][$r['key']] ?? null)->filter(fn ($v) => $v !== null);
        $hasNeg = $values->contains(fn ($v) => $v < 0);
        if ($hasNeg) { $series = []; }
        $max = max(0, (float) $values->max());
        $min = min(0, (float) $values->min());
        $span = ($max - $min) ?: 1;
        $zero = -$min / $span * 100;
    @endphp
    <div class="card viz">
        <h2 style="margin-bottom:4px">{{ $m['label'] }} {{ __('حسب') }} {{ $dims[$row]['label'] }}@if($rangeHtml) <span class="muted" style="font-weight:400;font-size:14px">({!! $rangeHtml !!})</span>@endif</h2>
        @if($col && ! $m['additive'])<p class="hint" style="margin-top:0">{{ __('القيمة المختارة نسبة لا تُجمع، فالرسم يعرض الإجمالي لكل صف؛ التفصيل حسب') }} {{ $dims[$col]['label'] }} {{ __('في الجدول المحوري.') }}</p>@endif
        @if($hasNeg && $col)<p class="hint" style="margin-top:0">{{ __('توجد قيم سالبة، فالرسم يعرض الإجمالي لكل صف دون تقسيم.') }}</p>@endif
        @if(count($series) > 1)
            <div class="legend">
                @foreach($series as $i => $s)<span><i style="background:{{ $palette[$i] }}"></i>{{ $s['label'] }}</span>@endforeach
                @if(count($pivot['cols']) > 7)<span><i style="background: var(--series-other)"></i>{{ __('أخرى') }}</span>@endif
            </div>
        @endif
        <div class="bars" role="img" aria-label="{{ $m['label'] }} {{ __('حسب') }} {{ $dims[$row]['label'] }}">
            @foreach($pivot['rows'] as $r)
                @php($raw = $pivot['rowTotals'][$r['key']] ?? null)
                @php($v = (float) $raw)
                <div class="bar-row">
                    <div class="bar-label" title="{{ $r['label'] }}">{{ $r['label'] }}</div>
                    <div class="bar-track">
                        @if($zero > 0)<span class="bar-zero" style="inset-inline-start: {{ $zero }}%"></span>@endif
                        @if($raw === null)
                            <span class="bar-value" style="inset-inline-start: calc({{ $zero }}% + 6px)" title="{{ __('لا يمكن حسابها لهذا الصف') }}">{{ __('— غير قابل للحساب') }}</span>
                        @elseif($series)
                            @php($acc = 0)
                            <div class="bar-stack" style="inset-inline-start: {{ $zero }}%; width: {{ $v / $span * 100 }}%">
                                @foreach($series as $i => $s)
                                    @php($sv = (float) ($pivot['cells'][$r['key']][$s['key']] ?? 0))
                                    @if($sv > 0)<span class="seg" style="flex: {{ $sv }}; background: {{ $palette[$i] }}" title="{{ $s['label'] }}: {{ $fmt($sv) }}"></span>@endif
                                    @php($acc += $sv)
                                @endforeach
                                @if($v - $acc > 0.0001)<span class="seg" style="flex: {{ $v - $acc }}; background: var(--series-other)" title="{{ __('أخرى:') }} {{ $fmt($v - $acc) }}"></span>@endif
                            </div>
                        @else
                            <div @class(['bar', 'neg' => $v < 0]) title="{{ $r['label'] }}: {{ $fmt($v) }}"
                                 style="inset-inline-start: {{ $v >= 0 ? $zero : $zero - abs($v) / $span * 100 }}%; width: {{ abs($v) / $span * 100 }}%"></div>
                        @endif
                        @if($raw !== null)
                            <span class="bar-value" style="inset-inline-start: calc({{ $v >= 0 ? $zero + $v / $span * 100 : $zero }}% + 6px)">{{ $fmt($v) }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <p class="hint">{{ __('مرّر المؤشر على الأعمدة لرؤية القيم؛ الأرقام كاملة في عرض الجدول المحوري.') }}</p>
    </div>
@else
    <div class="card" style="padding:0">
        <div class="table-wrap"><table class="pivot">
            <thead>
                <tr>
                    <th>{{ $dims[$row]['label'] }} @if($col)<span class="muted">/ {{ $dims[$col]['label'] }}</span>@endif</th>
                    @foreach($pivot['cols'] as $c)<th class="num">{{ $c['label'] }}</th>@endforeach
                    <th class="num">{{ __('الإجمالي') }}</th>
                    @if($canShare)<th class="num share-col">{{ __('النسبة') }}</th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach($pivot['rows'] as $r)
                    <tr>
                        <td>{{ $r['label'] }}</td>
                        @foreach($pivot['cols'] as $c)<td class="num">{{ $fmt($pivot['cells'][$r['key']][$c['key']] ?? null) }}</td>@endforeach
                        <td class="num strong">{{ $fmt($pivot['rowTotals'][$r['key']] ?? null) }}</td>
                        @if($canShare)
                            @php($sh = max(0, (float) ($pivot['rowTotals'][$r['key']] ?? 0)) / $grandV * 100)
                            <td class="num share-col"><span class="share-bar"><i style="width: {{ min(100, $sh) }}%"></i></span>{{ number_format($sh, 1) }}%</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th>{{ __('الإجمالي') }}</th>
                    @foreach($pivot['cols'] as $c)<th class="num">{{ $fmt($pivot['colTotals'][$c['key']] ?? null) }}</th>@endforeach
                    <th class="num">{{ $fmt($pivot['grand']) }}</th>
                    @if($canShare)<th class="num share-col">100%</th>@endif
                </tr>
            </tfoot>
        </table></div>
    </div>
@endif

@if($note = $report->note($lv->active, $from, $to))
    <p class="alert warn" style="margin-top:12px">{{ $note }}</p>
@endif
@endsection
