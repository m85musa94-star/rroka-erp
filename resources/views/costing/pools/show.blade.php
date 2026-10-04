@extends('layouts.app')
@section('title', __("rroka.pool_kind.$pool->kind"))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('التكاليف غير المباشرة'), route('costing.pools.index')], [($pool->kind === 'SELLING_ADMIN' ? __('بيعية وإدارية') : ($pool->costCenter?->name ?? __('المصنع كله'))).' — v'.$pool->version, null]]])
@endsection
@section('content')
@php($canEdit = auth()->user()->hasPermission('settings.cost_rates') && $pool->isDraft())
@php($sa = $pool->kind === 'SELLING_ADMIN')
@include('costing._record-bar', ['type' => 'pools', 'r' => $pool, 'edit' => route('costing.pools.edit', $pool)])
<div class="card">
    <h1 class="rec-title">{{ __("rroka.pool_kind.$pool->kind") }}</h1>
    <p class="rec-sub">{{ $sa ? '' : ($pool->costCenter?->name ?? __('المصنع كله')).' · ' }}{{ $pool->effective_from->format('Y-m-d') }} → {{ $pool->period_to->format('Y-m-d') }} · {{ __("rroka.driver.$pool->driver") }}</p>
    <div class="grid g2">
        <div class="table-wrap"><table>
            <tr><td>{{ __('مجموع البنود') }}</td><td class="num">{{ number_format($preview['gross'], 2) }}</td></tr>
            @if($preview['energy'] > 0)<tr><td>− {{ __('كهرباء الآلات (محمّلة في تكلفة ساعاتها)') }}</td><td class="num">{{ number_format($preview['energy'], 2) }}</td></tr>@endif
            <tr><td>{{ __('الصافي') }}</td><td class="num">{{ number_format($preview['net'], 2) }}</td></tr>
            <tr><td>{{ $sa ? __('÷ تكلفة التصنيع المتوقعة') : __('÷ الطاقة العملية (ساعات)') }}</td><td class="num">{{ number_format($sa ? $pool->budgeted_manufacturing_cost : $pool->practical_capacity, 2) }}</td></tr>
            <tr><th>{{ $sa ? __('نسبة المصاريف البيعية والإدارية') : __('معدل التحميل للساعة') }}</th>
                <th class="num">{{ $preview['rate'] !== null ? number_format($preview['rate'], $sa ? 2 : 4).($sa ? '%' : '') : '—' }}</th></tr>
        </table></div>
        <div class="hint">
            @if($pool->isDraft())<p>{{ __('أرقام معاينة؛ تُحسب وتُجمَّد عند الاعتماد.') }}</p>@else<p>{{ __('أرقام مجمّدة عند الاعتماد.') }}</p>@endif
            @if($pool->theoretical_capacity)<p>{{ __('الطاقة النظرية: :t ساعة — العملية: :p ساعة', ['t' => number_format($pool->theoretical_capacity, 0), 'p' => number_format($pool->practical_capacity, 0)]) }}</p>@endif
        </div>
    </div>
    @include('costing._record-meta', ['r' => $pool])
</div>
<div class="card">
    <h2>{{ __('بنود الوعاء') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الفئة') }}</th><th>{{ __('الوصف') }}</th><th>{{ __('مرتبط بـ') }}</th><th class="num">{{ __('المبلغ المتوقع للفترة') }}</th>@if($canEdit)<th></th>@endif</tr>
        @forelse($pool->lines as $l)
            <tr><td>{{ __("rroka.cost_category.$l->category") }}</td><td>{{ $l->description }}</td><td>{{ $l->employee?->name ?? ($l->machine ? $l->machine->code : '—') }}</td><td class="num">{{ number_format($l->amount, 2) }}</td>
                @if($canEdit)<td><form method="post" action="{{ route('costing.pools.lines.destroy', [$pool, $l->id]) }}" class="inline">@csrf @method('delete')<button class="btn ghost sm">{{ __('حذف') }}</button></form></td>@endif</tr>
        @empty
            <tr><td colspan="5" class="muted">{{ __('لا بنود بعد.') }}</td></tr>
        @endforelse
    </table></div>
    @if($canEdit)
    <form method="post" action="{{ route('costing.pools.lines.store', $pool) }}" style="margin-top:12px">@csrf
        <div class="grid g4">
            <div class="field"><label>{{ __('الفئة *') }}</label><select name="category" required>@foreach($pool->categories() as $c)@continue($c === 'GENERAL_ELECTRICITY' && $pool->cost_center_id)<option value="{{ $c }}">{{ __("rroka.cost_category.$c") }}</option>@endforeach</select></div>
            <div class="field"><label>{{ __('الوصف *') }}</label><input name="description" required></div>
            <div class="field"><label>{{ __('المبلغ المتوقع للفترة *') }}</label><input name="amount" type="number" step="0.01" min="0" required dir="ltr"></div>
            @unless($sa)
            <div class="field"><label>{{ __('الموظف (للإشراف والعمالة غير المباشرة)') }}</label><select name="employee_id"><option value="">—</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></div>
            <div class="field"><label>{{ __('الآلة (للإهلاك)') }}</label><select name="machine_id"><option value="">—</option>@foreach($machines as $m)<option value="{{ $m->id }}">{{ $m->code }} — {{ $m->name }}</option>@endforeach</select></div>
            @endunless
        </div>
        <div class="actions"><button class="btn sm">{{ __('إضافة بند') }}</button></div>
    </form>
    <p class="hint">{{ __('لا يُقبل موظف له بطاقة تكلفة مباشرة، ولا إهلاك آلة لها تكلفة ساعة، حتى لا يُحمَّل البند مرتين. ولا تدخل هنا تمويلات المالك ولا أقساط القروض ولا الضريبة القابلة للاسترداد ولا التحويلات ولا العهد غير المسوّاة.') }}</p>
    @endif
</div>
@include('partials.chatter', ['activity' => $activity])
@endsection
