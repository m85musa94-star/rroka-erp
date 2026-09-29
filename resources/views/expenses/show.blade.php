@extends('layouts.app')
@section('title', $x->expense_no)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('المصروفات'), route('expenses.index')], [$x->expense_no, null]]])
@endsection
@section('content')
@php($u = auth()->user())
@php($path = $x->status === 'CANCELLED' ? ['DRAFT', 'CANCELLED'] : ['DRAFT', 'APPROVED'])
<div class="rec-bar">
    <div class="actions">
        @if($x->status === 'DRAFT')
            @if($u->hasPermission('expenses.manage'))<a class="btn ghost sm" href="{{ route('expenses.edit', $x) }}">{{ __('تعديل') }}</a>@endif
            @if($u->hasPermission('expenses.approve'))<form method="post" action="{{ route('expenses.approve', $x) }}" class="inline" data-confirm="{{ __('اعتماد المصروف؟ لا يمكن تعديله بعدها.') }}">@csrf<button class="btn ok sm">{{ __('اعتماد') }}</button></form>@endif
            @if($u->hasPermission('expenses.manage'))<form method="post" action="{{ route('expenses.cancel', $x) }}" class="inline" data-confirm="{{ __('إلغاء المسودة؟') }}">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>@endif
        @endif
        @if($x->attachment)<a class="btn ghost sm" href="{{ $x->attachment->url(false) }}" target="_blank" rel="noopener">{{ __('صورة الإيصال') }}</a>@endif
    </div>
    @include('partials.statusbar', ['path' => $path, 'current' => $x->status, 'bad' => ['CANCELLED']])
</div>
<div class="card">
    <h1 class="rec-title">{{ $x->expense_no }}</h1>
    <p class="rec-sub">{{ $x->category->name }} · {{ $x->description }}</p>
    <div class="grid g2">
        <dl class="kv">
            <dt>{{ __('التاريخ') }}</dt><dd>{{ $x->expense_date->format('Y-m-d') }}</dd>
            <dt>{{ __('الجهة') }}</dt><dd>@if($x->supplier)<a href="{{ route('suppliers.show', $x->supplier) }}">{{ $x->supplier->name }}</a>@else{{ $x->payee }}@endif</dd>
            <dt>{{ __('المشروع') }}</dt><dd>@if($x->project)<a href="{{ route('projects.show', $x->project) }}">{{ $x->project->project_no }}</a>@else{{ __('مصروف عام للورشة') }}@endif</dd>
            <dt>{{ __('طريقة الدفع') }}</dt><dd>{{ __("rroka.payment_method.$x->payment_method") }}{{ $x->paidBy ? ' — '.$x->paidBy->name : '' }}</dd>
            <dt>{{ __('المرجع') }}</dt><dd>{{ $x->reference ?? '—' }}</dd>
        </dl>
        <div class="table-wrap"><table>
            <tr><td>{{ __('المبلغ قبل الضريبة') }}</td><td class="num">{{ number_format($x->amount, 2) }}</td></tr>
            <tr><td>{{ __('ضريبة القيمة المضافة (كما في الإيصال)') }}</td><td class="num">{{ number_format($x->vat_amount, 2) }}</td></tr>
            <tr><th>{{ __('الإجمالي') }}</th><th class="num">{{ number_format($x->amount + $x->vat_amount, 2) }}</th></tr>
        </table></div>
    </div>
    <dl class="kv" style="margin-top:10px">
        <dt>{{ __('أدخله') }}</dt><dd>{{ $names[$x->created_by] ?? '—' }}</dd>
        @if($x->approved_at)<dt>{{ __('اعتمده') }}</dt><dd>{{ $names[$x->approved_by] ?? '—' }} — {{ $x->approved_at->format('Y-m-d H:i') }} @if($x->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</dd>@endif
        <dt>{{ __('دفترة') }}</dt><dd>{{ $x->daftra_expense_id ? __('مرتبط (رقم :no)', ['no' => $x->daftra_expense_id]) : __('لم يُرسل — الإرسال موقوف حتى التحقق من ربط دفترة') }}</dd>
    </dl>
    @unless($x->attachment_id)<p class="hint warn-text">{{ __('لا توجد صورة للمستند المؤيد.') }}</p>@endunless
</div>
@include('partials.chatter', ['activity' => $activity])
@endsection
