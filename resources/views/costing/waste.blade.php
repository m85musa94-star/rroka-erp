@extends('layouts.app')
@section('title', __('نسب الهالك المعيارية'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('محرك التكلفة'), route('costing.index')], [__('نسب الهالك المعيارية'), null]]])
@endsection
@section('content')
@php($u = auth()->user())
<p class="hint" style="margin-top:0">{{ __('نسبة الهالك لكل مادة أو لكل فئة مواد (القماش غير الخشب غير الإسفنج). نسبة المادة نفسها تتقدم على نسبة فئتها، ويمكن تعديلها لكل بند عند التقدير.') }}</p>
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('ينطبق على') }}</th><th class="num">{{ __('الإصدار') }}</th><th>{{ __('ساري من') }}</th><th class="num">{{ __('الهالك %') }}</th><th>{{ __('المصدر') }}</th><th>{{ __('الحالة') }}</th><th></th></tr>
    @forelse($rows as $w)
        <tr>
            <td>{{ $w->material ? $w->material->code.' — '.$w->material->name : __('فئة: :c', ['c' => $w->category]) }}</td>
            <td class="num">{{ $w->version }}</td><td class="num">{{ $w->effective_from->format('Y-m-d') }}</td><td class="num">{{ number_format($w->waste_pct, 2) }}</td>
            <td>{{ $w->source }} @if($w->estimated)<span class="badge b-ON_HOLD">{{ __('تقديري') }}</span>@endif</td>
            <td>@include('partials.badge', ['s' => $w->status]) @if($w->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif</td>
            <td>@if($w->isDraft())
                @if($u->hasPermission('cost_rates.approve'))<form method="post" action="{{ route('costing.approve', ['waste', $w->id]) }}" class="inline">@csrf<button class="btn ok sm">{{ __('اعتماد') }}</button></form>@endif
                @if($u->hasPermission('settings.cost_rates'))<form method="post" action="{{ route('costing.cancel', ['waste', $w->id]) }}" class="inline">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>@endif
            @endif</td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">{{ __('لم تُدخل نسب هالك بعد.') }}</td></tr>
    @endforelse
</table></div></div>
@if($u->hasPermission('settings.cost_rates'))
<form method="post" action="{{ route('costing.waste.store') }}" class="card">@csrf
    <h2>{{ __('نسبة هالك جديدة') }}</h2>
    <div class="grid g3">
        <div class="field"><label>{{ __('فئة مواد') }}</label><select name="category"><option value="">—</option>@foreach($categories as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('أو مادة بعينها') }}</label><select name="material_id"><option value="">—</option>@foreach($materials as $m)<option value="{{ $m->id }}">{{ $m->code }} — {{ $m->name }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('الهالك % *') }}</label><input name="waste_pct" type="number" step="0.01" min="0" max="99.99" required dir="ltr"></div>
    </div>
    @include('costing._fields', ['r' => new \App\Models\WasteDefault(['effective_from' => today()])])
    <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button></div>
</form>
@endif
@endsection
