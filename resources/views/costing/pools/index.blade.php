@extends('layouts.app')
@section('title', __('التكاليف غير المباشرة'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('محرك التكلفة'), route('costing.index')], [__('التكاليف غير المباشرة'), null]]])
@endsection
@section('content')
@if(auth()->user()->hasPermission('settings.cost_rates'))
<div class="actions" style="margin-bottom:12px">
    <a class="btn" href="{{ route('costing.pools.create') }}">{{ __('وعاء تكاليف صناعية') }}</a>
    <a class="btn ghost" href="{{ route('costing.pools.create', ['kind' => 'SELLING_ADMIN']) }}">{{ __('وعاء مصاريف بيعية وإدارية') }}</a>
</div>
@endif
<p class="hint" style="margin-top:0">{{ __('لكل مركز تكلفة (أو للمصنع كله) وعاء لفترة: التكاليف غير المباشرة المتوقعة ÷ الطاقة العملية لمحرك المركز = معدل التحميل للساعة. ووعاء المصاريف البيعية والإدارية يعطي نسبة التكلفة الكاملة.') }}</p>
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('النوع') }}</th><th>{{ __('المركز') }}</th><th>{{ __('الفترة') }}</th><th>{{ __('المحرك') }}</th><th class="num">{{ __('البنود') }}</th><th class="num">{{ __('المعدل') }}</th><th>{{ __('الحالة') }}</th></tr>
    @forelse($pools as $p)
        <tr class="row-link" onclick="location='{{ route('costing.pools.show', $p) }}'">
            <td><a href="{{ route('costing.pools.show', $p) }}">{{ __("rroka.pool_kind.$p->kind") }}</a></td>
            <td>{{ $p->kind === 'SELLING_ADMIN' ? '—' : ($p->costCenter?->name ?? __('المصنع كله')) }}</td>
            <td class="num">{{ $p->effective_from->format('Y-m-d') }} → {{ $p->period_to->format('Y-m-d') }}</td>
            <td>{{ __("rroka.driver.$p->driver") }}</td>
            <td class="num">{{ number_format((float) $p->lines_sum_amount, 2) }}</td>
            <td class="num">{{ $p->rate !== null ? ($p->kind === 'SELLING_ADMIN' ? number_format($p->rate, 2).'%' : number_format($p->rate, 2)) : '—' }}</td>
            <td>@include('partials.badge', ['s' => $p->status]) @if($p->estimated)<span class="badge b-ON_HOLD">{{ __('تقديري') }}</span>@endif</td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">{{ __('لم تُضف أوعية بعد.') }}</td></tr>
    @endforelse
</table></div></div>
@endsection
