@extends('layouts.app')
@section('title', __('الصناديق والبنوك والعهد'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('الخزينة والعهد'), null]], 'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('treasury.manage') ? route('treasury.accounts.create') : null,
        'paginator' => null, 'total' => ($accounts ?? $groups?->flatten())->count(),
        'placeholder' => __('بحث بالاسم أو البنك أو الموظف…'),
    ])
@endsection
@section('content')
@php($u = auth()->user())
@php($rows = $accounts ?? $groups->flatten())
<p class="hint" style="margin-top:0">{{ __('الرصيد يظهر للعهد فقط لأن كل حركاتها تُسجَّل هنا. رصيد الصندوق والبنك ومطابقتهما في دفترة، لأن التحصيل من العملاء يُسجَّل هناك.') }}</p>
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لم تُضف حسابات بعد') }}</strong>{{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('أضف صندوق الورشة والحساب البنكي وعهدة كل موظف يصرف نقدًا.') }}</div>
@elseif($lv->view === 'kanban' && ! $groups)
    <div class="acct-cards">
        @foreach($rows as $a)
            @php($sum = $a->summary)
            <div class="acct-card">
                <a class="acct-head" href="{{ route('treasury.accounts.show', $a) }}">
                    <span class="acct-kind k-{{ $a->kind }}">{{ __("rroka.account_kind.$a->kind") }}</span>
                    <span class="acct-name">{{ $a->name }}</span>
                    @if($a->kind === 'BANK' && $a->bank_name)<span class="muted">{{ $a->bank_name }}</span>@endif
                    @if($a->employee)<span class="muted">{{ $a->employee->name }}</span>@endif
                </a>
                @if($a->isCustody())
                    <div class="acct-figure @if($sum->custody_balance < 0) bad @endif">
                        <span class="label">{{ $sum->custody_balance < 0 ? __('مستحق للموظف (دفع من ماله)') : __('في العهدة الآن') }}</span>
                        <span class="value num">{{ number_format(abs($sum->custody_balance), 2) }}</span>
                        @if($a->custody_limit)<span class="hint">{{ __('الحد الأعلى: :n', ['n' => number_format($a->custody_limit, 2)]) }}</span>@endif
                    </div>
                @else
                    <div class="acct-figure">
                        <span class="label">{{ __('مدفوعات معتمدة مسجلة هنا') }}</span>
                        <span class="value num">{{ number_format($sum->approved_out, 2) }}</span>
                        <span class="hint">{{ __('الرصيد والمطابقة في دفترة') }}</span>
                    </div>
                @endif
                <div class="acct-flags">
                    @if($sum->drafts)<a class="badge b-DRAFT" href="{{ route('treasury.accounts.show', $a) }}">{{ __(':n مسودة', ['n' => $sum->drafts]) }}</a>@endif
                    @if($sum->not_in_daftra)<span class="badge b-ON_HOLD">{{ __(':n لم تُرسل لدفترة', ['n' => $sum->not_in_daftra]) }}</span>@endif
                    @unless($a->daftra_treasury_ref)<span class="badge b-CANCELLED">{{ __('غير مربوط بخزينة دفترة') }}</span>@endunless
                </div>
                <div class="acct-actions">
                    @if($u->hasPermission('expenses.manage'))<a class="btn sm" href="{{ route('expenses.create', ['payment_account_id' => $a->id]) }}">{{ __('مصروف') }}</a>@endif
                    @if($u->hasPermission('treasury.manage'))
                        @if($a->isCustody())
                            <a class="btn ghost sm" href="{{ route('treasury.transfers.create', ['to' => $a->id]) }}">{{ __('صرف عهدة') }}</a>
                            <a class="btn ghost sm" href="{{ route('treasury.transfers.create', ['from' => $a->id]) }}">{{ __('إرجاع') }}</a>
                        @else
                            <a class="btn ghost sm" href="{{ route('treasury.transfers.create', ['from' => $a->id]) }}">{{ __('تحويل') }}</a>
                        @endif
                    @endif
                    <a class="btn ghost sm" href="{{ route('treasury.accounts.show', $a) }}">{{ __('كشف الحركات') }}</a>
                </div>
            </div>
        @endforeach
    </div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الحساب') }}</th><th>{{ __('النوع') }}</th><th>{{ __('الموظف / البنك') }}</th><th class="num">{{ __('في العهدة') }}</th><th class="num">{{ __('مدفوعات معتمدة') }}</th><th class="num">{{ __('مسودات') }}</th><th>{{ __('دفترة') }}</th></tr>
    @foreach($groups ?? ['' => $accounts] as $title => $items)
        @if($groups)<tr class="grp"><td colspan="7">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td></tr>@endif
        @foreach($items as $a)
            <tr class="row-link" onclick="location='{{ route('treasury.accounts.show', $a) }}'">
                <td><a href="{{ route('treasury.accounts.show', $a) }}">{{ $a->name }}</a> @unless($a->is_active)<span class="badge b-CANCELLED">{{ __('مغلق') }}</span>@endunless</td>
                <td>{{ __("rroka.account_kind.$a->kind") }}</td>
                <td>{{ $a->employee?->name ?? $a->bank_name ?? '—' }}</td>
                <td class="num @if($a->isCustody() && $a->summary->custody_balance < 0) warn-text @endif">{{ $a->isCustody() ? number_format($a->summary->custody_balance, 2) : '—' }}</td>
                <td class="num">{{ number_format($a->summary->approved_out, 2) }}</td>
                <td class="num">{{ $a->summary->drafts ?: '—' }}</td>
                <td>{!! $a->daftra_treasury_ref ? '<span class="badge b-SUCCESS">'.e(__('مربوط')).'</span>' : '<span class="muted">—</span>' !!}</td>
            </tr>
        @endforeach
    @endforeach
</table></div></div>
@endif
@endsection
