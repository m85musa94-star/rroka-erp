@extends('layouts.app')
@section('title', __('حركات المالك'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('حركات المالك'), null]],
        'newUrl' => auth()->user()->hasPermission('accounting.manage') ? route('accounting.owner.create') : null])
@endsection
@section('content')
@php($can = auth()->user()->hasPermission('accounting.manage'))
@if($can)
<div class="actions" style="margin-bottom:12px">
    <a class="btn sm" href="{{ route('accounting.owner.create') }}">{{ __('تمويل من المالك') }}</a>
    <a class="btn ghost sm" href="{{ route('accounting.owner.create', ['kind' => 'WITHDRAWAL']) }}">{{ __('سحب للمالك') }}</a>
    @if(auth()->user()->hasPermission('expenses.manage'))<a class="btn ghost sm" href="{{ route('expenses.create') }}">{{ __('مصروف دفعه المالك من ماله') }}</a>@endif
</div>
@endif
@include('partials.kpi-strip', ['kpis' => [
    [__('التمويل النقدي'), number_format($totals['funding'], 2), null, null],
    [__('مصروفات دفعها المالك عن الورشة'), number_format($totals['paid'], 2), null, null],
    [__('السحوبات'), '('.number_format($totals['withdrawn'], 2).')', null, null],
    [__('رصيد جاري المالك'), number_format($totals['balance'], 2), null, null],
]])
@if($pending->isNotEmpty())
<div class="card">
    <h2>{{ __('بانتظار الاعتماد') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الرقم') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('النوع') }}</th><th>{{ __('البيان') }}</th><th>{{ __('الحساب') }}</th><th class="num">{{ __('المبلغ') }}</th></tr>
        @foreach($pending as $p)
            <tr class="row-link" onclick="location='{{ route('accounting.owner.show', $p) }}'"><td><a href="{{ route('accounting.owner.show', $p) }}">{{ $p->txn_no }}</a></td><td class="num">{{ $p->txn_date->format('Y-m-d') }}</td>
                <td>{{ __("rroka.owner_kind.$p->kind") }}</td><td>{{ $p->description }}</td><td>{{ $p->paymentAccount->name }}</td><td class="num">{{ number_format($p->amount, 2) }}</td></tr>
        @endforeach
    </table></div>
</div>
@endif
<div class="card" style="padding:0">
    <h2 style="padding:16px 16px 0">{{ __('كشف حساب المالك') }}</h2>
    @if($lines->isEmpty())
        <p class="muted" style="padding:0 16px 16px">{{ __('لا حركات معتمدة بعد. سجّل تمويل المالك ومصروفاته عن الورشة وسحوباته هنا.') }}</p>
    @else
    <div class="table-wrap"><table>
        <tr><th>{{ __('التاريخ') }}</th><th>{{ __('المستند') }}</th><th>{{ __('البيان') }}</th><th class="num">{{ __('له (تمويل)') }}</th><th class="num">{{ __('عليه (سحب)') }}</th><th class="num">{{ __('الرصيد') }}</th></tr>
        @foreach($lines as $l)
            <tr><td class="num">{{ \Illuminate\Support\Carbon::parse($l->line_date)->format('Y-m-d') }}</td>
                <td><a href="{{ $l->source === 'OWNER' ? route('accounting.owner.show', $l->source_id) : route('expenses.show', $l->source_id) }}">{{ $l->doc_no }}</a></td>
                <td>{{ $l->description }} <span class="muted">· {{ __("rroka.owner_kind.$l->kind") }}</span></td>
                <td class="num">{{ (float) $l->credit ? number_format($l->credit, 2) : '' }}</td><td class="num">{{ (float) $l->debit ? number_format($l->debit, 2) : '' }}</td>
                <td class="num">{{ number_format($l->balance, 2) }}</td></tr>
        @endforeach
    </table></div>
    @endif
</div>
<p class="hint">{{ __('جاري المالك لا يُحرَّك بقيد يدوي (عدا القيد الافتتاحي)؛ كل حركة له تمر من هذه الشاشة أو من مصروف «دفعه المالك»، فيطابق هذا الكشف حسابه في الدفاتر.') }}</p>
@endsection
