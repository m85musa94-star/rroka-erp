@extends('layouts.app')
@section('title', $e->name)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الموظفون'), route('employees.index')], [$e->name, null]]])
@endsection
@section('content')
@php($u = auth()->user())
<div class="rec-bar">
    <div class="actions">
        @if($full)
            <a class="btn sm" href="{{ route('employees.edit', $e) }}">{{ __('تعديل') }}</a>
            <details class="inline-details"><summary class="btn ghost sm">{{ $e->is_active ? __('إنهاء الخدمة') : __('إعادة للعمل') }}</summary>
                <form method="post" action="{{ route('employees.employment', $e) }}" class="inline-form" style="margin-top:6px">@csrf
                    @if($e->is_active)
                        <input type="date" name="termination_date" value="{{ now()->format('Y-m-d') }}" required title="{{ __('تاريخ آخر يوم عمل') }}">
                        <input name="termination_reason" required placeholder="{{ __('السبب') }}">
                        <button class="btn bad sm">{{ __('تسجيل إنهاء الخدمة') }}</button>
                    @else
                        <input type="date" name="hire_date" value="{{ now()->format('Y-m-d') }}" required title="{{ __('تاريخ التعيين') }}">
                        <button class="btn sm">{{ __('إعادة للعمل') }}</button>
                    @endif
                </form>
            </details>
        @endif
        @if($u->hasPermission('settings.cost_rates'))<a class="btn ghost sm" href="{{ route('rates.index') }}">{{ __('معدل التكلفة') }}</a>@endif
    </div>
    @if($e->is_active)<span class="badge b-ACTIVE">{{ __('على رأس العمل') }}</span>@else<span class="badge b-CANCELLED">{{ __('انتهت خدمته') }}</span>@endif
</div>
<div class="card">
    <h1 class="rec-title">{{ $e->name }}</h1>
    <p class="rec-sub">{{ $e->employee_no }} · {{ $e->job?->name ?? $e->trade ?? '—' }}</p>
    <div class="grid g2">
        <dl class="kv">
            <dt>{{ __('القسم') }}</dt><dd>{{ $e->department?->name ?? '—' }}</dd>
            <dt>{{ __('المدير المباشر') }}</dt><dd>@if($e->manager)<a href="{{ route('employees.show', $e->manager) }}">{{ $e->manager->name }}</a>@else — @endif</dd>
            <dt>{{ __('المهنة') }}</dt><dd>{{ $e->trade ?? '—' }}</dd>
            <dt>{{ __('نوع التوظيف') }}</dt><dd>{{ $e->employment_type ? __("rroka.employment_type.$e->employment_type") : '—' }}</dd>
            <dt>{{ __('العمالة') }}</dt><dd>{{ $e->is_direct_labor ? __('مباشرة (إنتاج)') : __('غير مباشرة') }}</dd>
        </dl>
        <dl class="kv">
            <dt>{{ __('هاتف العمل') }}</dt><dd dir="ltr" class="ltr-in">{{ $e->work_phone ?? '—' }}</dd>
            <dt>{{ __('بريد العمل') }}</dt><dd>{{ $e->work_email ?? '—' }}</dd>
            <dt>{{ __('تاريخ التعيين') }}</dt><dd>{{ $e->hire_date?->format('Y-m-d') ?? '—' }}</dd>
            @unless($e->is_active)<dt>{{ __('انتهاء الخدمة') }}</dt><dd>{{ $e->termination_date?->format('Y-m-d') }} — {{ $e->termination_reason }}</dd>@endunless
            @if($e->subordinates->isNotEmpty())<dt>{{ __('المرؤوسون') }}</dt><dd>@foreach($e->subordinates as $s)<a href="{{ route('employees.show', $s) }}">{{ $s->name }}</a>@if(! $loop->last)، @endif @endforeach</dd>@endif
        </dl>
    </div>
</div>

@if($full)
<div class="card">
    <h2>{{ __('البيانات الشخصية') }}</h2>
    <div class="grid g2">
        <dl class="kv">
            <dt>{{ __('الجنسية') }}</dt><dd>{{ $e->nationality ?? '—' }}</dd>
            <dt>{{ __('الهوية') }}</dt><dd>{{ $e->id_type ? __("rroka.doc_type.$e->id_type") : '' }} <bdi dir="ltr">{{ $e->id_number ?? '—' }}</bdi></dd>
            <dt>{{ __('تاريخ الميلاد') }}</dt><dd>{{ $e->birth_date?->format('Y-m-d') ?? '—' }}</dd>
            <dt>{{ __('الجنس') }}</dt><dd>{{ $e->gender === 'M' ? __('ذكر') : ($e->gender === 'F' ? __('أنثى') : '—') }}</dd>
        </dl>
        <dl class="kv">
            <dt>{{ __('الجوال الشخصي') }}</dt><dd dir="ltr" class="ltr-in">{{ $e->mobile ?? '—' }}</dd>
            <dt>{{ __('الآيبان (IBAN)') }}</dt><dd dir="ltr" class="ltr-in">{{ $e->iban ?? '—' }}</dd>
            <dt>{{ __('الطوارئ') }}</dt><dd>{{ collect([$e->emergency_contact, $e->emergency_phone])->filter()->join(' — ') ?: '—' }}</dd>
            <dt>{{ __('حساب المستخدم') }}</dt><dd>{{ $e->user?->email ?? '—' }}</dd>
        </dl>
    </div>
    @if($e->address || $e->notes)<p style="margin-top:10px">{{ $e->address }} @if($e->notes)<br><span class="muted">{{ $e->notes }}</span>@endif</p>@endif
</div>

<div class="card">
    <h2>{{ __('الوثائق') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('النوع') }}</th><th>{{ __('الرقم') }}</th><th>{{ __('الإصدار') }}</th><th>{{ __('الانتهاء') }}</th><th>{{ __('ملاحظات') }}</th><th></th></tr>
        @forelse($e->documents as $d)
            @php($st = $d->state())
            <tr>
                <td>{{ __("rroka.doc_type.$d->doc_type") }}</td>
                <td class="num">{{ $d->doc_number ?? '—' }}</td>
                <td class="num">{{ $d->issue_date?->format('Y-m-d') ?? '—' }}</td>
                <td class="num">{{ $d->expiry_date?->format('Y-m-d') ?? '—' }}
                    @if($st === 'expired')<span class="badge b-FAILED">{{ __('منتهية') }}</span>@elseif($st === 'soon')<span class="badge b-ON_HOLD">{{ __('قريبة الانتهاء') }}</span>@endif</td>
                <td>{{ $d->notes ?? '—' }}</td>
                <td>
                    <details class="inline-details"><summary class="btn ghost sm">{{ __('تعديل') }}</summary>
                        <form method="post" action="{{ route('employee-documents.update', $d) }}" class="inline-form" style="margin-top:6px">@csrf @method('put')
                            <input type="hidden" name="doc_type" value="{{ $d->doc_type }}">
                            <input name="doc_number" value="{{ $d->doc_number }}" placeholder="{{ __('الرقم') }}" dir="ltr">
                            <input type="date" name="issue_date" value="{{ $d->issue_date?->format('Y-m-d') }}" title="{{ __('الإصدار') }}">
                            <input type="date" name="expiry_date" value="{{ $d->expiry_date?->format('Y-m-d') }}" title="{{ __('الانتهاء') }}">
                            <input name="notes" value="{{ $d->notes }}" placeholder="{{ __('ملاحظات') }}">
                            <button class="btn sm">{{ __('حفظ') }}</button>
                        </form>
                    </details>
                    <form method="post" action="{{ route('employee-documents.destroy', $d) }}" class="inline" data-confirm="{{ __('حذف الوثيقة؟') }}">@csrf @method('delete')<button class="btn ghost sm">{{ __('حذف') }}</button></form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('لا وثائق مسجلة.') }}</td></tr>
        @endforelse
    </table></div>
    <form method="post" action="{{ route('employees.documents.store', $e) }}" class="inline-form" style="margin-top:12px">@csrf
        <select name="doc_type" required>@foreach(\App\Models\EmployeeDocument::TYPES as $t)<option value="{{ $t }}">{{ __("rroka.doc_type.$t") }}</option>@endforeach</select>
        <input name="doc_number" placeholder="{{ __('الرقم') }}" dir="ltr">
        <input type="date" name="issue_date" title="{{ __('الإصدار') }}">
        <input type="date" name="expiry_date" title="{{ __('الانتهاء') }}">
        <input name="notes" placeholder="{{ __('ملاحظات (إلزامية لنوع «أخرى»)') }}">
        <button class="btn sm">{{ __('إضافة وثيقة') }}</button>
    </form>
    <p class="hint">{{ __('تظهر الوثيقة «قريبة الانتهاء» قبل :n يومًا من تاريخ انتهائها.', ['n' => \App\Models\EmployeeDocument::WARN_DAYS]) }}</p>
</div>
@include('partials.chatter', ['activity' => $activity])
@endif
@endsection
