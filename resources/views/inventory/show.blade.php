@extends('layouts.app')
@section('title', $material->code.' — '.$material->name)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الخامات والمخزون'), route('materials.index')], [$material->code, null]]])
@endsection
@section('content')
@php
    $b = $material->balance;
    $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.') ?: '0';
    $canMove = auth()->user()->hasPermission('inventory.move');
@endphp
<div class="rec-bar">
    <div class="actions">
        @include('partials.delete-button', ['type' => 'materials', 'model' => $material])
        @if($canMove)<a class="btn sm" href="{{ route('materials.edit', $material) }}">{{ __('تعديل') }}</a>@endif
    </div>
    @unless($material->is_active)<span class="badge b-CANCELLED">{{ __('موقوفة') }}</span>@endunless
</div>
<div class="card">
    <h1 class="rec-title">{{ $material->name }}</h1>
    <p class="rec-sub"><bdi dir="ltr">{{ $material->code }}</bdi> · {{ $material->category ?? __('بلا فئة') }} · {{ __('الوحدة') }}: {{ $material->uom }}</p>
    <div class="stats">
        <div class="stat"><div class="k">{{ __('الرصيد') }}</div><div class="v">{{ $q($b?->qty_on_hand ?? 0) }}</div></div>
        <div class="stat"><div class="k">{{ __('المحجوز للمشاريع') }}</div><div class="v">{{ $q($b?->qty_reserved ?? 0) }}</div></div>
        <div class="stat"><div class="k">{{ __('المتاح') }}</div><div class="v">{{ $q($b ? $b->available() : 0) }}</div></div>
        <div class="stat"><div class="k">{{ __('متوسط التكلفة') }}</div><div class="v">{{ $b?->avg_unit_cost === null ? '—' : number_format($b->avg_unit_cost, 2) }}</div></div>
    </div>
</div>

@if($canMove)
<div class="card">
    <h2>{{ __('تسوية المخزون') }}</h2>
    <p class="hint">{{ __('الاستلام يتم من فاتورة المورد المعتمدة فقط. هنا فروق الجرد والتالف والرصيد الافتتاحي (تسوية بالزيادة بسبب «رصيد افتتاحي»).') }}
        @if(auth()->user()->hasPermission('purchases.manage'))<a href="{{ route('purchases.create') }}">{{ __('فاتورة مورد جديدة') }}</a>@endif</p>
    <form method="post" action="{{ route('materials.move', $material) }}" class="grid g3" id="move-form">
        @csrf
        <div class="field"><label>{{ __('النوع *') }}</label>
            <select name="movement_type" id="mtype">
                @foreach(['ADJUST_IN', 'ADJUST_OUT'] as $t)
                    <option value="{{ $t }}" @selected(old('movement_type') === $t)>{{ __("rroka.movement_type.$t") }}</option>
                @endforeach
            </select></div>
        <div class="field"><label>{{ __('الكمية *') }}</label><input name="quantity" type="number" step="0.0001" min="0.0001" value="{{ old('quantity') }}" required dir="ltr"></div>
        <div class="field" id="cost-field"><label>{{ __('تكلفة الوحدة الفعلية *') }}</label><input name="unit_cost" type="number" step="0.0001" min="0" value="{{ old('unit_cost') }}" dir="ltr"><div class="hint">{{ __('قبل الضريبة، من آخر فاتورة شراء أو من الجرد المعتمد.') }}</div></div>
        <div class="field"><label>{{ __('المرجع') }}</label><input name="reference" value="{{ old('reference') }}" placeholder="{{ __('رقم محضر الجرد أو المستند') }}"></div>
        <div class="field"><label>{{ __('السبب') }}</label><input name="reason" value="{{ old('reason') }}" placeholder="{{ __('إلزامي: رصيد افتتاحي، فرق جرد، تالف…') }}" required></div>
        <div class="field"><label>{{ __('التاريخ') }}</label><input name="moved_at" type="datetime-local" value="{{ old('moved_at', now()->format('Y-m-d\TH:i')) }}"></div>
        <div class="actions"><button class="btn">{{ __('تسجيل') }}</button></div>
    </form>
    <p class="hint">{{ __('الحجز والصرف والإرجاع للمشاريع تتم من أمر التصنيع. الحركات لا تُعدَّل ولا تُحذف؛ الخطأ يُصحَّح بحركة عكسية.') }}</p>
    <p class="hint">{{ __('محاسبيًا: الرصيد الافتتاحي يُسجَّل بتاريخ قبل بداية الدفاتر (مثل 2025-12-31) فيدخل ضمن القيد الافتتاحي لا الأرباح؛ وفروق الجرد بعد ذلك تُقيَّد على حساب «فروقات جرد المخزون».') }}</p>
</div>
@endif

<div class="card" style="padding:0">
    <h2 style="padding:16px 16px 0">{{ __('الحركات') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('التاريخ') }}</th><th>{{ __('النوع') }}</th><th class="num">{{ __('الكمية') }}</th><th class="num">{{ __('تكلفة الوحدة') }}</th><th>{{ __('المشروع') }}</th><th>{{ __('أمر التصنيع') }}</th><th>{{ __('المرجع / السبب') }}</th>@if($entries !== null)<th>{{ __('القيد') }}</th>@endif</tr>
        @forelse($movements as $mv)
            <tr>
                <td class="num">{{ $mv->moved_at->format('Y-m-d H:i') }}</td>
                <td>{{ __("rroka.movement_type.$mv->movement_type") }}</td>
                <td class="num">{{ $q($mv->quantity) }}</td>
                <td class="num">{{ $mv->unit_cost === null ? '—' : number_format($mv->unit_cost, 2) }}</td>
                <td>@if($mv->project)<a href="{{ route('projects.show', $mv->project) }}">{{ $mv->project->project_no }}</a>@else — @endif</td>
                <td>@if($mv->productionOrder)<a href="{{ route('production.show', $mv->productionOrder) }}">{{ $mv->productionOrder->order_no }}</a>@else — @endif</td>
                <td>{{ collect([$mv->reference, $mv->reason])->filter()->join(' — ') ?: '—' }}</td>
                @if($entries !== null)<td>@if($je = $entries[$mv->id] ?? null)<a href="{{ route('accounting.journal.show', $je->id) }}" dir="ltr">{{ $je->entry_no }}</a>@endif</td>@endif
            </tr>
        @empty
            <tr><td colspan="8" class="muted">{{ __('لا توجد حركات بعد.') }}</td></tr>
        @endforelse
    </table></div>
</div>
@include('partials.chatter', ['activity' => $activity])
@endsection
@push('scripts')
<script>
(() => {
    const t = document.getElementById('mtype'), cost = document.getElementById('cost-field');
    if (!t) return;
    const sync = () => { cost.hidden = t.value === 'ADJUST_OUT'; cost.querySelector('input').required = t.value !== 'ADJUST_OUT'; };
    t.addEventListener('change', sync); sync();
})();
</script>
@endpush
