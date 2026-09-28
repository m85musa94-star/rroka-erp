@extends('layouts.app')
@section('title', $report->title())
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [['التقارير', route('reports.index')], [$report->title(), null]],
        'lv' => $lv,
        'noSearch' => true,
        'total' => null,
    ])
@endsection
@section('content')
@php
    $m = $measures[$measure];
    $fmt = fn ($v) => \App\Reports\Report::format($v, $m['format']);
    $base = request()->except(['export']);
    $palette = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7'];
@endphp

<form method="get" class="card report-bar">
    @foreach($lv->active as $f)<input type="hidden" name="f[]" value="{{ $f }}">@endforeach
    <input type="hidden" name="v" value="{{ $lv->view }}">
    <label>القيمة
        <select name="m" onchange="this.form.submit()">
            @foreach($measures as $k => $def)<option value="{{ $k }}" @selected($k === $measure)>{{ $def['label'] }}</option>@endforeach
        </select>
    </label>
    <label>الصفوف
        <select name="rows" onchange="this.form.submit()">
            @foreach($dims as $k => $d)<option value="{{ $k }}" @selected($k === $row)>{{ $d['label'] }}</option>@endforeach
        </select>
    </label>
    <label>الأعمدة
        <select name="cols" onchange="this.form.submit()">
            <option value="">— بلا —</option>
            @foreach($dims as $k => $d)@if($k !== $row)<option value="{{ $k }}" @selected($k === $col)>{{ $d['label'] }}</option>@endif @endforeach
        </select>
    </label>
    <div class="actions" style="margin-inline-start:auto">
        @if($col)
            <a class="btn ghost sm" href="{{ request()->fullUrlWithQuery(['rows' => $col, 'cols' => $row]) }}" title="تبديل الصفوف والأعمدة">⇄ تبديل</a>
        @endif
        <a class="btn ghost sm" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}">تصدير إلى إكسل</a>
    </div>
</form>

@if(! $pivot['rows'])
    <div class="card empty-state"><strong>لا توجد بيانات</strong>لا توجد سجلات تطابق الفلاتر المختارة.</div>
@elseif($lv->view === 'graph')
    @php
        $series = $col && $m['additive'] ? array_slice($pivot['cols'], 0, 7) : [];
        $values = collect($pivot['rows'])->map(fn ($r) => $pivot['rowTotals'][$r['key']] ?? null)->filter(fn ($v) => $v !== null);
        $hasNeg = $values->contains(fn ($v) => $v < 0);
        if ($hasNeg) { $series = []; }
        $max = max(0, (float) $values->max());
        $min = min(0, (float) $values->min());
        $range = ($max - $min) ?: 1;
        $zero = -$min / $range * 100;
    @endphp
    <div class="card viz">
        <h2 style="margin-bottom:4px">{{ $m['label'] }} حسب {{ $dims[$row]['label'] }}</h2>
        @if($col && ! $m['additive'])<p class="hint" style="margin-top:0">القيمة المختارة نسبة لا تُجمع، فالرسم يعرض الإجمالي لكل صف؛ التفصيل حسب {{ $dims[$col]['label'] }} في الجدول المحوري.</p>@endif
        @if($hasNeg && $col)<p class="hint" style="margin-top:0">توجد قيم سالبة، فالرسم يعرض الإجمالي لكل صف دون تقسيم.</p>@endif
        @if(count($series) > 1)
            <div class="legend">
                @foreach($series as $i => $s)<span><i style="background:{{ $palette[$i] }}"></i>{{ $s['label'] }}</span>@endforeach
                @if(count($pivot['cols']) > 7)<span><i style="background:#9a948d"></i>أخرى</span>@endif
            </div>
        @endif
        <div class="bars" role="img" aria-label="{{ $m['label'] }} حسب {{ $dims[$row]['label'] }}">
            @foreach($pivot['rows'] as $r)
                @php($raw = $pivot['rowTotals'][$r['key']] ?? null)
                @php($v = (float) $raw)
                <div class="bar-row">
                    <div class="bar-label" title="{{ $r['label'] }}">{{ $r['label'] }}</div>
                    <div class="bar-track">
                        @if($zero > 0)<span class="bar-zero" style="inset-inline-start: {{ $zero }}%"></span>@endif
                        @if($raw === null)
                            <span class="bar-value" style="inset-inline-start: calc({{ $zero }}% + 6px)" title="لا يمكن حسابها لهذا الصف">— غير قابل للحساب</span>
                        @elseif($series)
                            @php($acc = 0)
                            <div class="bar-stack" style="inset-inline-start: {{ $zero }}%; width: {{ $v / $range * 100 }}%">
                                @foreach($series as $i => $s)
                                    @php($sv = (float) ($pivot['cells'][$r['key']][$s['key']] ?? 0))
                                    @if($sv > 0)<span class="seg" style="flex: {{ $sv }}; background: {{ $palette[$i] }}" title="{{ $s['label'] }}: {{ $fmt($sv) }}"></span>@endif
                                    @php($acc += $sv)
                                @endforeach
                                @if($v - $acc > 0.0001)<span class="seg" style="flex: {{ $v - $acc }}; background:#9a948d" title="أخرى: {{ $fmt($v - $acc) }}"></span>@endif
                            </div>
                        @else
                            <div @class(['bar', 'neg' => $v < 0]) title="{{ $r['label'] }}: {{ $fmt($v) }}"
                                 style="inset-inline-start: {{ $v >= 0 ? $zero : $zero - abs($v) / $range * 100 }}%; width: {{ abs($v) / $range * 100 }}%"></div>
                        @endif
                        @if($raw !== null)
                            <span class="bar-value" style="inset-inline-start: calc({{ $v >= 0 ? $zero + $v / $range * 100 : $zero }}% + 6px)">{{ $fmt($v) }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <p class="hint">مرّر المؤشر على الأعمدة لرؤية القيم؛ الأرقام كاملة في عرض الجدول المحوري.</p>
    </div>
@else
    <div class="card" style="padding:0">
        <div class="table-wrap"><table class="pivot">
            <thead>
                <tr>
                    <th>{{ $dims[$row]['label'] }} @if($col)<span class="muted">/ {{ $dims[$col]['label'] }}</span>@endif</th>
                    @foreach($pivot['cols'] as $c)<th class="num">{{ $c['label'] }}</th>@endforeach
                    <th class="num">الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pivot['rows'] as $r)
                    <tr>
                        <td>{{ $r['label'] }}</td>
                        @foreach($pivot['cols'] as $c)<td class="num">{{ $fmt($pivot['cells'][$r['key']][$c['key']] ?? null) }}</td>@endforeach
                        <td class="num strong">{{ $fmt($pivot['rowTotals'][$r['key']] ?? null) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th>الإجمالي</th>
                    @foreach($pivot['cols'] as $c)<th class="num">{{ $fmt($pivot['colTotals'][$c['key']] ?? null) }}</th>@endforeach
                    <th class="num">{{ $fmt($pivot['grand']) }}</th>
                </tr>
            </tfoot>
        </table></div>
    </div>
@endif

@if($note = $report->note($lv->active))
    <p class="alert warn" style="margin-top:12px">{{ $note }}</p>
@endif
@endsection
