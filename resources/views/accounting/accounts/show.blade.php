@extends('layouts.app')
@section('title', $a->code.' — '.$a->label())
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('دليل الحسابات'), route('accounting.accounts.index')], [$a->code.' — '.$a->label(), null]]])
@endsection
@section('content')
<div class="card">
    <h1 class="rec-title">{{ $a->code }} — {{ $a->label() }}</h1>
    @if($a->is_postable && auth()->user()->hasPermission('accounting.manage'))<div class="actions" style="float:inline-end"><a class="btn ghost sm" href="{{ route('accounting.accounts.edit', $a) }}">{{ __('إعدادات الحساب') }}</a></div>@endif
    <p class="rec-sub">{{ $a->detail_type ? __("rroka.detail_type.$a->detail_type") : __("rroka.account_type.$a->account_type") }}
        @if($a->parent) · {{ __('تحت') }} <a href="{{ route('accounting.accounts.show', $a->parent) }}">{{ $a->parent->code }} — {{ $a->parent->label() }}</a>@endif
        @if($isGroup) · {{ __('حساب تجميعي: يعرض حركة كل الحسابات تحته') }}@endif
        @if($a->system_role) · {{ __("rroka.account_role.$a->system_role") }}@endif</p>
    <form method="get" class="grid g4" style="align-items:end">
        <div class="field"><label>{{ __('من') }}</label><input type="date" name="from" value="{{ $from }}"></div>
        <div class="field"><label>{{ __('إلى') }}</label><input type="date" name="to" value="{{ $to }}"></div>
        <div class="actions"><button class="btn ghost">{{ __('عرض') }}</button>
            <a class="btn ghost" href="{{ route('accounting.accounts.show', [$a, 'from' => $from, 'to' => $to, 'export' => 'csv']) }}">{{ __('تصدير CSV') }}</a></div>
    </form>
</div>
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('التاريخ') }}</th><th>{{ __('رقم القيد') }}</th>@if($isGroup)<th>{{ __('الحساب') }}</th>@endif<th>{{ __('البيان') }}</th><th>{{ __('المشروع') }}</th><th class="num">{{ __('مدين') }}</th><th class="num">{{ __('دائن') }}</th><th class="num">{{ __('الرصيد') }}</th></tr>
    <tr class="grp"><td colspan="{{ $isGroup ? 7 : 6 }}">{{ __('رصيد أول المدة') }} ({{ $from }})</td><td class="num">{{ number_format($opening, 2) }}</td></tr>
    @forelse($lines as $l)
        <tr>
            <td class="num">{{ $l->entry_date }}</td>
            <td><a href="{{ route('accounting.journal.show', $l->entry_id) }}" dir="ltr">{{ $l->entry_no }}</a></td>
            @if($isGroup)<td class="num" dir="ltr">{{ $l->account_code }}</td>@endif
            <td>{{ $l->description ?: $l->entry_description }}</td>
            <td>{{ $l->project_no ?? '' }}</td>
            <td class="num">{{ (float) $l->debit ? number_format($l->debit, 2) : '' }}</td>
            <td class="num">{{ (float) $l->credit ? number_format($l->credit, 2) : '' }}</td>
            <td class="num">{{ number_format($l->balance, 2) }}</td>
        </tr>
    @empty
        <tr><td colspan="{{ $isGroup ? 8 : 7 }}" class="muted">{{ __('لا توجد قيود مرحَّلة في هذه الفترة.') }}</td></tr>
    @endforelse
    <tr class="grp"><td colspan="{{ $isGroup ? 5 : 4 }}">{{ __('الحركة وأرصدة آخر المدة') }} ({{ $to }})</td>
        <td class="num">{{ number_format($lines->sum('debit'), 2) }}</td><td class="num">{{ number_format($lines->sum('credit'), 2) }}</td><td class="num">{{ number_format($closing, 2) }}</td></tr>
</table></div></div>
<p class="hint">{{ __('القيود المرحَّلة فقط. الرصيد بطبيعة الحساب: موجب = في جانبه الطبيعي.') }}</p>
@include('partials.chatter', ['activity' => $activity])
@endsection
