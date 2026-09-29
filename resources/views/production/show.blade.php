@extends('layouts.app')
@section('title', $o->order_no)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('أوامر التصنيع'), route('production.index')], [$o->order_no, null]]])
@endsection
@section('content')
@php
    $u = auth()->user();
    $open = in_array($o->status, ['PLANNED', 'IN_PROGRESS'], true);
    $canMove = $u->hasPermission('inventory.move') && $open;
    $canLog = $u->hasPermission('production.log_time') && $o->status !== 'PLANNED' && $o->status !== 'CANCELLED';
    $q = fn ($x) => rtrim(rtrim(number_format((float) $x, 4, '.', ','), '0'), '.') ?: '0';
    $finalPass = $o->inspections->contains(fn ($i) => $i->stage === 'FINAL' && $i->result === 'PASS');
    $path = $o->status === 'CANCELLED' ? ['PLANNED', 'CANCELLED'] : ['PLANNED', 'IN_PROGRESS', 'COMPLETED'];
@endphp
<div class="rec-bar">
    <div class="actions">
        @if($u->hasPermission('production.manage'))
            @if($o->status === 'PLANNED')
                <form method="post" action="{{ route('production.transition', [$o, 'IN_PROGRESS']) }}" class="inline">@csrf<button class="btn sm">{{ __('بدء التصنيع') }}</button></form>
            @endif
            @if($o->status === 'IN_PROGRESS')
                <form method="post" action="{{ route('production.transition', [$o, 'COMPLETED']) }}" class="inline" data-confirm="{{ __('إكمال أمر التصنيع؟ يُغلق نهائيًا.') }}">@csrf<button class="btn ok sm" @disabled(! $finalPass) title="{{ $finalPass ? '' : __('يلزم فحص جودة نهائي ناجح') }}">{{ __('إكمال') }}</button></form>
            @endif
            @if($open)
                <form method="post" action="{{ route('production.transition', [$o, 'CANCELLED']) }}" class="inline" data-confirm="{{ __('إلغاء أمر التصنيع نهائيًا؟') }}">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>
            @endif
        @endif
        @if($canMove && $components->isNotEmpty())
            <form method="post" action="{{ route('production.reserve-all', $o) }}" class="inline">@csrf<button class="btn ghost sm">{{ __('فحص التوفر وحجز المواد') }}</button></form>
        @endif
    </div>
    @include('partials.statusbar', ['path' => $path, 'current' => $o->status, 'bad' => ['CANCELLED']])
</div>

<div class="card">
    <h1 class="rec-title">{{ $o->order_no }}</h1>
    <p class="rec-sub">{{ $o->designVersion->design->title }} v{{ $o->designVersion->version_no }}</p>
    <dl class="kv">
        <dt>{{ __('المشروع') }}</dt><dd><a href="{{ route('projects.show', $o->project) }}">{{ $o->project->project_no }} — {{ $o->project->title }}</a> · {{ $o->project->client->business_name }}</dd>
        <dt>{{ __('نسخة التصميم') }}</dt><dd><a href="{{ route('design-versions.show', $o->designVersion) }}">v{{ $o->designVersion->version_no }}</a></dd>
        <dt>{{ __('المخطط') }}</dt><dd>{{ __('من :from إلى :to', ['from' => $o->planned_start?->format('Y-m-d') ?? '—', 'to' => $o->planned_end?->format('Y-m-d') ?? '—']) }}</dd>
        @if($o->started_at)<dt>{{ __('بدأ') }}</dt><dd>{{ $o->started_at->format('Y-m-d H:i') }}</dd>@endif
        @if($o->completed_at)<dt>{{ __('اكتمل') }}</dt><dd>{{ $o->completed_at->format('Y-m-d H:i') }}</dd>@endif
        @if($o->notes)<dt>{{ __('ملاحظات') }}</dt><dd>{{ $o->notes }}</dd>@endif
    </dl>
    @if($o->status === 'IN_PROGRESS' && ! $finalPass)
        <p class="hint">{{ __('لا يُكمل الأمر قبل فحص جودة نهائي ناجح.') }}</p>
    @endif
</div>

<div class="card">
    <h2>{{ __('المكونات') }} <span class="muted" style="font-weight:400;font-size:13px">{{ __('— الحجز والصرف على مستوى المشروع') }}</span></h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الخامة') }}</th><th class="num">{{ __('المخطط') }}</th><th class="num">{{ __('محجوز') }}</th><th class="num">{{ __('مصروف') }}</th><th class="num">{{ __('متبقٍ للحجز') }}</th><th class="num">{{ __('متاح بالمخزن') }}</th>@if($canMove)<th>{{ __('حركة') }}</th>@endif</tr>
        @forelse($components as $c)
            <tr>
                <td><a href="{{ route('materials.show', $c->material) }}">{{ $c->material->code }}</a> — {{ $c->material->name }} <span class="muted">({{ $c->material->uom }})</span></td>
                <td class="num">{{ $q($c->planned) }}</td>
                <td class="num">{{ $q($c->reserved) }}</td>
                <td class="num">{{ $q($c->issued) }}</td>
                <td class="num">{{ $q($c->remaining) }}</td>
                <td class="num"><span @class(['warn-text' => $c->available < $c->remaining])>{{ $q($c->available) }}</span></td>
                @if($canMove)
                <td>
                    <form method="post" action="{{ route('production.material', $o) }}" class="inline-form">@csrf
                        <input type="hidden" name="material_id" value="{{ $c->material->id }}">
                        <select name="movement_type">
                            @foreach(['RESERVE', 'ISSUE', 'RETURN', 'UNRESERVE'] as $t)<option value="{{ $t }}">{{ __("rroka.movement_type.$t") }}</option>@endforeach
                        </select>
                        <input name="quantity" type="number" step="0.0001" min="0.0001" required dir="ltr" placeholder="{{ __('الكمية') }}">
                        <button class="btn ghost sm">{{ __('تسجيل') }}</button>
                    </form>
                </td>
                @endif
            </tr>
        @empty
            <tr><td colspan="7" class="muted">{{ __('قائمة مواد النسخة فارغة.') }}</td></tr>
        @endforelse
    </table></div>
</div>

<div class="grid g2">
    <div class="card">
        <h2>{{ __('ساعات العمال') }}</h2>
        <div class="table-wrap"><table>
            <tr><th>{{ __('التاريخ') }}</th><th>{{ __('العامل') }}</th><th class="num">{{ __('الساعات') }}</th><th>{{ __('النشاط') }}</th></tr>
            @forelse($o->laborLogs as $l)
                <tr><td class="num">{{ $l->work_date->format('Y-m-d') }}</td><td>{{ $l->worker->name }}</td><td class="num">{{ $q($l->hours) }}</td><td>{{ $l->activity ?? '—' }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">{{ __('لا ساعات مسجلة.') }}</td></tr>
            @endforelse
            @if($o->laborLogs->isNotEmpty())<tr><th colspan="2">{{ __('الإجمالي') }}</th><th class="num">{{ $q($o->laborLogs->sum('hours')) }}</th><th></th></tr>@endif
        </table></div>
        @if($canLog)
            <form method="post" action="{{ route('production.labor', $o) }}" class="inline-form" style="margin-top:10px">@csrf
                <select name="worker_id" required><option value="">{{ __('— العامل —') }}</option>@foreach($workers as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select>
                <input type="date" name="work_date" value="{{ now()->format('Y-m-d') }}" required>
                <input name="hours" type="number" step="0.25" min="0.25" max="24" required dir="ltr" placeholder="{{ __('الساعات') }}">
                <input name="activity" placeholder="{{ __('النشاط (قص، تجميع، دهان…)') }}">
                <button class="btn sm">{{ __('تسجيل') }}</button>
            </form>
        @elseif($o->status === 'PLANNED')
            <p class="hint">{{ __('تُسجَّل الساعات بعد بدء التصنيع.') }}</p>
        @endif
    </div>
    <div class="card">
        <h2>{{ __('ساعات الآلات') }}</h2>
        <div class="table-wrap"><table>
            <tr><th>{{ __('التاريخ') }}</th><th>{{ __('الآلة') }}</th><th class="num">{{ __('الساعات') }}</th></tr>
            @forelse($o->machineLogs as $l)
                <tr><td class="num">{{ $l->work_date->format('Y-m-d') }}</td><td>{{ $l->machine->code }} — {{ $l->machine->name }}</td><td class="num">{{ $q($l->hours) }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">{{ __('لا ساعات مسجلة.') }}</td></tr>
            @endforelse
        </table></div>
        @if($canLog)
            <form method="post" action="{{ route('production.machine', $o) }}" class="inline-form" style="margin-top:10px">@csrf
                <select name="machine_id" required><option value="">{{ __('— الآلة —') }}</option>@foreach($machines as $m)<option value="{{ $m->id }}">{{ $m->code }} — {{ $m->name }}</option>@endforeach</select>
                <input type="date" name="work_date" value="{{ now()->format('Y-m-d') }}" required>
                <input name="hours" type="number" step="0.25" min="0.25" max="24" required dir="ltr" placeholder="{{ __('الساعات') }}">
                <button class="btn sm">{{ __('تسجيل') }}</button>
            </form>
        @endif
    </div>
</div>

<div class="card">
    <h2>{{ __('فحوصات الجودة') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('التاريخ') }}</th><th>{{ __('المرحلة') }}</th><th>{{ __('النتيجة') }}</th><th>{{ __('الملاحظات') }}</th><th>{{ __('الفاحص') }}</th></tr>
        @forelse($o->inspections as $i)
            <tr><td class="num">{{ $i->inspected_at->format('Y-m-d H:i') }}</td><td>{{ __("rroka.qc_stage.$i->stage") }}</td>
                <td><span class="badge b-{{ $i->result === 'PASS' ? 'SUCCESS' : 'FAILED' }}">{{ __("rroka.qc_result.$i->result") }}</span></td>
                <td>{{ $i->findings ?? '—' }}</td><td>{{ $i->inspector->name }}</td></tr>
        @empty
            <tr><td colspan="5" class="muted">{{ __('لا فحوصات بعد.') }}</td></tr>
        @endforelse
    </table></div>
    @if($u->hasPermission('quality.inspect') && $o->status === 'IN_PROGRESS')
        <form method="post" action="{{ route('production.inspect', $o) }}" class="inline-form" style="margin-top:10px">@csrf
            <select name="stage">@foreach(['IN_PROCESS', 'FINAL'] as $s)<option value="{{ $s }}">{{ __("rroka.qc_stage.$s") }}</option>@endforeach</select>
            <select name="result">@foreach(\App\Models\QualityInspection::RESULTS as $r)<option value="{{ $r }}">{{ __("rroka.qc_result.$r") }}</option>@endforeach</select>
            <input name="findings" placeholder="{{ __('الملاحظات (إلزامية إن لم ينجح)') }}" style="min-width:260px">
            <button class="btn sm">{{ __('تسجيل الفحص') }}</button>
        </form>
    @endif
</div>

@if($o->movements->isNotEmpty())
<div class="card" style="padding:0">
    <h2 style="padding:16px 16px 0">{{ __('حركات المواد لهذا الأمر') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('التاريخ') }}</th><th>{{ __('النوع') }}</th><th>{{ __('الخامة') }}</th><th class="num">{{ __('الكمية') }}</th><th class="num">{{ __('تكلفة الوحدة') }}</th></tr>
        @foreach($o->movements as $mv)
            <tr><td class="num">{{ $mv->moved_at->format('Y-m-d H:i') }}</td><td>{{ __("rroka.movement_type.$mv->movement_type") }}</td><td>{{ $mv->material->code }}</td><td class="num">{{ $q($mv->quantity) }}</td><td class="num">{{ $mv->unit_cost === null ? '—' : number_format($mv->unit_cost, 2) }}</td></tr>
        @endforeach
    </table></div>
</div>
@endif
@include('partials.chatter', ['activity' => $activity])
@endsection
