@extends('layouts.app')
@section('title', 'عرض السعر '.$q->quotation_no)
@section('content')
@php($u = auth()->user())
<div class="card">
    <div class="actions" style="margin-bottom:14px">
        @include('partials.badge', ['s' => $q->status])
        @if($q->status === 'DRAFT' && $u->hasPermission('quotations.manage'))
            <a class="btn ghost sm" href="{{ route('quotations.edit', $q) }}">تعديل</a>
            <form method="post" action="{{ route('quotations.transition', [$q, 'send']) }}" class="inline" onsubmit="return confirm('بعد الإرسال يُجمَّد العرض ولا يُعدَّل إلا بإعادته إلى مسودة. متابعة؟')">@csrf<button class="btn sm">تسجيل الإرسال للعميل</button></form>
        @endif
        @if($q->status === 'SENT')
            @if($u->hasPermission('quotations.approve'))
                <form method="post" action="{{ route('quotations.transition', [$q, 'approve']) }}" class="inline" onsubmit="return confirm('اعتماد العرض بموافقة العميل؟')">@csrf<button class="btn ok sm">اعتماد (وافق العميل)</button></form>
                <form method="post" action="{{ route('quotations.transition', [$q, 'reject']) }}" class="inline" onsubmit="return confirm('تسجيل رفض العميل؟')">@csrf<button class="btn bad sm">رفض العميل</button></form>
            @endif
            @if($u->hasPermission('quotations.manage'))
                <form method="post" action="{{ route('quotations.transition', [$q, 'revise']) }}" class="inline">@csrf<button class="btn ghost sm">إعادة إلى مسودة</button></form>
            @endif
        @endif
        @if(in_array($q->status, ['DRAFT', 'SENT']) && $u->hasPermission('quotations.manage'))
            <form method="post" action="{{ route('quotations.transition', [$q, 'cancel']) }}" class="inline" onsubmit="return confirm('إلغاء العرض نهائيًا؟')">@csrf<button class="btn ghost sm">إلغاء العرض</button></form>
        @endif
        @if($q->status === 'APPROVED' && ! $q->project && $u->hasPermission('projects.manage'))
            <a class="btn sm" href="{{ route('projects.create', ['quotation_id' => $q->id]) }}">إنشاء مشروع</a>
        @endif
        @if($q->project)
            <a class="btn ghost sm" href="{{ route('projects.show', $q->project) }}">المشروع {{ $q->project->project_no }}</a>
        @endif
        @if(in_array($q->status, ['SENT', 'APPROVED']) && ! $q->daftra_estimate_id && $u->hasPermission('daftra.sync'))
            <form method="post" action="{{ route('quotations.sync', $q) }}" class="inline">@csrf<button class="btn ghost sm">إنشاء في دفترة</button></form>
        @endif
        <button class="btn ghost sm" onclick="window.print()">طباعة</button>
    </div>
    <dl class="kv">
        <dt>العميل</dt><dd><a href="{{ route('clients.show', $q->client) }}">{{ $q->client->business_name }}</a></dd>
        <dt>تاريخ الإصدار</dt><dd>{{ $q->issue_date->format('Y-m-d') }}</dd>
        <dt>صالح حتى</dt><dd>{{ $q->valid_until?->format('Y-m-d') ?? '—' }}</dd>
        @if($q->approved_at)<dt>اعتُمد</dt><dd>{{ $q->approved_at->format('Y-m-d H:i') }} — {{ $approver }}</dd>@endif
        <dt>دفترة</dt><dd>{{ $q->daftra_estimate_id ? "مرتبط (رقم {$q->daftra_estimate_id})" : 'غير مرتبط' }}</dd>
        @if($q->notes)<dt>ملاحظات</dt><dd>{{ $q->notes }}</dd>@endif
    </dl>
</div>

<div class="card">
    <h2>البنود</h2>
    <div class="table-wrap"><table>
        <tr><th>#</th><th>الوصف</th><th class="num">الكمية</th><th>الوحدة</th><th class="num">سعر الوحدة</th><th class="num">الإجمالي</th></tr>
        @foreach($q->lines as $l)
            <tr><td>{{ $l->line_no }}</td><td>{{ $l->description }}</td><td class="num">{{ rtrim(rtrim($l->quantity, '0'), '.') }}</td>
                <td>{{ $l->unit }}</td><td class="num">{{ number_format($l->unit_price, 2) }}</td><td class="num">{{ number_format($l->line_total, 2) }}</td></tr>
        @endforeach
        <tr><td colspan="5">المجموع</td><td class="num">{{ number_format($totals['subtotal'], 2) }}</td></tr>
        <tr><td colspan="5">الخصم</td><td class="num">{{ number_format($totals['discount_amount'], 2) }}</td></tr>
        <tr><th colspan="5">الصافي قبل ضريبة القيمة المضافة</th><th class="num">{{ number_format($totals['net_before_vat'], 2) }}</th></tr>
    </table></div>
    <p class="hint">ضريبة القيمة المضافة والفاتورة الرسمية تصدران من دفترة.</p>
</div>

@include('partials.sync-log', ['log' => $syncLog])
@endsection
