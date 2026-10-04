@extends('layouts.app')
@section('title', __('بطاقة تكلفة :name', ['name' => $card->employee->name]))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('تكلفة الموظفين'), route('costing.employee-cards.index')], [$card->employee->name.' — v'.$card->version, null]]])
@endsection
@section('content')
@php($canEdit = auth()->user()->hasPermission('settings.cost_rates') && $card->isDraft())
@include('costing._record-bar', ['type' => 'employee-cards', 'r' => $card, 'edit' => route('costing.employee-cards.edit', $card)])
<div class="card">
    <h1 class="rec-title">{{ $card->employee->name }}</h1>
    <p class="rec-sub">{{ __('بطاقة تكلفة الموظف') }}</p>
    <div class="grid g2">
        <div class="table-wrap"><table>
            @foreach(['basic_salary' => __('الراتب الأساسي'), 'housing' => __('السكن'), 'transportation' => __('النقل'), 'insurance' => __('التأمينات'), 'government_fees' => __('الإقامة والرسوم الحكومية'), 'allowances' => __('البدلات'), 'other_costs' => __('تكاليف أخرى')] as $k => $label)
                <tr><td>{{ $label }}</td><td class="num">{{ number_format($card->$k, 2) }}</td></tr>
            @endforeach
            <tr><th>{{ __('التكلفة الشهرية') }}</th><th class="num">{{ number_format($card->monthly_cost, 2) }}</th></tr>
        </table></div>
        <div class="table-wrap"><table>
            <tr><td>{{ __('ساعات الحضور النظرية') }}</td><td class="num">{{ number_format($card->theoretical_hours, 2) }}</td></tr>
            @foreach(['break_hours' => __('الاستراحات'), 'cleaning_hours' => __('التنظيف'), 'maintenance_hours' => __('الصيانة'), 'setup_hours' => __('التجهيز'), 'meeting_hours' => __('الاجتماعات'), 'downtime_hours' => __('التوقف المعتاد'), 'waiting_hours' => __('انتظار المواد'), 'other_nonproductive_hours' => __('وقت غير منتج آخر')] as $k => $label)
                @if($card->$k > 0)<tr><td>− {{ $label }}</td><td class="num">{{ number_format($card->$k, 2) }}</td></tr>@endif
            @endforeach
            <tr><th>{{ __('الساعات الإنتاجية العملية') }}</th><th class="num">{{ number_format($card->practical_hours, 2) }}</th></tr>
        </table></div>
    </div>
    <div class="acct-figure" style="margin-top:12px">
        <span class="label">{{ __('تكلفة الساعة الإنتاجية') }}</span>
        <span class="value num">{{ number_format($card->hourly_rate, 2) }} {{ __('ريال') }}</span>
        <span class="hint">{{ number_format($card->monthly_cost, 2) }} ÷ {{ number_format($card->practical_hours, 2) }}</span>
        <span class="hint">{{ __('الساعات الفعلية المسجلة على أوامر التصنيع منذ بدء البطاقة: :h', ['h' => number_format($actualHours, 2)]) }}</span>
    </div>
    @include('costing._record-meta', ['r' => $card])
</div>

<div class="card">
    <h2>{{ __('توزيع الساعات على مراكز التكلفة') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('مركز التكلفة') }}</th><th class="num">{{ __('الحصة %') }}</th>@if($canEdit)<th></th>@endif</tr>
        @forelse($card->shares as $s)
            <tr><td>{{ $s->costCenter->name }}</td><td class="num">{{ number_format($s->share_pct, 2) }}</td>
                @if($canEdit)<td><form method="post" action="{{ route('costing.employee-cards.shares.destroy', [$card, $s->id]) }}" class="inline">@csrf @method('delete')<button class="btn ghost sm">{{ __('حذف') }}</button></form></td>@endif</tr>
        @empty
            <tr><td colspan="3" class="muted">{{ __('لم توزَّع الساعات بعد.') }}</td></tr>
        @endforelse
        <tr class="grp"><td>{{ __('المجموع') }}</td><td class="num @if($card->shares->sum('share_pct') != 100) warn-text @endif">{{ number_format($card->shares->sum('share_pct'), 2) }}</td>@if($canEdit)<td></td>@endif</tr>
    </table></div>
    @if($canEdit)
        <form method="post" action="{{ route('costing.employee-cards.shares.store', $card) }}" class="inline-form" style="display:flex;gap:8px;align-items:end;margin-top:10px">@csrf
            <div class="field"><label>{{ __('مركز التكلفة') }}</label><select name="cost_center_id" required>@foreach($centers->whereNotIn('id', $card->shares->pluck('cost_center_id')) as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
            <div class="field"><label>{{ __('الحصة %') }}</label><input name="share_pct" type="number" step="0.01" min="0.01" max="100" required dir="ltr" value="{{ max(0, 100 - $card->shares->sum('share_pct')) ?: '' }}"></div>
            <button class="btn sm">{{ __('إضافة') }}</button>
        </form>
        @if($centers->isEmpty())<p class="hint">{{ __('أضف مراكز التكلفة أولًا.') }} <a href="{{ route('costing.centers') }}">{{ __('مراكز التكلفة') }}</a></p>@endif
    @endif
    <p class="hint">{{ __('الاعتماد يتطلب مجموع 100%، ويكتب أجر الساعة في معدلات العامل تلقائيًا من تاريخ السريان.') }}</p>
</div>
@include('partials.chatter', ['activity' => $activity])
@endsection
