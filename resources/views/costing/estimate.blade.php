@extends('layouts.app')
@section('title', __('ورقة التكلفة — :q بند :n', ['q' => $q->quotation_no, 'n' => $line->line_no]))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('عروض الأسعار'), route('quotations.index')], [$q->quotation_no, route('quotations.show', $q)], [__('ورقة تكلفة البند :n', ['n' => $line->line_no]), null]]])
@endsection
@section('content')
@php($fmt = fn ($v, $d = 2) => $v === null ? '—' : number_format((float) $v, $d))
@php($c = $snapshot ?? $costs)
@php($missing = $snapshot ? $snapshot->missingList() : ($costs->missing ?? []))
@php($warnings = $snapshot ? $snapshot->warningList() : ($costs->warnings ?? []))
@php($route = fn ($name, $extra = []) => route($name, ['quotation' => $q->id, 'line' => $line->line_no] + $extra))
<div class="card">
    <h1 class="rec-title">{{ $line->description }}</h1>
    <p class="rec-sub">{{ $q->quotation_no }} · {{ __('الكمية :n :u', ['n' => rtrim(rtrim(number_format($line->quantity, 3), '0'), '.'), 'u' => $line->unit]) }} · {{ __('سعر الوحدة :p', ['p' => number_format($line->unit_price, 2)]) }} · {{ __('تاريخ التسعير :d', ['d' => $q->issue_date->format('Y-m-d')]) }}</p>
    @if($snapshot)
        <div class="alert ok">{{ __('مجمّدة عند اعتماد العرض في :d. لا تتغير بتغيّر المعدلات لاحقًا.', ['d' => $snapshot->frozen_at->format('Y-m-d H:i')]) }}</div>
    @elseif(! $e)
        <div class="alert warn">{{ __('لا يوجد تقدير تكلفة لهذا البند بعد.') }}@if($q->requires_costing) {{ __('لا يُعتمد العرض قبل اكتماله.') }}@endif</div>
    @endif
    @foreach($missing as $m)<div class="alert bad">{{ __("rroka.costing_missing.$m") }}</div>@endforeach
    @foreach($warnings as $w)<div class="alert warn">{{ __("rroka.costing_warning.$w") }}</div>@endforeach

    @if($c)
    <div class="grid g2">
        <div class="table-wrap"><table>
            <tr><td>{{ __('المواد المباشرة') }}</td><td class="num">{{ $fmt($c->materials_cost) }}</td></tr>
            <tr><td>{{ __('العمالة المباشرة') }}</td><td class="num">{{ $fmt($c->labor_cost) }}</td></tr>
            <tr><td>{{ __('تشغيل الآلات') }}</td><td class="num">{{ $fmt($c->machine_cost) }}</td></tr>
            <tr><td>{{ __('تكاليف مباشرة أخرى') }}</td><td class="num">{{ $fmt($c->direct_other_cost) }}</td></tr>
            <tr class="grp"><th>{{ __('التكلفة المباشرة') }}</th><th class="num">{{ $fmt($c->direct_cost) }}</th></tr>
            <tr><td>{{ __('التكاليف الصناعية غير المباشرة المحمّلة') }}</td><td class="num">{{ $fmt($c->overhead_cost) }}</td></tr>
            <tr class="grp"><th>{{ __('تكلفة التصنيع') }}</th><th class="num">{{ $fmt($c->manufacturing_cost) }}</th></tr>
            <tr><td>{{ __('المصاريف البيعية والإدارية (:p%)', ['p' => $fmt($c->selling_admin_pct)]) }}</td><td class="num">{{ $c->fully_loaded_cost !== null && $c->manufacturing_cost !== null ? $fmt($c->fully_loaded_cost - $c->manufacturing_cost) : '—' }}</td></tr>
            <tr class="grp"><th>{{ __('التكلفة الكاملة') }}</th><th class="num">{{ $fmt($c->fully_loaded_cost) }}</th></tr>
            <tr><td class="muted">{{ __('تكلفة التصنيع للوحدة') }}</td><td class="num muted">{{ $c->manufacturing_cost !== null ? $fmt($c->manufacturing_cost / $c->quantity) : '—' }}</td></tr>
        </table></div>
        <div class="table-wrap"><table>
            <tr><td>{{ __('طريقة التسعير') }}</td><td>{{ __("rroka.pricing_method.$c->pricing_method") }} — {{ $fmt($c->target_pct) }}%</td></tr>
            <tr class="grp"><th>{{ __('السعر المقترح (قبل الضريبة)') }}</th><th class="num">{{ $fmt($c->recommended_price) }}</th></tr>
            <tr><td>{{ __('سعر العرض بعد حصته من الخصم') }}</td><td class="num">{{ $fmt($c->net_price) }}</td></tr>
            <tr><td>{{ __('مجمل الربح (السعر − تكلفة التصنيع)') }}</td><td class="num">{{ $fmt($c->gross_profit) }}</td></tr>
            <tr><td>{{ __('هامش مجمل الربح (من السعر)') }}</td><td class="num">{{ $c->gross_margin_pct !== null ? $fmt($c->gross_margin_pct).'%' : '—' }}</td></tr>
            @unless($snapshot)
            <tr><td>{{ __('نسبة الإضافة Markup (من التكلفة)') }}</td><td class="num">{{ $c->markup_pct !== null ? $fmt($c->markup_pct).'%' : '—' }}</td></tr>
            <tr><td>{{ __('الربح بعد التكاليف الكاملة') }}</td><td class="num">{{ $fmt($c->profit_after_overheads) }}</td></tr>
            <tr><td>{{ __('الهامش بعد التكاليف الكاملة') }}</td><td class="num">{{ $c->margin_after_overheads_pct !== null ? $fmt($c->margin_after_overheads_pct).'%' : '—' }}</td></tr>
            <tr><td>{{ __('المساهمة (السعر − المواد والتكاليف المباشرة الخارجية)') }}</td><td class="num">{{ $fmt($c->contribution) }}</td></tr>
            @endunless
            <tr><td class="muted">{{ __('ساعات العمل / ساعات الآلات') }}</td><td class="num muted">{{ $fmt($c->labor_hours, 2) }} / {{ $fmt($c->machine_hours, 2) }}</td></tr>
        </table></div>
    </div>
    <p class="hint">{{ __('الضريبة لا تدخل في السعر ولا الربح هنا؛ تُحسب على العرض كاملًا حسب النسبة المختارة.') }}</p>
    @endif
</div>

@if($editable)
<form method="post" action="{{ $route('estimates.save') }}" class="card">@csrf
    <h2>{{ __('بيانات المنتج والتسعير') }}</h2>
    @if(! $e && ! $policy)<p class="hint warn-text">{{ __('لا توجد سياسة تسعير معتمدة؛ أدخل الطريقة والنسبة لهذا البند.') }}</p>@endif
    <div class="grid g3">
        <div class="field"><label>{{ __('طريقة التسعير *') }}</label><select name="pricing_method" required>@foreach(\App\Models\PricingPolicy::METHODS as $m)<option value="{{ $m }}" @selected(old('pricing_method', $e?->pricing_method ?? $policy?->pricing_method) === $m)>{{ __("rroka.pricing_method.$m") }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('النسبة المستهدفة % *') }}</label><input name="target_pct" type="number" step="0.01" min="0" required dir="ltr" value="{{ old('target_pct', $e?->target_pct ?? $policy?->target_pct) }}"></div>
        <div class="field"><label>{{ __('الحد الأدنى للهامش %') }}</label><input name="min_margin_pct" type="number" step="0.01" min="0" max="99.99" dir="ltr" value="{{ old('min_margin_pct', $e?->min_margin_pct ?? $policy?->min_margin_pct) }}"></div>
        <div class="field"><label>{{ __('فئة المنتج') }}</label><input name="product_category" value="{{ old('product_category', $e?->product_category) }}" placeholder="{{ __('مثال: مطابخ، غرف نوم، كنب') }}"></div>
        <div class="field"><label>{{ __('المقاسات') }}</label><input name="dimensions" value="{{ old('dimensions', $e?->dimensions) }}"></div>
        <div class="field"><label>{{ __('المواصفات') }}</label><input name="specifications" value="{{ old('specifications', $e?->specifications) }}" placeholder="{{ __('درجة الخشب، القماش، الإسفنج، الإكسسوارات') }}"></div>
    </div>
    <label class="perm-item"><input type="checkbox" name="installation_required" value="1" @checked(old('installation_required', $e?->installation_required))> {{ __('يتطلب تركيبًا') }}</label>
    <label class="perm-item"><input type="checkbox" name="delivery_required" value="1" @checked(old('delivery_required', $e?->delivery_required))> {{ __('يتطلب توصيلًا') }}</label>
    <div class="actions"><button class="btn sm">{{ $e ? __('حفظ') : __('إنشاء التقدير') }}</button></div>
</form>
@endif

@if($e)
<div class="card">
    <h2>{{ __('المواد (قائمة المواد للوحدة)') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('المادة') }}</th><th class="num">{{ __('الكمية للوحدة') }}</th><th class="num">{{ __('الهالك %') }}</th><th class="num">{{ __('الكمية المعدّلة') }}</th><th class="num">{{ __('سعر الوحدة') }}</th><th class="num">{{ __('الإجمالي للبند') }}</th>@if($editable)<th></th>@endif</tr>
        @forelse($materialLines as $m)
            <tr><td>{{ $m->code }} — {{ $m->name }} <span class="muted">/ {{ $m->uom }}</span></td><td class="num">{{ $fmt($m->qty_per_unit, 4) }}</td>
                <td class="num">{!! $m->waste_pct !== null ? e($fmt($m->waste_pct)).' <span class="muted">'.e($m->waste_source === 'OVERRIDE' ? __('معدّل') : __('معياري')).'</span>' : '<span class="na">'.e(__('غير مُدخل')).'</span>' !!}</td>
                <td class="num">{{ $fmt($m->adjusted_qty_per_unit, 4) }}</td>
                <td class="num">{!! $m->unit_price !== null ? e($fmt($m->unit_price, 4)).' <span class="muted">'.e($m->price_source === 'OVERRIDE' ? $m->override_source : __('معياري v:v', ['v' => $m->price_version])).'</span>' : '<span class="na">'.e(__('غير مُدخل')).'</span>' !!}</td>
                <td class="num">{{ $fmt($m->total_cost) }}</td>
                @if($editable)<td><form method="post" action="{{ $route('estimates.items.destroy', ['kind' => 'materials', 'id' => $m->id]) }}" class="inline">@csrf @method('delete')<button class="btn ghost sm">{{ __('حذف') }}</button></form></td>@endif</tr>
        @empty
            <tr><td colspan="7" class="muted">{{ __('لا مواد بعد.') }}</td></tr>
        @endforelse
    </table></div>
    @if($editable)
    <form method="post" action="{{ $route('estimates.materials.store') }}" style="margin-top:10px">@csrf
        <div class="grid g4">
            <div class="field"><label>{{ __('المادة *') }}</label><select name="material_id" required>@foreach($materials as $mt)<option value="{{ $mt->id }}">{{ $mt->code }} — {{ $mt->name }} ({{ $mt->uom }})</option>@endforeach</select></div>
            <div class="field"><label>{{ __('الكمية للوحدة *') }}</label><input name="quantity" type="number" step="0.0001" min="0.0001" required dir="ltr"></div>
            <div class="field"><label>{{ __('هالك خاص %') }}</label><input name="waste_pct" type="number" step="0.01" min="0" max="99.99" dir="ltr" placeholder="{{ __('فارغ = الهالك المعياري') }}"></div>
            <div class="field"><label>{{ __('سعر خاص') }}</label><input name="unit_price" type="number" step="0.0001" min="0.0001" dir="ltr" placeholder="{{ __('فارغ = السعر المعياري') }}"></div>
            <div class="field"><label>{{ __('مصدر السعر الخاص') }}</label><input name="price_source" placeholder="{{ __('مثال: عرض سعر المورد رقم…') }}"></div>
        </div>
        <div class="actions"><button class="btn sm">{{ __('إضافة مادة') }}</button></div>
    </form>
    @endif
</div>

<div class="card">
    <h2>{{ __('مراحل التصنيع (Routing)') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>#</th><th>{{ __('العملية') }}</th><th>{{ __('مركز التكلفة') }}</th><th class="num">{{ __('ساعات العمل') }}</th><th class="num">{{ __('الأجر/ساعة') }}</th><th class="num">{{ __('ساعات الآلة') }}</th><th class="num">{{ __('الآلة/ساعة') }}</th><th class="num">{{ __('التحميل/ساعة') }}</th><th class="num">{{ __('التكلفة') }}</th>@if($editable)<th></th>@endif</tr>
        @forelse($operationLines as $o)
            <tr><td class="num">{{ $o->seq }}</td><td>{{ $o->operation }}</td><td>{{ $o->cost_center_name }}</td>
                <td class="num">{{ $fmt($o->total_labor_hours) }} <div class="hint">{{ $fmt($o->labor_hours, 3) }} × {{ $fmt($o->line_qty, 0) }} + {{ $fmt($o->setup_hours, 3) }}</div></td>
                <td class="num">{!! $o->total_labor_hours == 0 ? '—' : ($o->labor_rate !== null ? e($fmt($o->labor_rate)).'<div class="hint">'.e($o->labor_rate_basis === 'EMPLOYEE' ? __('أجر الموظف') : __('متوسط المركز')).'</div>' : '<span class="na">'.e(__('ناقص')).'</span>') !!}</td>
                <td class="num">{{ $o->machine_id ? $fmt($o->total_machine_hours) : '—' }}</td>
                <td class="num">{!! ! $o->machine_id ? '—' : ($o->machine_rate !== null ? e($fmt($o->machine_rate)) : '<span class="na">'.e(__('ناقص')).'</span>') !!}</td>
                <td class="num">{!! $o->overhead_rate !== null ? e($fmt($o->overhead_rate, 4)).'<div class="hint">'.e(__("rroka.driver.$o->driver")).'</div>' : ($o->driver_hours == 0 ? '—' : '<span class="na">'.e(__('ناقص')).'</span>') !!}</td>
                <td class="num">{{ $o->labor_cost !== null && $o->machine_cost !== null && $o->overhead_cost !== null ? $fmt($o->labor_cost + $o->machine_cost + $o->overhead_cost) : '—' }}</td>
                @if($editable)<td><form method="post" action="{{ $route('estimates.items.destroy', ['kind' => 'operations', 'id' => $o->id]) }}" class="inline">@csrf @method('delete')<button class="btn ghost sm">{{ __('حذف') }}</button></form></td>@endif</tr>
        @empty
            <tr><td colspan="10" class="muted">{{ __('لا عمليات بعد.') }}</td></tr>
        @endforelse
    </table></div>
    @if($editable)
    <form method="post" action="{{ $route('estimates.operations.store') }}" style="margin-top:10px">@csrf
        <div class="grid g4">
            <div class="field"><label>{{ __('العملية *') }}</label><input name="operation" required placeholder="{{ __('قص، نجارة، تنجيد، دهان، تجميع، تركيب') }}"></div>
            <div class="field"><label>{{ __('مركز التكلفة *') }}</label><select name="cost_center_id" required>@foreach($centers as $cc)<option value="{{ $cc->id }}">{{ $cc->name }}</option>@endforeach</select></div>
            <div class="field"><label>{{ __('موظف بعينه') }}</label><select name="employee_id"><option value="">{{ __('— متوسط أجر المركز —') }}</option>@foreach($employees as $em)<option value="{{ $em->id }}">{{ $em->name }}</option>@endforeach</select></div>
            <div class="field"><label>{{ __('ساعات العمل للوحدة *') }}</label><input name="labor_hours" type="number" step="0.001" min="0" required dir="ltr" value="0"></div>
            <div class="field"><label>{{ __('ساعات التجهيز للدفعة *') }}</label><input name="setup_hours" type="number" step="0.001" min="0" required dir="ltr" value="0"><div class="hint">{{ __('تُحسب مرة واحدة مهما كانت الكمية.') }}</div></div>
            <div class="field"><label>{{ __('الآلة') }}</label><select name="machine_id"><option value="">—</option>@foreach($machines as $mc)<option value="{{ $mc->id }}">{{ $mc->code }} — {{ $mc->name }}</option>@endforeach</select></div>
            <div class="field"><label>{{ __('ساعات الآلة للوحدة') }}</label><input name="machine_hours" type="number" step="0.001" min="0" dir="ltr" value="0"></div>
            <div class="field"><label>{{ __('تجهيز الآلة للدفعة') }}</label><input name="machine_setup_hours" type="number" step="0.001" min="0" dir="ltr" value="0"></div>
        </div>
        <div class="actions"><button class="btn sm">{{ __('إضافة عملية') }}</button></div>
    </form>
    @endif
</div>

<div class="card">
    <h2>{{ __('تكاليف مباشرة أخرى للطلب') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('النوع') }}</th><th>{{ __('الوصف') }}</th><th>{{ __('الأساس') }}</th><th class="num">{{ __('المبلغ') }}</th>@if($editable)<th></th>@endif</tr>
        @forelse($directCosts as $d)
            <tr><td>{{ __("rroka.direct_cost_type.$d->cost_type") }}</td><td>{{ $d->description }}</td><td>{{ __("rroka.cost_basis.$d->basis") }}</td><td class="num">{{ $fmt($d->amount) }}</td>
                @if($editable)<td><form method="post" action="{{ $route('estimates.items.destroy', ['kind' => 'direct', 'id' => $d->id]) }}" class="inline">@csrf @method('delete')<button class="btn ghost sm">{{ __('حذف') }}</button></form></td>@endif</tr>
        @empty
            <tr><td colspan="5" class="muted">{{ __('لا تكاليف مباشرة أخرى.') }}</td></tr>
        @endforelse
    </table></div>
    @if($editable)
    <form method="post" action="{{ $route('estimates.direct.store') }}" style="margin-top:10px">@csrf
        <div class="grid g4">
            <div class="field"><label>{{ __('النوع *') }}</label><select name="cost_type" required>@foreach(\App\Models\CostEstimate::DIRECT_TYPES as $t)<option value="{{ $t }}">{{ __("rroka.direct_cost_type.$t") }}</option>@endforeach</select></div>
            <div class="field"><label>{{ __('الوصف *') }}</label><input name="description" required></div>
            <div class="field"><label>{{ __('المبلغ *') }}</label><input name="amount" type="number" step="0.01" min="0" required dir="ltr"></div>
            <div class="field"><label>{{ __('الأساس *') }}</label><select name="basis" required>@foreach(['ONE_TIME', 'PER_UNIT'] as $b)<option value="{{ $b }}">{{ __("rroka.cost_basis.$b") }}</option>@endforeach</select></div>
        </div>
        <div class="actions"><button class="btn sm">{{ __('إضافة') }}</button></div>
    </form>
    <p class="hint">{{ __('التكلفة المرتبطة بهذا الطلب وحده تُسجَّل هنا ولا تُوزَّع على غيره.') }}</p>
    @endif
</div>
@include('partials.chatter', ['activity' => $activity])
@endif
@endsection
