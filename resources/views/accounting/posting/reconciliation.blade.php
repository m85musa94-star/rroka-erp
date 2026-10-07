@extends('layouts.app')
@section('title', __('مطابقة الدفاتر'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('المحاسبة'), route('accounting.journal.index')], [__('مطابقة الدفاتر'), null]]])
@endsection
@section('content')
@php($fmt = fn ($v) => $v === null ? '—' : ($v < 0 ? '('.number_format(abs($v), 2).')' : number_format($v, 2)))
@php($diffCell = function ($sub, $gl) use ($fmt) { if ($gl === null) { return '<td class="num muted">—</td><td><span class="badge b-ON_HOLD">'.e(__('لا حساب')).'</span></td>'; } $d = round($sub - $gl, 2); return '<td class="num'.($d != 0 ? ' warn-text' : '').'">'.$fmt($d).'</td><td>'.($d == 0 ? '<span class="badge b-APPROVED">'.e(__('مطابق')).'</span>' : '<span class="badge b-ON_HOLD">'.e(__('فرق')).'</span>').'</td>'; })
<div class="card">
    <p style="margin:0">{{ __('كل سجل تفصيلي محفوظ في النظام (المخزون، المشاريع، الضريبة، الموردون، العهد) مقابل رصيد حسابه في الدفاتر من القيود المرحَّلة. الفرق يُفسَّر بأحد ثلاثة: مستندات لم تُرحَّل، أو أرصدة افتتاحية لم تُقيَّد بعد، أو قيود يدوية على الحساب.') }}</p>
    <p style="margin:8px 0 0">
        @if($backlog->n)
            <a href="{{ route('accounting.posting.backlog') }}">{{ __(':n مستند لم يُرحَّل بقيمة :v', ['n' => $backlog->n, 'v' => number_format($backlog->v, 2)]) }}</a>
        @else
            <span class="badge b-APPROVED">{{ __('لا مستندات تنتظر الترحيل') }}</span>
        @endif
        @if($excluded) · {{ __(':n مستند مستبعد بسبب', ['n' => $excluded]) }}@endif
        · {{ __('بداية الدفاتر') }}: {{ $books ?? '—' }}
    </p>
</div>
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('البند') }}</th><th>{{ __('حساب الدفاتر') }}</th><th class="num">{{ __('السجل التفصيلي') }}</th><th class="num">{{ __('الدفاتر') }}</th><th class="num">{{ __('الفرق') }}</th><th></th></tr>
    @foreach($rows as $r)
        <tr><td>@if($r['link'])<a href="{{ $r['link'] }}">{{ $r['label'] }}</a>@else{{ $r['label'] }}@endif<div class="hint" style="margin:0">{{ $r['note'] }}</div></td>
            <td>@if($r['account'])<a href="{{ route('accounting.accounts.show', $r['account']) }}"><bdi dir="ltr">{{ $r['account']->code }}</bdi> — {{ $r['account']->label() }}</a>@else<a href="{{ route('accounting.posting.settings') }}">{{ __('غير محدد') }}</a>@endif</td>
            <td class="num">{{ $fmt($r['sub']) }}</td><td class="num">{{ $fmt($r['gl']) }}</td>{!! $diffCell($r['sub'], $r['gl']) !!}</tr>
    @endforeach
</table></div></div>

<div class="card">
    <h2>{{ __('المشاريع: التكلفة الفعلية مقابل أعمال تحت التنفيذ') }}</h2>
    @if(empty($projects))
        <p class="muted">{{ __('لا تكاليف على المشاريع بعد.') }}</p>
    @else
    <div class="table-wrap"><table>
        <tr><th>{{ __('المشروع') }}</th><th class="num">{{ __('السجل التفصيلي') }}</th><th class="num">{{ __('الدفاتر') }}</th><th class="num">{{ __('الفرق') }}</th><th></th></tr>
        @foreach($projects as $p)
            <tr><td><a href="{{ route('projects.show', $p->id) }}" dir="ltr">{{ $p->project_no }}</a> <bdi>{{ $p->title }}</bdi></td>
                <td class="num">{{ $fmt((float) $p->sub) }}</td><td class="num">{{ $wip ? $fmt((float) $p->gl) : '—' }}</td>{!! $diffCell((float) $p->sub, $wip ? (float) $p->gl : null) !!}</tr>
        @endforeach
    </table></div>
    @endif
</div>

<div class="card">
    <h2>{{ __('العهد') }}</h2>
    @if($custody->isEmpty())
        <p class="muted">{{ __('لا أرصدة عهد.') }}</p>
    @else
    <div class="table-wrap"><table>
        <tr><th>{{ __('العهدة') }}</th><th>{{ __('حساب الدفاتر') }}</th><th class="num">{{ __('رصيد العهدة') }}</th><th class="num">{{ __('الدفاتر') }}</th><th class="num">{{ __('الفرق') }}</th><th></th></tr>
        @foreach($custody as $r)
            <tr><td><a href="{{ $r['link'] }}"><bdi>{{ $r['label'] }}</bdi></a></td>
                <td>@if($r['account'])<bdi dir="ltr">{{ $r['account']->code }}</bdi> — {{ $r['account']->label() }}@else<a href="{{ route('accounting.posting.settings') }}">{{ __('غير محدد') }}</a>@endif</td>
                <td class="num">{{ $fmt($r['sub']) }}</td><td class="num">{{ $fmt($r['gl']) }}</td>{!! $diffCell($r['sub'], $r['gl']) !!}</tr>
        @endforeach
    </table></div>
    @endif
</div>
<p class="hint">{{ __('الصندوق والبنك لا يُطابقان هنا: رصيدهما الصحيح يُطابق مع كشف البنك (المرحلة ج).') }}</p>
@endsection
