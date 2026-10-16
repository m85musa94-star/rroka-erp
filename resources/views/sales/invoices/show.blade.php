@extends('layouts.app')
@section('title', $x->invoice_no)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('فواتير العملاء'), route('sales.invoices.index')], [$x->invoice_no, null]]])
@endsection
@section('content')
@php($u = auth()->user())
@php($path = $x->status === 'CANCELLED' ? ['DRAFT', 'CANCELLED'] : ['DRAFT', 'APPROVED'])
<div class="rec-bar">
    <div class="actions">
        @include('partials.delete-button', ['type' => 'sales-invoices', 'model' => $x])
        @if($x->status === 'DRAFT')
            @if($u->hasPermission('sales.manage'))<a class="btn ghost sm" href="{{ route('sales.invoices.edit', $x) }}">{{ __('تعديل') }}</a>@endif
            @if($u->hasPermission('sales.approve'))<form method="post" action="{{ route('sales.invoices.approve', $x) }}" class="inline" data-confirm="{{ __('اعتماد المستند؟ يُرحَّل للدفاتر ولا يمكن تعديله بعدها.') }}">@csrf<button class="btn ok sm">{{ __('اعتماد') }}</button></form>@endif
            @if($u->hasPermission('sales.manage'))<form method="post" action="{{ route('sales.invoices.cancel', $x) }}" class="inline" data-confirm="{{ __('إلغاء المسودة؟') }}">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>@endif
        @endif
        @if($x->status === 'APPROVED' && ! $x->isCreditNote() && $u->hasPermission('sales.manage'))
            <a class="btn sm" href="{{ route('sales.receipts.create', ['invoice' => $x->id]) }}">{{ __('تسجيل تحصيل') }}</a>
            <a class="btn ghost sm" href="{{ route('sales.invoices.create', ['original' => $x->id]) }}">{{ __('إشعار دائن') }}</a>
        @endif
        @if($x->attachment)<a class="btn ghost sm" href="{{ $x->attachment->url(false) }}" target="_blank" rel="noopener">{{ __('صورة الفاتورة') }}</a>@endif
    </div>
    @include('partials.statusbar', ['path' => $path, 'current' => $x->status, 'bad' => ['CANCELLED']])
</div>
<div class="card">
    <h1 class="rec-title">{{ $x->invoice_no }} @if($x->isCreditNote())<span class="badge b-ON_HOLD">{{ __('إشعار دائن') }}</span>@endif</h1>
    <p class="rec-sub">{{ $x->client->business_name }} · {{ $x->description }}</p>
    <div class="grid g2">
        <dl class="kv">
            <dt>{{ __('رقم دفترة') }}</dt><dd><bdi dir="ltr">{{ $x->daftra_invoice_no }}</bdi></dd>
            <dt>{{ __('التاريخ') }}</dt><dd>{{ $x->invoice_date->format('Y-m-d') }}</dd>
            <dt>{{ __('العميل') }}</dt><dd>@if($u->hasPermission('clients.view'))<a href="{{ route('clients.show', $x->client) }}">{{ $x->client->business_name }}</a>@else{{ $x->client->business_name }}@endif</dd>
            <dt>{{ __('المشروع') }}</dt><dd>@if($x->project)<a href="{{ route('projects.show', $x->project) }}">{{ $x->project->project_no }} — {{ $x->project->title }}</a>@else{{ __('بلا مشروع') }}@endif</dd>
            @if($x->original)<dt>{{ __('على الفاتورة') }}</dt><dd><a href="{{ route('sales.invoices.show', $x->original) }}">{{ $x->original->invoice_no }}</a> (<bdi dir="ltr">{{ $x->original->daftra_invoice_no }}</bdi>)</dd>@endif
            @include('partials.journal-link', ['type' => 'SALE', 'id' => $x->id, 'approved' => $x->status === 'APPROVED'])
        </dl>
        <div class="table-wrap"><table>
            <tr><td>{{ __('المبلغ قبل الضريبة') }}</td><td class="num">{{ number_format($x->amount, 2) }}</td></tr>
            <tr><td>{{ __('ضريبة القيمة المضافة') }}</td><td class="num">{{ number_format($x->vat_amount, 2) }}</td></tr>
            <tr><th>{{ __('الإجمالي') }}</th><th class="num">{{ number_format($x->total, 2) }}</th></tr>
            @if($open !== null)<tr><th>{{ __('المتبقي على الفاتورة') }}</th><th class="num">{{ number_format($open, 2) }}</th></tr>@endif
        </table></div>
    </div>
    @if($x->notes)<p>{{ $x->notes }}</p>@endif
    <dl class="kv" style="margin-top:10px">
        <dt>{{ __('أدخله') }}</dt><dd>{{ $names[$x->created_by] ?? '—' }}</dd>
        @if($x->approved_at)<dt>{{ __('اعتمده') }}</dt><dd>{{ $names[$x->approved_by] ?? '—' }} — {{ $x->approved_at->format('Y-m-d H:i') }} @if($x->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</dd>@endif
    </dl>
</div>
@if(! $x->isCreditNote() && ($x->receipts->isNotEmpty() || $x->creditNotes->isNotEmpty()))
<div class="card">
    <h2>{{ __('التحصيل والإشعارات الدائنة') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('المستند') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('البيان') }}</th><th class="num">{{ __('المبلغ') }}</th><th>{{ __('الحالة') }}</th></tr>
        @foreach($x->creditNotes as $c)
            <tr><td><a href="{{ route('sales.invoices.show', $c) }}">{{ $c->invoice_no }}</a></td><td class="num">{{ $c->invoice_date->format('Y-m-d') }}</td><td>{{ __('إشعار دائن') }} — {{ $c->description }}</td><td class="num">{{ number_format($c->total, 2) }}</td><td>@include('partials.badge', ['s' => $c->status])</td></tr>
        @endforeach
        @foreach($x->receipts as $r)
            <tr><td><a href="{{ route('sales.receipts.show', $r) }}">{{ $r->receipt_no }}</a></td><td class="num">{{ $r->receipt_date->format('Y-m-d') }}</td><td>{{ __('تحصيل') }} — {{ $r->paymentAccount->name }}</td><td class="num">{{ number_format($r->amount, 2) }}</td><td>@include('partials.badge', ['s' => $r->status])</td></tr>
        @endforeach
    </table></div>
</div>
@endif
@include('partials.attachments', ['type' => 'sales-invoices', 'model' => $x])
@include('partials.chatter', ['activity' => $activity])
@endsection
