@extends('layouts.app')
@section('title', $s->name)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الموردون'), route('suppliers.index')], [$s->name, null]]])
@endsection
@section('content')
@php($u = auth()->user())
<div class="rec-bar">
    <div class="actions">
        @include('partials.delete-button', ['type' => 'suppliers', 'model' => $s])
        @if($u->hasPermission('purchases.manage'))
            <a class="btn sm" href="{{ route('suppliers.edit', $s) }}">{{ __('تعديل') }}</a>
            <a class="btn ghost sm" href="{{ route('purchases.create', ['supplier_id' => $s->id]) }}">{{ __('فاتورة مورد جديدة') }}</a>
        @endif
    </div>
    <span class="badge {{ $s->daftra_supplier_id ? 'b-SUCCESS' : '' }}">{{ $s->daftra_supplier_id ? __('مرتبط بدفترة') : __('غير مرتبط بدفترة') }}</span>
</div>
<div class="card">
    <h1 class="rec-title">{{ $s->name }}</h1>
    <p class="rec-sub">{{ $s->supplier_no }}</p>
    <div class="grid g2">
        <dl class="kv">
            <dt>{{ __('الرقم الضريبي') }}</dt><dd>{{ $s->vat_number ?? '—' }}</dd>
            <dt>{{ __('السجل التجاري') }}</dt><dd>{{ $s->commercial_reg_no ?? '—' }}</dd>
            <dt>{{ __('الآيبان (IBAN)') }}</dt><dd dir="ltr" class="ltr-in">{{ $s->iban ?? '—' }}</dd>
        </dl>
        <dl class="kv">
            <dt>{{ __('الجوال') }}</dt><dd dir="ltr" class="ltr-in">{{ $s->phone ?? '—' }}</dd>
            <dt>{{ __('البريد') }}</dt><dd>{{ $s->email ?? '—' }}</dd>
            <dt>{{ __('العنوان') }}</dt><dd>{{ collect([$s->city, $s->address])->filter()->join(__('، ')) ?: '—' }}</dd>
        </dl>
    </div>
</div>
<div class="card" style="padding:0">
    <h2 style="padding:16px 16px 0">{{ __('فواتير المورد') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الرقم') }}</th><th>{{ __('فاتورة المورد') }}</th><th>{{ __('التاريخ') }}</th><th class="num">{{ __('الصافي قبل الضريبة') }}</th><th class="num">{{ __('الإجمالي') }}</th><th>{{ __('الحالة') }}</th></tr>
        @forelse($invoices as $p)
            <tr class="row-link" onclick="location='{{ route('purchases.show', $p) }}'"><td><a href="{{ route('purchases.show', $p) }}">{{ $p->purchase_no }}</a></td><td>{{ $p->supplier_invoice_no }}</td>
                <td class="num">{{ $p->invoice_date->format('Y-m-d') }}</td><td class="num">{{ number_format($p->net_before_vat, 2) }}</td><td class="num">{{ number_format($p->total, 2) }}</td><td>@include('partials.badge', ['s' => $p->status])</td></tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('لا فواتير بعد.') }}</td></tr>
        @endforelse
    </table></div>
</div>
@include('partials.chatter', ['activity' => $activity])
@endsection
