@extends('layouts.app')
@section('title', $a->name)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الخزينة والعهد'), route('treasury.accounts.index')], [$a->name, null]]])
@endsection
@section('content')
@php($u = auth()->user())
@php($canSeeExpenses = $u->hasPermission('expenses.view') || $u->hasPermission('expenses.manage'))
<div class="rec-bar">
    <div class="actions">
        @if($u->hasPermission('treasury.manage'))<a class="btn ghost sm" href="{{ route('treasury.accounts.edit', $a) }}">{{ __('تعديل') }}</a>
            @if($lines->isEmpty() && ! \Illuminate\Support\Facades\DB::table('v_payment_account_lines')->where('account_id', $a->id)->exists())
                <form method="post" action="{{ route('treasury.accounts.destroy', $a) }}" class="inline" data-confirm="{{ __('حذف الحساب؟ لا حركات عليه، والحذف نهائي.') }}">@csrf @method('delete')<button class="btn ghost sm">{{ __('حذف') }}</button></form>
            @endif
        @endif
        @if($a->is_active && $u->hasPermission('expenses.manage'))<a class="btn sm" href="{{ route('expenses.create', ['payment_account_id' => $a->id]) }}">{{ __('مصروف جديد') }}</a>@endif
        @if($a->is_active && $u->hasPermission('treasury.manage'))
            @if($a->isCustody())
                <a class="btn ghost sm" href="{{ route('treasury.transfers.create', ['to' => $a->id]) }}">{{ __('صرف عهدة') }}</a>
                <a class="btn ghost sm" href="{{ route('treasury.transfers.create', ['from' => $a->id]) }}">{{ __('إرجاع المتبقي') }}</a>
            @else
                <a class="btn ghost sm" href="{{ route('treasury.transfers.create', ['from' => $a->id]) }}">{{ __('تحويل منه') }}</a>
            @endif
        @endif
    </div>
    <span class="badge {{ $a->is_active ? 'b-ACTIVE' : 'b-CANCELLED' }}">{{ $a->is_active ? __('نشط') : __('مغلق') }}</span>
</div>
<div class="card">
    <h1 class="rec-title">{{ $a->name }}</h1>
    <p class="rec-sub">{{ __("rroka.account_kind.$a->kind") }}</p>
    <div class="grid g2">
        <dl class="kv">
            @if($a->kind === 'BANK')
                <dt>{{ __('البنك') }}</dt><dd>{{ $a->bank_name ?? '—' }}</dd>
                <dt>{{ __('الآيبان (IBAN)') }}</dt><dd dir="ltr" class="ltr-in">{{ $a->iban ?? '—' }}</dd>
            @endif
            @if($a->isCustody())
                <dt>{{ __('صاحب العهدة') }}</dt><dd>{{ $a->employee?->name }}</dd>
                <dt>{{ __('الحد الأعلى للعهدة') }}</dt><dd>{{ $a->custody_limit ? number_format($a->custody_limit, 2) : __('بلا حد') }}</dd>
            @endif
            <dt>{{ __('خزينة دفترة') }}</dt><dd>{{ $a->daftra_treasury_ref ?? __('غير مربوط بعد') }}</dd>
            @if($a->notes)<dt>{{ __('ملاحظات') }}</dt><dd>{{ $a->notes }}</dd>@endif
        </dl>
        @if($a->isCustody())
            <div class="acct-figure @if($a->summary->custody_balance < 0) bad @endif">
                <span class="label">{{ $a->summary->custody_balance < 0 ? __('مستحق للموظف (دفع من ماله)') : __('في العهدة الآن') }}</span>
                <span class="value num">{{ number_format(abs($a->summary->custody_balance), 2) }}</span>
                <span class="hint">{{ __('ما صُرف له − ما أنفقه بإيصالات معتمدة − ما أرجعه.') }}</span>
                @if($a->summary->draft_out > 0)<span class="hint">{{ __('إيصالات بانتظار الاعتماد: :n', ['n' => number_format($a->summary->draft_out, 2)]) }}</span>@endif
            </div>
        @else
            <div class="acct-figure">
                <span class="label">{{ __('الرصيد') }}</span>
                <span class="value">—</span>
                <span class="hint">{{ __('رصيد هذا الحساب ومطابقته مع كشف البنك في دفترة؛ هنا تُسجَّل المدفوعات والتحويلات فقط، أما التحصيل من العملاء فيُسجَّل في دفترة.') }}</span>
            </div>
        @endif
    </div>
</div>

<div class="card">
    <form method="get" class="inline-form report-bar" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
        <div class="field"><label>{{ __('من') }}</label><input type="date" name="from" value="{{ $from->format('Y-m-d') }}"></div>
        <div class="field"><label>{{ __('إلى') }}</label><input type="date" name="to" value="{{ $to->format('Y-m-d') }}"></div>
        <button class="btn sm">{{ __('عرض') }}</button>
        <a class="btn ghost sm" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}">{{ __('تصدير CSV للمطابقة') }}</a>
    </form>
    <h2>{{ __('كشف الحركات') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('التاريخ') }}</th><th>{{ __('المستند') }}</th><th>{{ __('البيان') }}</th><th>{{ __('الطرف المقابل') }}</th><th class="num">{{ __('وارد') }}</th><th class="num">{{ __('صادر') }}</th>@if($a->isCustody())<th class="num">{{ __('الرصيد') }}</th>@endif<th>{{ __('الحالة') }}</th><th>{{ __('دفترة') }}</th></tr>
        @if($a->isCustody())
            <tr class="grp"><td colspan="6">{{ __('الرصيد في بداية الفترة') }}</td><td class="num">{{ number_format($opening, 2) }}</td><td colspan="2"></td></tr>
        @endif
        @forelse($lines as $l)
            @php($url = $l->source === 'EXPENSE' ? ($canSeeExpenses ? route('expenses.show', $l->source_id) : null) : route('treasury.transfers.show', $l->source_id))
            <tr @class(['draft-line' => $l->status === 'DRAFT'])>
                <td class="num">{{ $l->line_date }}</td>
                <td>@if($url)<a href="{{ $url }}">{{ $l->doc_no }}</a>@else{{ $l->doc_no }}@endif</td>
                <td>{{ $l->source === 'EXPENSE' ? $l->description : (($l->source === 'TRANSFER_IN' ? __('تحويل وارد') : __('تحويل صادر')).($l->description ? ' — '.$l->description : '')) }}</td>
                <td>{{ $l->counterpart ?? '—' }}</td>
                <td class="num stmt-in">{{ $l->amount_in > 0 ? number_format($l->amount_in, 2) : '' }}</td>
                <td class="num stmt-out">{{ $l->amount_out > 0 ? number_format($l->amount_out, 2) : '' }}</td>
                @if($a->isCustody())<td class="num">{{ isset($l->balance) ? number_format($l->balance, 2) : '—' }}</td>@endif
                <td>@include('partials.badge', ['s' => $l->status])</td>
                <td>{!! $l->in_daftra ? '<span class="badge b-SUCCESS">'.e(__('مُرسل')).'</span>' : '<span class="muted">—</span>' !!}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="muted">{{ __('لا حركات في هذه الفترة.') }}</td></tr>
        @endforelse
        <tr class="grp"><td colspan="4">{{ __('مجموع المعتمد في الفترة') }}</td><td class="num">{{ number_format($periodIn, 2) }}</td><td class="num">{{ number_format($periodOut, 2) }}</td>@if($a->isCustody())<td class="num">{{ number_format($opening + $periodIn - $periodOut, 2) }}</td>@endif<td colspan="2"></td></tr>
    </table></div>
    <p class="hint">{{ __('المسودات تظهر بخط مائل ولا تدخل المجاميع ولا الرصيد حتى تُعتمد.') }}</p>
</div>
@include('partials.chatter', ['activity' => $activity])
@endsection
