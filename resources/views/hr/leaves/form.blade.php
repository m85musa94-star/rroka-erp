@extends('layouts.app')
@section('title', __('طلب إجازة'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الإجازات'), route('leaves.index')], [__('طلب إجازة'), null]]])
@endsection
@section('content')
@if($types->isEmpty())
    <div class="card empty-state"><strong>{{ __('لا توجد أنواع إجازات بعد') }}</strong>{{ __('يضيفها من يملك صلاحية اعتماد الإجازات من «أنواع الإجازات والأرصدة».') }}</div>
@else
<form method="post" action="{{ route('leaves.store') }}" class="card">
    @csrf
    <div class="grid g3">
        <div class="field"><label>{{ __('الموظف *') }}</label>
            <select name="employee_id" required>@foreach($employees as $e)<option value="{{ $e->id }}" @selected((string) old('employee_id', $selected) === (string) $e->id)>{{ $e->name }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('نوع الإجازة *') }}</label>
            <select name="leave_type_id" required>@foreach($types as $t)<option value="{{ $t->id }}" @selected((string) old('leave_type_id') === (string) $t->id)>{{ $t->name }}{{ $t->is_paid ? '' : ' — '.__('غير مدفوعة') }}</option>@endforeach</select></div>
        <div class="field"></div>
        <div class="field"><label>{{ __('من *') }}</label><input type="date" name="date_from" id="d1" value="{{ old('date_from') }}" required></div>
        <div class="field"><label>{{ __('إلى *') }}</label><input type="date" name="date_to" id="d2" value="{{ old('date_to') }}" required></div>
        <div class="field"><label>{{ __('الأيام المحتسبة *') }}</label><input type="number" name="days" id="days" step="0.5" min="0.5" value="{{ old('days') }}" required dir="ltr">
            <div class="hint">{{ __('تُقترح أيام التقويم كاملة؛ عدّلها وفق سياسة الورشة إن كانت العطل لا تُحتسب.') }}</div></div>
    </div>
    <div class="field"><label>{{ __('السبب') }}</label><input name="reason" value="{{ old('reason') }}"></div>
    <div class="actions"><button class="btn">{{ __('إرسال للاعتماد') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endif
@endsection
@push('scripts')
<script>
(() => {
    const d1 = document.getElementById('d1'), d2 = document.getElementById('d2'), days = document.getElementById('days');
    if (!d1) return;
    const sync = () => { if (d1.value && d2.value && d2.value >= d1.value) days.value = (new Date(d2.value) - new Date(d1.value)) / 864e5 + 1; };
    d1.addEventListener('change', () => { if (!d2.value || d2.value < d1.value) d2.value = d1.value; sync(); });
    d2.addEventListener('change', sync);
})();
</script>
@endpush
