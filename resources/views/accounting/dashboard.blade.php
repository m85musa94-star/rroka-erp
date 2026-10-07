@extends('layouts.app')
@section('title', __('لوحة المحاسبة'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('المحاسبة'), null], [__('لوحة المحاسبة'), null]]])
@endsection
@section('content')
@php($u = auth()->user())
@php($treasury = $u->hasPermission('treasury.view') || $u->hasPermission('treasury.manage'))
@php($money = fn ($v) => $v === null ? '—' : ($v < 0 ? '('.number_format(abs($v), 2).')' : number_format($v, 2)))
@unless($books['auto'])
    <div class="alert warn">{{ __('الترحيل الآلي متوقف؛ المستندات المعتمدة لا تدخل الدفاتر تلقائيًا.') }} <a href="{{ route('accounting.posting.settings') }}">{{ __('الربط المحاسبي') }}</a></div>
@endunless

<h2 class="section-title">{{ __('الصناديق والبنوك والعهد') }}</h2>
@if($pay->isEmpty())
    <div class="card empty-state"><strong>{{ __('لا توجد خزائن بعد') }}</strong>{{ __('أنشئ الصندوق وكل حساب بنكي وعهدة كل موظف، ثم اربط كلًا منها بحسابه.') }}
        @if($treasury)<a href="{{ route('treasury.accounts.index') }}">{{ __('الصناديق والبنوك والعهد') }}</a>@endif</div>
@else
<div class="journal-grid">
    @foreach($pay as $p)
        <div class="card journal-card">
            <div class="jc-head"><span class="acct-kind k-{{ $p->kind }}">{{ __("rroka.account_kind.$p->kind") }}</span>
                <strong><bdi>{{ $p->name }}</bdi></strong></div>
            <div class="jc-balance">
                @if($p->account_id)
                    <span class="muted">{{ __('الرصيد في الدفاتر') }}</span><span class="jc-amount">{{ $money($p->balance) }}</span>
                @else
                    <span class="badge b-ON_HOLD">{{ __('غير مربوط بحساب') }}</span>
                @endif
            </div>
            @if($p->drafts)<div class="jc-todo">{{ __(':n مستند بانتظار الاعتماد', ['n' => $p->drafts]) }}</div>@endif
            <div class="jc-links">
                @if($treasury)<a href="{{ route('treasury.accounts.show', $p->id) }}">{{ __('كشف الحركات') }}</a>@endif
                @if($p->account_id)<a href="{{ route('accounting.accounts.show', $p->account_id) }}">{{ __('دفتر الأستاذ') }} <bdi dir="ltr">{{ $p->acc_code }}</bdi></a>
                @else<a href="{{ route('accounting.posting.settings') }}">{{ __('ربطه بحساب') }}</a>@endif
            </div>
        </div>
    @endforeach
</div>
@endif

<h2 class="section-title">{{ __('العمليات والدفاتر') }}</h2>
<div class="journal-grid">
    <div class="card journal-card">
        <div class="jc-head"><strong>{{ __('المشتريات') }}</strong></div>
        <div class="jc-balance"><span class="muted">{{ __('المستحق للموردين') }}</span><span class="jc-amount">{{ $money($purchases['payable']) }}</span></div>
        <div class="jc-sub muted">{{ __('معتمد هذا الشهر') }}: {{ $money($purchases['month']) }}</div>
        @if($purchases['drafts'])<div class="jc-todo">{{ __(':n فاتورة بانتظار الاعتماد', ['n' => $purchases['drafts']]) }}</div>@endif
        <div class="jc-links">@if($u->hasPermission('purchases.view') || $u->hasPermission('purchases.manage'))<a href="{{ route('purchases.index') }}">{{ __('فواتير المشتريات') }}</a>@endif
            <a href="{{ route('accounting.posting.reconciliation') }}">{{ __('مطابقة الموردين') }}</a></div>
    </div>
    <div class="card journal-card">
        <div class="jc-head"><strong>{{ __('المصروفات') }}</strong></div>
        <div class="jc-balance"><span class="muted">{{ __('معتمد هذا الشهر') }}</span><span class="jc-amount">{{ $money($expenses['month']) }}</span></div>
        @if($expenses['drafts'])<div class="jc-todo">{{ __(':n مصروف بانتظار الاعتماد', ['n' => $expenses['drafts']]) }}</div>@endif
        <div class="jc-links">@if($u->hasPermission('expenses.view') || $u->hasPermission('expenses.manage'))<a href="{{ route('expenses.index') }}">{{ __('المصروفات') }}</a>@endif
            <a href="{{ route('accounting.reports.show', 'profit-loss') }}">{{ __('قائمة الدخل') }}</a></div>
    </div>
    <div class="card journal-card">
        <div class="jc-head"><strong>{{ __('الدفاتر') }}</strong> @include('partials.badge', ['s' => $books['period']])</div>
        <div class="jc-balance"><span class="muted">{{ __('قيود مرحَّلة هذا الشهر') }}</span><span class="jc-amount">{{ $books['posted_month'] }}</span></div>
        @if($books['backlog'])<div class="jc-todo bad"><a href="{{ route('accounting.posting.backlog') }}">{{ __(':n مستند معتمد لم يُرحَّل', ['n' => $books['backlog']]) }}</a></div>@endif
        @if($books['drafts'])<div class="jc-todo"><a href="{{ route('accounting.journal.index', ['f' => ['draft']]) }}">{{ __(':n قيد في المسودة', ['n' => $books['drafts']]) }}</a></div>@endif
        <div class="jc-links"><a href="{{ route('accounting.journal.index') }}">{{ __('القيود') }}</a>
            <a href="{{ route('accounting.posting.reconciliation') }}">{{ __('مطابقة الدفاتر') }}</a>
            <a href="{{ route('accounting.reports.index') }}">{{ __('التقارير المالية') }}</a>
            <a href="{{ route('accounting.accounts.groups') }}">{{ __('مجموعات الحسابات') }}</a></div>
    </div>
</div>
@endsection
