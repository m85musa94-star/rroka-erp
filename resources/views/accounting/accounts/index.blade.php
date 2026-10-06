@extends('layouts.app')
@section('title', __('دليل الحسابات'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('دليل الحسابات'), null]], 'lv' => $lv,
        'newUrl' => ! $empty && auth()->user()->hasPermission('accounting.manage') ? route('accounting.accounts.create') : null,
        'paginator' => $accounts, 'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث بالرمز أو اسم الحساب…'),
    ])
@endsection
@section('content')
@php($canEdit = auth()->user()->hasPermission('accounting.manage'))
@php($bal = fn ($a) => $a->account_type === 'ASSET' || $a->account_type === 'EXPENSE' ? (float) $a->debit - (float) $a->credit : (float) $a->credit - (float) $a->debit)
@if($empty)
<div class="card empty-state">
    <strong>{{ __('دليل الحسابات فارغ') }}</strong>
    {{ __('يمكن البدء بدليل مقترح لورشة تصنيع أثاث (أصول، خصوم، حقوق ملكية، إيرادات، تكاليف ومصروفات)، ثم مراجعته وتعديله. الحسابات البنكية تضاف حسابًا لكل بنك فعلي تحت «البنوك».') }}
    @if($canEdit)
        <form method="post" action="{{ route('accounting.accounts.template') }}" data-confirm="{{ __('إنشاء الدليل المقترح؟ يمكن تعديله بعد ذلك.') }}" style="margin-top:12px">@csrf<button class="btn">{{ __('إنشاء الدليل المقترح') }}</button></form>
    @endif
</div>
@else
@if($untyped)
    <div class="alert warn">{{ __(':n حساب بلا نوع؛ حدّد نوع كل حساب ليظهر في مكانه الصحيح من القوائم المالية.', ['n' => $untyped]) }}
        <a href="{{ $lv->url(['f' => ['untyped'], 'page' => null]) }}">{{ __('عرضها') }}</a></div>
@endif
@php($rows = $accounts ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state"><strong>{{ __('لا نتائج مطابقة') }}</strong>{{ __('غيّر البحث أو أزل الفلاتر.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table class="o-list">
    <tr><th style="width:110px">{{ __('الرمز') }}</th><th>{{ __('اسم الحساب') }}</th><th>{{ __('النوع') }}</th><th class="center">{{ __('تسمح بالتسوية') }}</th>
        <th class="num">{{ __('مدين') }}</th><th class="num">{{ __('دائن') }}</th><th class="num">{{ __('الرصيد') }}</th><th></th></tr>
    @foreach($groups ?? ['' => $accounts] as $title => $items)
        @if($groups)
            <tr class="grp"><td colspan="4">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td>
                <td class="num">{{ number_format($items->sum('debit'), 2) }}</td><td class="num">{{ number_format($items->sum('credit'), 2) }}</td><td colspan="2"></td></tr>
        @endif
        @foreach($items as $a)
            <tr class="row-link" @class(['muted' => ! $a->is_active]) onclick="location='{{ $canEdit ? route('accounting.accounts.edit', $a) : route('accounting.accounts.show', $a) }}'">
                <td class="num" dir="ltr">{{ $a->code }}</td>
                <td>{{ $a->label() }} @if($a->system_role)<span class="badge" title="{{ __('دور نظامي') }}">{{ __("rroka.account_role.$a->system_role") }}</span>@endif
                    @unless($a->is_active)<span class="badge b-CANCELLED">{{ __('مؤرشف') }}</span>@endunless</td>
                <td>@if($a->detail_type){{ __("rroka.detail_type.$a->detail_type") }}@else<span class="badge b-ON_HOLD">{{ __('بلا نوع') }}</span>@endif</td>
                <td class="center">@if($a->reconcile)<span aria-label="{{ __('نعم') }}">✓</span>@endif</td>
                <td class="num">{{ $a->debit !== null ? number_format($a->debit, 2) : '' }}</td>
                <td class="num">{{ $a->credit !== null ? number_format($a->credit, 2) : '' }}</td>
                <td class="num">{{ $a->debit !== null ? number_format($bal($a), 2) : '—' }}</td>
                <td><a class="btn ghost sm" href="{{ route('accounting.accounts.show', $a) }}" onclick="event.stopPropagation()">{{ __('دفتر الأستاذ') }}</a></td>
            </tr>
        @endforeach
    @endforeach
</table></div></div>
<p class="hint">{{ __('الأرصدة من القيود المرحَّلة فقط، بطبيعة الحساب. الحسابات التجميعية في «مجموعات الحسابات».') }}
    <a href="{{ route('accounting.accounts.groups') }}">{{ __('مجموعات الحسابات') }}</a></p>
@endif
@endif
@endsection
