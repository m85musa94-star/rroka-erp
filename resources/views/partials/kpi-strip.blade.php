{{-- Headline figures above a report: $kpis = [[label, value, sub|null, tone|null], …] --}}
@if(! empty($kpis))
<div class="kpi-strip">
    @foreach($kpis as [$label, $value, $sub, $tone])
        <div class="kpi-tile {{ $tone ? 'tone-'.$tone : '' }}">
            <span class="kpi-label">{{ $label }}</span>
            <span class="kpi-value">{{ $value }}</span>
            @if($sub)<span class="kpi-sub">{{ $sub }}</span>@endif
        </div>
    @endforeach
</div>
@endif
