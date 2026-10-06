@extends('layouts.app')
@section('title', __('مجموعات الحسابات'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('دليل الحسابات'), route('accounting.accounts.index')], [__('مجموعات الحسابات'), null]]])
@endsection
@section('content')
@php($canEdit = auth()->user()->hasPermission('accounting.manage'))
@if(! $rows)
    <div class="card empty-state"><strong>{{ __('لا توجد مجموعات بعد') }}</strong>{{ __('المجموعة تجمع حسابات متقاربة (مثل «البنوك» أو «المصروفات العمومية») ليُعرض مجموعها.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th style="width:110px">{{ __('الرمز') }}</th><th>{{ __('المجموعة') }}</th><th>{{ __('التصنيف الرئيسي') }}</th><th class="num">{{ __('حسابات') }}</th><th class="num">{{ __('الرصيد') }}</th><th></th></tr>
    @foreach($rows as $r)
        @php($g = $r['a'])
        <tr>
            <td class="num" dir="ltr">{{ $g->code }}</td>
            <td style="padding-inline-start: {{ 12 + $r['depth'] * 22 }}px"><a href="{{ route('accounting.accounts.index', ['g' => 'group']) }}">{{ $g->label() }}</a></td>
            <td>{{ __("rroka.account_type.$g->account_type") }}</td>
            <td class="num">{{ $counts[$g->id] ?? 0 }}</td>
            <td class="num">{{ $r['debit'] || $r['credit'] ? number_format($r['balance'], 2) : '—' }}</td>
            <td class="actions" style="justify-content:flex-end"><a class="btn ghost sm" href="{{ route('accounting.accounts.show', $g) }}">{{ __('دفتر الأستاذ') }}</a>
                @if($canEdit)<a class="btn ghost sm" href="{{ route('accounting.accounts.groups', ['edit' => $g->id]) }}#group-form">{{ __('تعديل') }}</a>
                <a class="btn ghost sm" href="{{ route('accounting.accounts.create') }}">{{ __('+ حساب') }}</a>@endif</td>
        </tr>
    @endforeach
</table></div></div>
@endif
@if($canEdit)
@php($e = $edit)
<form id="group-form" method="post" action="{{ $e ? route('accounting.accounts.groups.update', $e) : route('accounting.accounts.groups.store') }}" class="card">@csrf
    @if($e) @method('put') @endif
    <h2>{{ $e ? __('تعديل المجموعة :code', ['code' => $e->code]) : __('مجموعة جديدة') }}</h2>
    <div class="grid g4">
        <div class="field"><label>{{ __('الرمز *') }}</label><input name="code" required dir="ltr" inputmode="numeric" value="{{ old('code', $e?->code) }}"></div>
        <div class="field"><label>{{ __('الاسم *') }}</label><input name="name" required value="{{ old('name', $e?->name) }}"></div>
        <div class="field"><label>{{ __('الاسم بالإنجليزية') }}</label><input name="name_en" dir="ltr" value="{{ old('name_en', $e?->name_en) }}"></div>
        <div class="field"><label>{{ __('التصنيف الرئيسي *') }}</label>
            <select name="account_type" required>@foreach(\App\Models\Account::TYPES as $t)<option value="{{ $t }}" @selected(old('account_type', $e?->account_type) === $t)>{{ __("rroka.account_type.$t") }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('تحت المجموعة') }}</label>
            <select name="parent_id"><option value="">{{ __('— مجموعة رئيسية —') }}</option>@foreach($groupList as $g)@continue($e && $g->id === $e->id)<option value="{{ $g->id }}" @selected((string) old('parent_id', $e?->parent_id) === (string) $g->id)>{{ $g->code }} {{ $g->label() }}</option>@endforeach</select></div>
    </div>
    <div class="actions"><button class="btn">{{ $e ? __('حفظ') : __('إضافة') }}</button>@if($e)<a class="btn ghost" href="{{ route('accounting.accounts.groups') }}">{{ __('إلغاء') }}</a>@endif</div>
</form>
@endif
@endsection
