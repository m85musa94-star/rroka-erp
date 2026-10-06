@extends('layouts.app')
@section('title', $def['title'])
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('التقارير المالية'), route('accounting.reports.index')], [$def['title'], null]]])
@endsection
@section('content')
@php
    $presets = ['this_month' => __('هذا الشهر'), 'this_quarter' => __('هذا الربع'), 'this_year' => __('هذه السنة'), 'last_month' => __('الشهر الماضي'), 'last_quarter' => __('الربع الماضي'), 'last_year' => __('السنة الماضية'), 'custom' => __('فترة مخصصة')];
    $cmps = ['none' => __('بلا مقارنة'), 'previous' => __('الفترة السابقة'), 'last_year' => __('الفترة نفسها من السنة الماضية')];
    $url = fn (array $ch) => request()->url().'?'.http_build_query($o->query($ch));
    $periodText = $r['asOf'] ? __('في :d', ['d' => $r['columns'][0]['to'] ?? $o->to]) : __('من :from إلى :to', ['from' => $o->from, 'to' => $o->to]);
@endphp
<div class="card fin-bar">
    <details class="fin-drop">
        <summary>📅 {{ $r['asOf'] ? __('التاريخ') : __('الفترة') }}: <strong>{{ $presets[$o->preset] }}</strong> <span class="muted" dir="ltr">({{ $r['asOf'] ? ($r['columns'][0]['to'] ?? $o->to) : $o->from.' → '.$o->to }})</span></summary>
        <div class="fin-menu">
            @foreach($presets as $k => $label)@continue($k === 'custom')<a href="{{ $url(['date' => $k, 'from' => null, 'to' => null]) }}" @class(['on' => $o->preset === $k])>{{ $label }}</a>@endforeach
            <hr>
            <form method="get" class="fin-custom">
                @foreach($o->query(['date' => null, 'from' => null, 'to' => null]) as $k => $v)
                    @if(is_array($v))@foreach($v as $x)<input type="hidden" name="{{ $k }}[]" value="{{ $x }}">@endforeach @else<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
                @endforeach
                <input type="hidden" name="date" value="custom">
                @unless($r['asOf'])<label>{{ __('من') }} <input type="date" name="from" value="{{ $o->from }}"></label>@else<input type="hidden" name="from" value="{{ $o->booksStart }}">@endunless
                <label>{{ $r['asOf'] ? __('في تاريخ') : __('إلى') }} <input type="date" name="to" value="{{ $r['asOf'] ? ($r['columns'][0]['to'] ?? $o->to) : $o->to }}"></label>
                <button class="btn sm">{{ __('تطبيق') }}</button>
            </form>
        </div>
    </details>
    @if(in_array($key, ['profit-loss', 'balance-sheet'], true))
    <details class="fin-drop">
        <summary>⇄ {{ __('المقارنة') }}: <strong>{{ $cmps[$o->comparison] }}</strong></summary>
        <div class="fin-menu">@foreach($cmps as $k => $label)<a href="{{ $url(['cmp' => $k === 'none' ? null : $k]) }}" @class(['on' => $o->comparison === $k])>{{ $label }}</a>@endforeach</div>
    </details>
    @endif
    <details class="fin-drop">
        <summary>⚙ {{ __('الخيارات') }}</summary>
        <div class="fin-menu">
            <a href="{{ $url(['zero' => $o->showZero ? null : 1]) }}" @class(['on' => $o->showZero])>{{ __('إظهار الحسابات ذات الرصيد الصفري') }}</a>
            @if($key === 'general-ledger')<a href="{{ $url(['unfold' => $o->unfoldAll ? null : 'all', 'acc' => null]) }}" @class(['on' => $o->unfoldAll])>{{ __('فتح كل الحسابات') }}</a>@endif
        </div>
    </details>
    <div class="actions" style="margin-inline-start:auto">
        <a class="btn ghost sm" href="{{ $url(['print' => 1, 'autoprint' => 1]) }}" target="_blank" rel="noopener">{{ __('PDF / طباعة') }}</a>
        <a class="btn ghost sm" href="{{ $url(['export' => 'xlsx']) }}">{{ __('Excel') }}</a>
    </div>
</div>

<div class="card fin-sheet">
    <div class="fin-head">
        <div>
            <h1>{{ $def['title'] }}</h1>
            <div class="muted">{{ $company }} · {{ $periodText }}</div>
        </div>
        <div class="fin-checks">
            @foreach($r['checks'] as $c)
                @if($c['ok'])<span class="badge b-APPROVED">✓ {{ $key === 'balance-sheet' ? __('متوازنة') : __('الميزان متوازن') }}@if(count($r['checks']) > 1) · {{ $c['label'] }}@endif</span>
                @else<span class="badge b-REJECTED">{{ __('غير متوازن — الفرق :d', ['d' => number_format($c['diff'], 2)]) }}</span>@endif
            @endforeach
        </div>
    </div>
    @foreach($r['warnings'] as $w)<div class="alert warn">{{ $w }}</div>@endforeach
    @if(count($r['lines']) <= 1 && collect($r['lines'])->every(fn ($l) => in_array($l['kind'], ['grand', 'heading'], true)))
        <div class="empty-state"><strong>{{ __('لا توجد قيود مرحَّلة في هذه الفترة') }}</strong>{{ __('التقارير المالية تُبنى من القيود المرحَّلة فقط.') }}</div>
    @else
        <div class="table-wrap">@include('accounting.reports._table', ['links' => true])</div>
    @endif
    <p class="hint fin-foot">{{ __('من القيود المرحَّلة فقط. السالب بين قوسين. السنة المالية ميلادية (يناير–ديسمبر)، وبداية الدفاتر :d.', ['d' => $o->booksStart ?? '—']) }}
        @if($key === 'balance-sheet') {{ __('أرباح السنة الحالية والسنوات السابقة محسوبة من قائمة الدخل إلى أن تُقفل في حساب الأرباح المبقاة.') }}@endif</p>
</div>
@endsection
