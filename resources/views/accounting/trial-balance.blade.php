@extends('layouts.app')
@section('title', __('ميزان المراجعة'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('المحاسبة'), route('accounting.journal.index')], [__('ميزان المراجعة'), null]]])
@endsection
@section('content')
@php($m = fn ($v) => $v > 0.004 ? number_format($v, 2) : '')
<div class="card">
    <form method="get" class="grid g4" style="align-items:end">
        <div class="field"><label>{{ __('من') }}</label><input type="date" name="from" value="{{ $from }}"></div>
        <div class="field"><label>{{ __('إلى') }}</label><input type="date" name="to" value="{{ $to }}"></div>
        <div class="actions"><button class="btn ghost">{{ __('عرض') }}</button></div>
    </form>
    @if($rows)
        <p class="{{ $balanced ? 'hint' : 'hint warn-text' }}" style="margin-bottom:0">{{ $balanced ? __('الميزان متوازن: مجموع المدين يساوي مجموع الدائن.') : __('الميزان غير متوازن — هذا لا يحدث إلا بخلل؛ أبلغ الدعم فورًا.') }}
            @if($drafts) · {{ __('توجد :n مسودة قيد في الفترة غير داخلة في الميزان.', ['n' => $drafts]) }} <a href="{{ route('accounting.journal.index', ['f' => ['draft']]) }}">{{ __('عرضها') }}</a>@endif</p>
    @endif
</div>
@if(! $rows)
    <div class="card empty-state"><strong>{{ __('لا توجد قيود مرحَّلة حتى :d', ['d' => $to]) }}</strong>{{ __('الميزان يُبنى من القيود المرحَّلة فقط.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th rowspan="2">{{ __('الرمز') }}</th><th rowspan="2">{{ __('الحساب') }}</th><th colspan="2" class="num">{{ __('رصيد أول المدة') }}</th><th colspan="2" class="num">{{ __('حركة الفترة') }}</th><th colspan="2" class="num">{{ __('رصيد آخر المدة') }}</th></tr>
    <tr><th class="num">{{ __('مدين') }}</th><th class="num">{{ __('دائن') }}</th><th class="num">{{ __('مدين') }}</th><th class="num">{{ __('دائن') }}</th><th class="num">{{ __('مدين') }}</th><th class="num">{{ __('دائن') }}</th></tr>
    @foreach(collect($rows)->groupBy('account_type')->sortBy(fn ($g, $t) => array_search($t, \App\Models\Account::TYPES, true)) as $type => $items)
        <tr class="grp"><td colspan="8">{{ __("rroka.account_type.$type") }}</td></tr>
        @foreach($items as $r)
            <tr><td class="num" dir="ltr">{{ $r->code }}</td>
                <td><a href="{{ route('accounting.accounts.show', [$r->id, 'from' => $from, 'to' => $to]) }}">{{ $r->label }}</a></td>
                <td class="num">{{ $m((float) $r->opening) }}</td><td class="num">{{ $m(-(float) $r->opening) }}</td>
                <td class="num">{{ $m((float) $r->debit) }}</td><td class="num">{{ $m((float) $r->credit) }}</td>
                <td class="num">{{ $m($r->closing) }}</td><td class="num">{{ $m(-$r->closing) }}</td></tr>
        @endforeach
    @endforeach
    <tr class="grp"><td colspan="2">{{ __('المجموع') }}</td>
        <td class="num">{{ number_format($t['od'], 2) }}</td><td class="num">{{ number_format($t['oc'], 2) }}</td>
        <td class="num">{{ number_format($t['d'], 2) }}</td><td class="num">{{ number_format($t['c'], 2) }}</td>
        <td class="num">{{ number_format($t['cd'], 2) }}</td><td class="num">{{ number_format($t['cc'], 2) }}</td></tr>
</table></div></div>
<p class="hint">{{ __('من القيود المرحَّلة فقط، لكل حساب عليه حركة حتى نهاية الفترة. الحساب الذي لا حركة عليه لا يظهر.') }}</p>
@endif
@endsection
