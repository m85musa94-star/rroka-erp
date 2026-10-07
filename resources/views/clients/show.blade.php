@extends('layouts.app')
@section('title', $client->business_name)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('العملاء'), route('clients.index')], [$client->business_name, null]]])
@endsection
@section('content')
@php($u = auth()->user())
<div class="rec-bar">
    <div class="actions">
        @include('partials.delete-button', ['type' => 'clients', 'model' => $client])
        @if($u->hasPermission('clients.manage'))
            <a class="btn sm" href="{{ route('clients.edit', $client) }}">{{ __('تعديل') }}</a>
        @endif
        @if($u->hasPermission('quotations.manage'))
            <a class="btn ghost sm" href="{{ route('quotations.create', ['client_id' => $client->id]) }}">{{ __('عرض سعر جديد') }}</a>
        @endif
        @if(! $client->daftra_client_id && $u->hasPermission('daftra.sync'))
            <form method="post" action="{{ route('clients.sync', $client) }}" class="inline" data-confirm="{{ __('إنشاء هذا العميل في دفترة؟') }}">@csrf
                <button class="btn ghost sm">{{ __('إنشاء في دفترة') }}</button>
            </form>
        @endif
    </div>
    <span class="badge {{ $client->daftra_client_id ? 'b-SUCCESS' : '' }}">{{ $client->daftra_client_id ? __('مرتبط بدفترة') : __('غير مرتبط بدفترة') }}</span>
</div>
<div class="card">
    <h1 class="rec-title">{{ $client->business_name }}</h1>
    <p class="rec-sub">{{ $client->client_no }} · {{ __("rroka.client_type.$client->client_type") }}</p>
    <div class="grid g2">
        <dl class="kv">
            <dt>{{ __('الجوال') }}</dt><dd dir="ltr" class="ltr-in">{{ $client->phone ?? '—' }}</dd>
            <dt>{{ __('البريد') }}</dt><dd>{{ $client->email ?? '—' }}</dd>
            <dt>{{ __('العنوان') }}</dt><dd>{{ collect([$client->city, $client->address])->filter()->join(__('، ')) ?: '—' }}</dd>
        </dl>
        <dl class="kv">
            <dt>{{ __('الرقم الضريبي') }}</dt><dd>{{ $client->vat_number ?? '—' }}</dd>
            <dt>{{ __('السجل التجاري') }}</dt><dd>{{ $client->commercial_reg_no ?? '—' }}</dd>
            <dt>{{ __('دفترة') }}</dt><dd>{{ $client->daftra_client_id ? __('رقم :no', ['no' => $client->daftra_client_number]) : '—' }}</dd>
        </dl>
    </div>
    @if($client->notes)<p style="margin-top:14px"><span class="muted">{{ __('ملاحظات:') }}</span> {{ $client->notes }}</p>@endif
</div>

<div class="card">
    <h2>{{ __('عروض الأسعار') }} <span class="muted" style="font-weight:400">({{ $quotations->count() }})</span></h2>
    @if($quotations->isEmpty())
        <p class="muted">{{ __('لا توجد عروض لهذا العميل.') }}</p>
    @else
    <div class="table-wrap"><table>
        <tr><th>{{ __('الرقم') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('الحالة') }}</th></tr>
        @foreach($quotations as $q)
            <tr class="row-link" onclick="location='{{ route('quotations.show', $q) }}'"><td><a href="{{ route('quotations.show', $q) }}">{{ $q->quotation_no }}</a></td>
                <td class="num">{{ $q->issue_date->format('Y-m-d') }}</td>
                <td>@include('partials.badge', ['s' => $q->status])</td></tr>
        @endforeach
    </table></div>
    @endif
</div>

@include('partials.sync-log', ['log' => $syncLog])
@include('partials.studio-strip', ['scope' => ['client_id' => $client->id]])
@include('partials.chatter', ['activity' => $activity])
@endsection
