@extends('layouts.app')
@section('title', __('أرصدة العملاء'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('أرصدة العملاء'), null]]])
@endsection
@section('content')
<div class="actions" style="margin-bottom:12px">
    <a class="btn {{ $onlyOpen ? 'ghost' : '' }} sm" href="{{ route('sales.balances') }}">{{ __('كل العملاء') }}</a>
    <a class="btn {{ $onlyOpen ? '' : 'ghost' }} sm" href="{{ route('sales.balances', ['open' => 1]) }}">{{ __('برصيد قائم فقط') }}</a>
</div>
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ __('لا توجد أرصدة') }}</strong>{{ __('تظهر هنا أرصدة العملاء بعد اعتماد فواتيرهم وسندات القبض.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('العميل') }}</th><th class="num">{{ __('الفواتير') }}</th><th class="num">{{ __('الإشعارات الدائنة') }}</th><th class="num">{{ __('المحصّل') }}</th><th class="num">{{ __('الرصيد') }}</th><th></th></tr>
    @foreach($rows as $r)
        <tr>
            <td>{{ $r->business_name }}</td>
            <td class="num">{{ number_format($r->invoiced, 2) }}</td><td class="num">{{ number_format($r->credited, 2) }}</td><td class="num">{{ number_format($r->collected, 2) }}</td>
            <td class="num"><strong>{{ $r->balance < 0 ? '('.number_format(-$r->balance, 2).')' : number_format($r->balance, 2) }}</strong></td>
            <td><a href="{{ route('sales.invoices.index', ['client_id' => $r->client_id]) }}">{{ __('الفواتير') }}</a> · <a href="{{ route('sales.receipts.index', ['client_id' => $r->client_id]) }}">{{ __('التحصيل') }}</a></td>
        </tr>
    @endforeach
    <tr class="grp"><td>{{ __('الإجمالي') }}</td><td class="num">{{ number_format($rows->sum('invoiced'), 2) }}</td><td class="num">{{ number_format($rows->sum('credited'), 2) }}</td><td class="num">{{ number_format($rows->sum('collected'), 2) }}</td><td class="num">{{ number_format($rows->sum('balance'), 2) }}</td><td></td></tr>
</table></div></div>
<p class="hint">{{ __('الرصيد = الفواتير − الإشعارات الدائنة − المحصّل (شاملة الضريبة). الرصيد بين قوسين دفعة مقدمة لدى الورشة. يطابق رصيد حساب العملاء في الدفاتر لكل عميل.') }}</p>
@endif
@endsection
