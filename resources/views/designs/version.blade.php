@extends('layouts.app')
@section('title', $v->design->title.' v'.$v->version_no)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('التصاميم'), route('designs.index')], [$v->design->project->project_no, route('projects.show', $v->design->project)], [$v->design->title.' — v'.$v->version_no, null]]])
@endsection
@section('content')
@php
    $u = auth()->user();
    $manage = $u->hasPermission('designs.manage');
    $bom = $u->hasPermission('bom.manage') && $v->bomEditable();
    $path = match ($v->status) {
        'REJECTED' => ['DRAFT', 'CLIENT_REVIEW', 'REJECTED'],
        'SUPERSEDED' => ['DRAFT', 'CLIENT_REVIEW', 'CLIENT_APPROVED', 'RELEASED_FOR_PRODUCTION', 'SUPERSEDED'],
        default => ['DRAFT', 'CLIENT_REVIEW', 'CLIENT_APPROVED', 'RELEASED_FOR_PRODUCTION'],
    };
    $q = fn ($x) => rtrim(rtrim(number_format((float) $x, 4, '.', ','), '0'), '.');
@endphp
<div class="rec-bar">
    <div class="actions">
        @if($manage && $v->status === 'DRAFT')
            <form method="post" action="{{ route('design-versions.transition', [$v, 'submit']) }}" class="inline">@csrf<button class="btn sm">{{ __('إرسال لمراجعة العميل') }}</button></form>
        @endif
        @if($manage && $v->status === 'CLIENT_REVIEW')
            <form method="post" action="{{ route('design-versions.transition', [$v, 'approve']) }}" class="inline inline-form">@csrf
                <input type="datetime-local" name="client_approved_at" value="{{ now()->format('Y-m-d\TH:i') }}" required title="{{ __('تاريخ موافقة العميل') }}">
                <button class="btn ok sm">{{ __('وافق العميل') }}</button></form>
            <form method="post" action="{{ route('design-versions.transition', [$v, 'revise']) }}" class="inline">@csrf<button class="btn ghost sm">{{ __('إعادة للتعديل') }}</button></form>
        @endif
        @if($manage && in_array($v->status, ['DRAFT', 'CLIENT_REVIEW'], true))
            <form method="post" action="{{ route('design-versions.transition', [$v, 'reject']) }}" class="inline" data-confirm="{{ __('تسجيل رفض هذه النسخة؟ لا يمكن إعادة فتحها.') }}">@csrf<button class="btn ghost sm">{{ __('رفض النسخة') }}</button></form>
        @endif
        @if($u->hasPermission('designs.release') && $v->status === 'CLIENT_APPROVED')
            <form method="post" action="{{ route('design-versions.transition', [$v, 'release']) }}" class="inline" data-confirm="{{ __('إصدار النسخة للإنتاج؟ تُجمَّد قائمة موادها، وتُستبدل النسخة المُصدرة السابقة إن وُجدت.') }}">@csrf<button class="btn ok sm">{{ __('إصدار للإنتاج') }}</button></form>
        @endif
        @if($v->status === 'RELEASED_FOR_PRODUCTION' && $u->hasPermission('production.manage'))
            <a class="btn sm" href="{{ route('production.create', ['design_version_id' => $v->id]) }}">{{ __('أمر تصنيع جديد') }}</a>
        @endif
        @if($manage)
            <details class="inline-details"><summary class="btn ghost sm">{{ __('نسخة جديدة') }}</summary>
                <form method="post" action="{{ route('designs.versions.store', $v->design) }}" class="inline-form" style="margin-top:6px">@csrf
                    <input name="change_notes" required placeholder="{{ __('ما الذي تغيّر؟') }}"><button class="btn sm">{{ __('إنشاء') }}</button></form>
            </details>
        @endif
    </div>
    @include('partials.statusbar', ['path' => $path, 'current' => $v->status, 'bad' => ['REJECTED', 'SUPERSEDED']])
</div>

<div class="card">
    <h1 class="rec-title">{{ $v->design->title }} <span class="muted">v{{ $v->version_no }}</span></h1>
    <dl class="kv">
        <dt>{{ __('المشروع') }}</dt><dd><a href="{{ route('projects.show', $v->design->project) }}">{{ $v->design->project->project_no }} — {{ $v->design->project->title }}</a></dd>
        <dt>{{ __('العميل') }}</dt><dd>{{ $v->design->project->client->business_name }}</dd>
        <dt>{{ __('ملف التصميم') }}</dt><dd>@if($v->file_url)<a href="{{ $v->file_url }}" target="_blank" rel="noopener noreferrer">{{ __('فتح الملف') }}</a>@else — @endif</dd>
        <dt>{{ __('ملاحظات النسخة') }}</dt><dd>{{ $v->change_notes ?: '—' }}</dd>
        @if($v->client_approved_at)<dt>{{ __('موافقة العميل') }}</dt><dd>{{ $v->client_approved_at->format('Y-m-d H:i') }}</dd>@endif
        @if($v->released_at)<dt>{{ __('أُصدرت للإنتاج') }}</dt><dd>{{ $v->released_at->format('Y-m-d H:i') }}</dd>@endif
    </dl>
    @if($manage && $v->bomEditable())
        <details style="margin-top:10px"><summary class="muted">{{ __('تعديل بيانات النسخة') }}</summary>
            <form method="post" action="{{ route('design-versions.update', $v) }}" class="grid g2" style="margin-top:8px">@csrf @method('put')
                <div class="field"><label>{{ __('رابط ملف التصميم') }}</label><input name="file_url" type="url" value="{{ $v->file_url }}" dir="ltr"></div>
                <div class="field"><label>{{ __('ملاحظات النسخة') }}</label><input name="change_notes" value="{{ $v->change_notes }}"></div>
                <div class="actions"><button class="btn sm">{{ __('حفظ') }}</button></div>
            </form>
        </details>
    @endif
    <p class="hint">{{ __('نسخ هذا التصميم:') }}
        @foreach($v->design->versions as $o)
            <a href="{{ route('design-versions.show', $o) }}" @class(['crumb-current' => $o->id === $v->id])>v{{ $o->version_no }}</a> ({{ __("rroka.status.$o->status") }})@if(! $loop->last)، @endif
        @endforeach
    </p>
</div>

<div class="card">
    <div class="section-title"><h2>{{ __('قائمة المواد') }}</h2>
        @unless($v->bomEditable())<span class="badge">{{ __('مجمّدة — لأي تغيير أنشئ نسخة جديدة') }}</span>@endunless</div>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الخامة') }}</th><th class="num">{{ __('الكمية') }}</th><th>{{ __('الوحدة') }}</th><th class="num">{{ __('نسبة الهالك %') }}</th><th class="num">{{ __('الكمية المخططة') }}</th>@if($bom)<th></th>@endif</tr>
        @forelse($v->bomLines as $l)
            <tr>
                <td><a href="{{ route('materials.show', $l->material) }}">{{ $l->material->code }}</a> — {{ $l->material->name }}</td>
                <td class="num">{{ $q($l->quantity) }}</td><td>{{ $l->material->uom }}</td>
                <td class="num">{{ $q($l->waste_pct) }}</td><td class="num">{{ $q($l->plannedQty()) }}</td>
                @if($bom)<td><form method="post" action="{{ route('bom-lines.destroy', $l) }}" class="inline">@csrf @method('delete')<button class="btn ghost sm">{{ __('حذف') }}</button></form></td>@endif
            </tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('لا توجد مواد بعد.') }}</td></tr>
        @endforelse
    </table></div>
    @if($bom)
        <form method="post" action="{{ route('design-versions.bom', $v) }}" class="inline-form" style="margin-top:12px">@csrf
            <select name="material_id" required>
                <option value="">{{ __('— الخامة —') }}</option>
                @foreach($materials as $m)<option value="{{ $m->id }}">{{ $m->code }} — {{ $m->name }} ({{ $m->uom }})</option>@endforeach
            </select>
            <input name="quantity" type="number" step="0.0001" min="0.0001" placeholder="{{ __('الكمية') }}" required dir="ltr">
            <input name="waste_pct" type="number" step="0.01" min="0" max="99.99" placeholder="{{ __('الهالك %') }}" dir="ltr">
            <button class="btn sm">{{ __('إضافة / تحديث') }}</button>
        </form>
        @if($materials->isEmpty())<p class="hint">{{ __('أضف الخامات أولًا من تطبيق المخزون.') }}</p>@endif
    @endif
</div>

@if($v->productionOrders->isNotEmpty())
<div class="card">
    <h2>{{ __('أوامر التصنيع على هذه النسخة') }}</h2>
    @foreach($v->productionOrders as $po)
        <a class="btn ghost sm" href="{{ route('production.show', $po) }}">{{ $po->order_no }} · {{ __("rroka.status.$po->status") }}</a>
    @endforeach
</div>
@endif
@include('partials.chatter', ['activity' => $activity])
@endsection
