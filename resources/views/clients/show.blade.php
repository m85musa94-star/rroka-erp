@extends('layouts.app')
@section('title', $client->business_name)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [['العملاء', route('clients.index')], [$client->business_name, null]]])
@endsection
@section('content')
@php($u = auth()->user())
<div class="rec-bar">
    <div class="actions">
        @if($u->hasPermission('clients.manage'))
            <a class="btn sm" href="{{ route('clients.edit', $client) }}">تعديل</a>
        @endif
        @if($u->hasPermission('quotations.manage'))
            <a class="btn ghost sm" href="{{ route('quotations.create', ['client_id' => $client->id]) }}">عرض سعر جديد</a>
        @endif
        @if(! $client->daftra_client_id && $u->hasPermission('daftra.sync'))
            <form method="post" action="{{ route('clients.sync', $client) }}" class="inline" onsubmit="return confirm('إنشاء هذا العميل في دفترة؟')">@csrf
                <button class="btn ghost sm">إنشاء في دفترة</button>
            </form>
        @endif
    </div>
    <span class="badge {{ $client->daftra_client_id ? 'b-SUCCESS' : '' }}">{{ $client->daftra_client_id ? 'مرتبط بدفترة' : 'غير مرتبط بدفترة' }}</span>
</div>
<div class="card">
    <h1 class="rec-title">{{ $client->business_name }}</h1>
    <p class="rec-sub">{{ $client->client_no }} · {{ __("rroka.client_type.$client->client_type") }}</p>
    <div class="grid g2">
        <dl class="kv">
            <dt>الجوال</dt><dd dir="ltr" style="text-align:right">{{ $client->phone ?? '—' }}</dd>
            <dt>البريد</dt><dd>{{ $client->email ?? '—' }}</dd>
            <dt>العنوان</dt><dd>{{ collect([$client->city, $client->address])->filter()->join('، ') ?: '—' }}</dd>
        </dl>
        <dl class="kv">
            <dt>الرقم الضريبي</dt><dd>{{ $client->vat_number ?? '—' }}</dd>
            <dt>السجل التجاري</dt><dd>{{ $client->commercial_reg_no ?? '—' }}</dd>
            <dt>دفترة</dt><dd>{{ $client->daftra_client_id ? "رقم {$client->daftra_client_number}" : '—' }}</dd>
        </dl>
    </div>
    @if($client->notes)<p style="margin-top:14px"><span class="muted">ملاحظات:</span> {{ $client->notes }}</p>@endif
</div>

<div class="card">
    <h2>عروض الأسعار <span class="muted" style="font-weight:400">({{ $quotations->count() }})</span></h2>
    @if($quotations->isEmpty())
        <p class="muted">لا توجد عروض لهذا العميل.</p>
    @else
    <div class="table-wrap"><table>
        <tr><th>الرقم</th><th>التاريخ</th><th>الحالة</th></tr>
        @foreach($quotations as $q)
            <tr class="row-link" onclick="location='{{ route('quotations.show', $q) }}'"><td><a href="{{ route('quotations.show', $q) }}">{{ $q->quotation_no }}</a></td>
                <td class="num">{{ $q->issue_date->format('Y-m-d') }}</td>
                <td>@include('partials.badge', ['s' => $q->status])</td></tr>
        @endforeach
    </table></div>
    @endif
</div>

@include('partials.sync-log', ['log' => $syncLog])
@include('partials.chatter', ['activity' => $activity])
@endsection
