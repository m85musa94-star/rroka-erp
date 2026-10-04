@extends('layouts.app')
@section('title', __("rroka.pool_kind.$pool->kind"))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('التكاليف غير المباشرة'), route('costing.pools.index')], [$pool->exists ? __('تعديل') : __('جديد'), null]]])
@endsection
@section('content')
@php($v = fn ($k) => old($k, $pool->{$k} instanceof \Carbon\CarbonInterface ? $pool->{$k}->format('Y-m-d') : $pool->{$k}))
@php($sa = $pool->kind === 'SELLING_ADMIN')
<form method="post" action="{{ $pool->exists ? route('costing.pools.update', $pool) : route('costing.pools.store') }}" class="card">
    @csrf
    @if($pool->exists) @method('put') @else <input type="hidden" name="kind" value="{{ $pool->kind }}"> @endif
    <h2>{{ __("rroka.pool_kind.$pool->kind") }}</h2>
    <div class="grid g3">
        @unless($sa)
            <div class="field"><label>{{ __('مركز التكلفة') }}</label>
                @if($pool->exists)<input value="{{ $pool->costCenter?->name ?? __('المصنع كله') }}" disabled>
                @else<select name="cost_center_id" id="center"><option value="">{{ __('المصنع كله (عام)') }}</option>@foreach($centers as $c)<option value="{{ $c->id }}" data-driver="{{ $c->driver }}" @selected((string) $v('cost_center_id') === (string) $c->id)>{{ $c->name }} — {{ __("rroka.driver.$c->driver") }}</option>@endforeach</select>@endif
                <div class="hint">{{ __('كهرباء المصنع العامة تُسجَّل في وعاء المصنع كله فقط.') }}</div></div>
            <div class="field"><label>{{ __('المحرك *') }}</label>
                <select name="driver" id="driver" required>@foreach(['LABOR_HOURS', 'MACHINE_HOURS'] as $d)<option value="{{ $d }}" @selected($v('driver') === $d)>{{ __("rroka.driver.$d") }}</option>@endforeach</select>
                <div class="hint">{{ __('وعاء المركز يأخذ محرك المركز تلقائيًا.') }}</div></div>
        @else
            <input type="hidden" name="driver" value="PCT_OF_MANUFACTURING_COST">
        @endunless
        <div class="field"><label>{{ __('من *') }}</label><input type="date" name="effective_from" value="{{ $v('effective_from') }}" required></div>
        <div class="field"><label>{{ __('إلى *') }}</label><input type="date" name="period_to" value="{{ $v('period_to') }}" required></div>
    </div>
    <div class="grid g3">
        @if($sa)
            <div class="field"><label>{{ __('تكلفة التصنيع المتوقعة للفترة *') }}</label><input name="budgeted_manufacturing_cost" type="number" step="0.01" min="0.01" value="{{ $v('budgeted_manufacturing_cost') }}" required dir="ltr">
                <div class="hint">{{ __('النسبة = المصاريف البيعية والإدارية ÷ تكلفة التصنيع المتوقعة.') }}</div></div>
        @else
            <div class="field"><label>{{ __('الطاقة النظرية للمحرك (ساعات)') }}</label><input name="theoretical_capacity" type="number" step="0.01" min="0.01" value="{{ $v('theoretical_capacity') }}" dir="ltr"></div>
            <div class="field"><label>{{ __('الطاقة العملية للمحرك (ساعات) *') }}</label><input name="practical_capacity" type="number" step="0.01" min="0.01" value="{{ $v('practical_capacity') }}" required dir="ltr">
                <div class="hint">{{ __('ساعات العمل المباشر أو ساعات الآلات المتوقعة فعلًا في الفترة؛ يُقسم عليها مجموع الوعاء.') }}</div></div>
        @endif
    </div>
    @include('costing._fields', ['r' => $pool])
    <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
@push('scripts')
<script>
(() => { const c = document.getElementById('center'), d = document.getElementById('driver'); if (!c || !d) return;
    const sync = () => { const dr = c.selectedOptions[0]?.dataset.driver; if (dr) { d.value = dr; } d.disabled = !!dr; };
    c.addEventListener('change', sync); sync();
    c.form.addEventListener('submit', () => { d.disabled = false; }); })();
</script>
@endpush
