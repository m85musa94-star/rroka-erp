@extends('layouts.app')
@section('title', __('أرصدة الموردين'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('أرصدة الموردين'), null]]])
@endsection
@section('content')
<div class="actions" style="margin-bottom:12px">
    <a class="btn {{ $onlyOpen ? 'ghost' : '' }} sm" href="{{ route('supplier-payments.balances') }}">{{ __('كل الموردين') }}</a>
    <a class="btn {{ $onlyOpen ? '' : 'ghost' }} sm" href="{{ route('supplier-payments.balances', ['open' => 1]) }}">{{ __('برصيد قائم فقط') }}</a>
</div>
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ __('لا توجد أرصدة') }}</strong>{{ __('تظهر هنا أرصدة الموردين بعد اعتماد فواتيرهم الآجلة وسندات الصرف.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('المورد') }}</th><th class="num">{{ __('الفواتير') }}</th><th class="num">{{ __('المدفوع') }}</th><th class="num">{{ __('المستحق للمورد') }}</th><th></th></tr>
    @foreach($rows as $r)
        <tr>
            <td><a href="{{ route('suppliers.show', $r->supplier_id) }}">{{ $r->name }}</a></td>
            <td class="num">{{ number_format($r->invoiced, 2) }}</td><td class="num">{{ number_format($r->paid, 2) }}</td>
            <td class="num"><strong>{{ $r->balance < 0 ? '('.number_format(-$r->balance, 2).')' : number_format($r->balance, 2) }}</strong></td>
            <td><a href="{{ route('supplier-payments.index', ['supplier_id' => $r->supplier_id]) }}">{{ __('السداد') }}</a>@if($r->balance > 0 && auth()->user()->hasPermission('purchases.manage')) · <a href="{{ route('supplier-payments.create', ['supplier_id' => $r->supplier_id]) }}">{{ __('سداد جديد') }}</a>@endif</td>
        </tr>
    @endforeach
    <tr class="grp"><td>{{ __('الإجمالي') }}</td><td class="num">{{ number_format($rows->sum('invoiced'), 2) }}</td><td class="num">{{ number_format($rows->sum('paid'), 2) }}</td><td class="num">{{ number_format($rows->sum('balance'), 2) }}</td><td></td></tr>
</table></div></div>
<p class="hint">{{ __('المستحق = فواتير المشتريات والفواتير الآجلة المعتمدة (شاملة الضريبة) − المدفوع. الرصيد بين قوسين دفعة مقدمة للمورد. يطابق رصيد حساب الموردين في الدفاتر لكل مورد.') }}</p>
@endif
@endsection
