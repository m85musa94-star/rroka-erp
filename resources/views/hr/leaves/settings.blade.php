@extends('layouts.app')
@section('title', __('أنواع الإجازات والأرصدة'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الإجازات'), route('leaves.index')], [__('أنواع الإجازات والأرصدة'), null]]])
@endsection
@section('content')
<div class="card">
    <h2>{{ __('أنواع الإجازات') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('النوع') }}</th><th>{{ __('مدفوعة') }}</th><th>{{ __('تتطلب رصيدًا') }}</th><th>{{ __('الحالة') }}</th></tr>
        @forelse($types as $t)
            <tr><td>{{ $t->name }}</td><td>{{ $t->is_paid ? __('نعم') : __('لا') }}</td><td>{{ $t->requires_allocation ? __('نعم') : __('لا') }}</td>
                <td><form method="post" action="{{ route('leave-types.update', $t) }}" class="inline">@csrf @method('put')
                    <input type="hidden" name="is_active" value="{{ $t->is_active ? 0 : 1 }}">
                    <button class="btn ghost sm">{{ $t->is_active ? __('إيقاف') : __('تفعيل') }}</button></form>
                    @unless($t->is_active)<span class="badge b-CANCELLED">{{ __('موقوف') }}</span>@endunless</td></tr>
        @empty
            <tr><td colspan="4" class="muted">{{ __('لا أنواع بعد.') }}</td></tr>
        @endforelse
    </table></div>
    <form method="post" action="{{ route('leave-types.store') }}" class="inline-form" style="margin-top:12px">@csrf
        <input name="name" required placeholder="{{ __('اسم النوع (سنوية، مرضية، بدون راتب…)') }}" style="min-width:240px">
        <label class="perm-item"><input type="checkbox" name="is_paid" value="1" style="width:auto"> {{ __('مدفوعة') }}</label>
        <label class="perm-item"><input type="checkbox" name="requires_allocation" value="1" style="width:auto"> {{ __('تتطلب رصيدًا ممنوحًا') }}</label>
        <button class="btn sm">{{ __('إضافة نوع') }}</button>
    </form>
    <p class="hint">{{ __('لا تُدخل الأنظمة أيام الإجازات تلقائيًا: الأرصدة تُمنح هنا وفق العقد ونظام العمل كما تعتمدها الإدارة.') }}</p>
</div>
<div class="card">
    <h2>{{ __('منح الأرصدة') }}</h2>
    <form method="post" action="{{ route('leave-allocations.store') }}" class="inline-form">@csrf
        <select name="employee_id" required><option value="">{{ __('— الموظف —') }}</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select>
        <select name="leave_type_id" required>@foreach($types->where('requires_allocation', true) as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>
        <input name="days" type="number" step="0.5" required placeholder="{{ __('الأيام') }}" dir="ltr" title="{{ __('سالب للخصم التصحيحي') }}">
        <input type="date" name="valid_from" value="{{ now()->startOfYear()->format('Y-m-d') }}" required title="{{ __('صالح من') }}">
        <input type="date" name="valid_to" value="{{ now()->endOfYear()->format('Y-m-d') }}" required title="{{ __('صالح حتى') }}">
        <input name="reason" required placeholder="{{ __('الأساس (الرصيد السنوي حسب العقد…)') }}">
        <button class="btn sm">{{ __('منح') }}</button>
    </form>
    <div class="table-wrap" style="margin-top:12px"><table>
        <tr><th>{{ __('الموظف') }}</th><th>{{ __('النوع') }}</th><th class="num">{{ __('الأيام') }}</th><th>{{ __('الصلاحية') }}</th><th>{{ __('الأساس') }}</th><th>{{ __('منحها') }}</th></tr>
        @forelse($allocations as $a)
            <tr><td>{{ $a->employee->name }}</td><td>{{ $a->type->name }}</td><td class="num">{{ $a->days + 0 }}</td>
                <td class="num">{{ $a->valid_from->format('Y-m-d') }} — {{ $a->valid_to->format('Y-m-d') }}</td><td>{{ $a->reason }}</td><td>{{ $a->approver->name }}</td></tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('لم تُمنح أرصدة بعد.') }}</td></tr>
        @endforelse
    </table></div>
    <p class="hint">{{ __('الأرصدة الممنوحة لا تُعدَّل ولا تُحذف؛ التصحيح يكون بقيد جديد (بالسالب للخصم) مع سببه.') }}</p>
</div>
@endsection
