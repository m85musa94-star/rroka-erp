@extends('layouts.app')
@section('title', $client->business_name)
@section('content')
<div class="card">
    <div class="actions" style="margin-bottom:14px">
        @if(auth()->user()->hasPermission('clients.manage'))
            <a class="btn ghost sm" href="{{ route('clients.edit', $client) }}">تعديل</a>
        @endif
        @if(auth()->user()->hasPermission('quotations.manage'))
            <a class="btn sm" href="{{ route('quotations.create', ['client_id' => $client->id]) }}">عرض سعر جديد</a>
        @endif
        @if(! $client->daftra_client_id && auth()->user()->hasPermission('daftra.sync'))
            <form method="post" action="{{ route('clients.sync', $client) }}" class="inline" onsubmit="return confirm('إنشاء هذا العميل في دفترة؟')">@csrf
                <button class="btn ghost sm">إنشاء في دفترة</button>
            </form>
        @endif
    </div>
    <dl class="kv">
        <dt>رقم العميل</dt><dd>{{ $client->client_no }}</dd>
        <dt>النوع</dt><dd>{{ __("rroka.client_type.$client->client_type") }}</dd>
        <dt>الجوال</dt><dd dir="ltr" style="text-align:right">{{ $client->phone ?? '—' }}</dd>
        <dt>البريد</dt><dd>{{ $client->email ?? '—' }}</dd>
        <dt>الرقم الضريبي</dt><dd>{{ $client->vat_number ?? '—' }}</dd>
        <dt>السجل التجاري</dt><dd>{{ $client->commercial_reg_no ?? '—' }}</dd>
        <dt>العنوان</dt><dd>{{ collect([$client->city, $client->address])->filter()->join('، ') ?: '—' }}</dd>
        <dt>دفترة</dt><dd>{{ $client->daftra_client_id ? "مرتبط (رقم {$client->daftra_client_number})" : 'غير مرتبط' }}</dd>
        @if($client->notes)<dt>ملاحظات</dt><dd>{{ $client->notes }}</dd>@endif
    </dl>
</div>

<div class="card">
    <h2>عروض الأسعار</h2>
    @if($quotations->isEmpty())
        <p class="muted">لا توجد عروض لهذا العميل.</p>
    @else
    <div class="table-wrap"><table>
        <tr><th>الرقم</th><th>التاريخ</th><th>الحالة</th></tr>
        @foreach($quotations as $q)
            <tr><td><a href="{{ route('quotations.show', $q) }}">{{ $q->quotation_no }}</a></td>
                <td class="num">{{ $q->issue_date->format('Y-m-d') }}</td>
                <td>@include('partials.badge', ['s' => $q->status])</td></tr>
        @endforeach
    </table></div>
    @endif
</div>

@include('partials.sync-log', ['log' => $syncLog])
@endsection
