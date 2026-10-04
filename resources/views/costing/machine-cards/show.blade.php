@extends('layouts.app')
@section('title', __('بطاقة تكلفة :name', ['name' => $card->machine->code]))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('تكلفة الآلات'), route('costing.machine-cards.index')], [$card->machine->code.' — v'.$card->version, null]]])
@endsection
@section('content')
@include('costing._record-bar', ['type' => 'machine-cards', 'r' => $card, 'edit' => route('costing.machine-cards.edit', $card)])
<div class="card">
    <h1 class="rec-title">{{ $card->machine->code }} — {{ $card->machine->name }}</h1>
    <p class="rec-sub">{{ __('مركز التكلفة: :c', ['c' => $card->machine->costCenter?->name ?? __('غير محدد')]) }}</p>
    <div class="grid g2">
        <dl class="kv">
            <dt>{{ __('تكلفة الشراء') }}</dt><dd>{{ number_format($card->acquisition_cost, 2) }}</dd>
            <dt>{{ __('القيمة المتبقية') }}</dt><dd>{{ number_format($card->residual_value, 2) }}</dd>
            <dt>{{ __('العمر الإنتاجي') }}</dt><dd>{{ __(':n سنة', ['n' => rtrim(rtrim(number_format($card->useful_life_years, 2), '0'), '.')]) }}</dd>
            <dt>{{ __('الساعات السنوية') }}</dt><dd>{{ __('نظرية :t — عملية :p', ['t' => number_format($card->theoretical_annual_hours, 0), 'p' => number_format($card->practical_annual_hours, 0)]) }}</dd>
            <dt>{{ __('الكهرباء') }}</dt><dd>{{ $card->power_kw }} kW × {{ $card->load_factor }} × {{ $card->electricity_rate !== null ? number_format($card->electricity_rate, 4) : '—' }}</dd>
            <dt>{{ __('الساعات الفعلية منذ بدء البطاقة') }}</dt><dd>{{ number_format($actualHours, 2) }}</dd>
        </dl>
        <div class="table-wrap"><table>
            <tr><td>{{ __('الإهلاك للساعة') }}</td><td class="num">{{ number_format($card->depreciation_per_hour, 4) }}</td></tr>
            <tr><td>{{ __('الكهرباء للساعة') }}</td><td class="num">{{ $card->electricity_per_hour !== null ? number_format($card->electricity_per_hour, 4) : '—' }}</td></tr>
            <tr><td>{{ __('الصيانة للساعة') }}</td><td class="num">{{ number_format($card->maintenance_per_hour, 4) }}</td></tr>
            <tr><td>{{ __('قطع الغيار للساعة') }}</td><td class="num">{{ number_format($card->spare_parts_per_hour, 4) }}</td></tr>
            <tr><td>{{ __('تشغيل آخر للساعة') }}</td><td class="num">{{ number_format($card->other_per_hour, 4) }}</td></tr>
            <tr><th>{{ __('تكلفة ساعة الآلة') }}</th><th class="num">{{ $card->hourly_rate !== null ? number_format($card->hourly_rate, 2) : '—' }}</th></tr>
        </table></div>
    </div>
    @if($card->electricity_rate === null && $card->power_kw > 0)<p class="hint warn-text">{{ __('لا يوجد سعر كهرباء معتمد في تاريخ السريان، فتكلفة الساعة غير مكتملة.') }} <a href="{{ route('costing.energy') }}">{{ __('سعر الكهرباء') }}</a></p>@endif
    @include('costing._record-meta', ['r' => $card])
</div>
@include('partials.chatter', ['activity' => $activity])
@endsection
