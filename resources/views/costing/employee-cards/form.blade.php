@extends('layouts.app')
@section('title', $card->exists ? __('تعديل بطاقة التكلفة') : __('بطاقة تكلفة موظف'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('تكلفة الموظفين'), route('costing.employee-cards.index')], [$card->exists ? $card->employee->name : __('جديد'), null]]])
@endsection
@section('content')
@php($v = fn ($k) => old($k, $card->{$k}))
@php($costLabels = ['basic_salary' => __('الراتب الأساسي *'), 'housing' => __('السكن *'), 'transportation' => __('النقل *'), 'insurance' => __('التأمينات الاجتماعية والطبية *'), 'government_fees' => __('الإقامة والرسوم الحكومية (شهريًا) *'), 'allowances' => __('البدلات *'), 'other_costs' => __('تكاليف أخرى *')])
@php($hourLabels = ['break_hours' => __('الاستراحات'), 'cleaning_hours' => __('التنظيف'), 'maintenance_hours' => __('الصيانة'), 'setup_hours' => __('التجهيز'), 'meeting_hours' => __('الاجتماعات'), 'downtime_hours' => __('التوقف المعتاد'), 'waiting_hours' => __('انتظار المواد'), 'other_nonproductive_hours' => __('وقت غير منتج آخر')])
@if(! $card->exists && $card->employee_id && ! $hasContract)
    <div class="alert warn">{{ __('لا يوجد عقد ساري لهذا الموظف؛ أدخل المكونات من مستنداتها.') }}</div>
@endif
@if($contract)
    <div class="alert ok">{{ __('نُسخ الراتب والسكن والنقل والبدلات من العقد :no. أكمل التأمينات والرسوم والساعات من مستنداتها.', ['no' => $contract->contract_no]) }}</div>
@endif
<form method="post" action="{{ $card->exists ? route('costing.employee-cards.update', $card) : route('costing.employee-cards.store') }}" class="card" id="card-form">
    @csrf
    @if($card->exists) @method('put') @endif
    <div class="grid g3">
        <div class="field"><label>{{ __('الموظف *') }}</label>
            @if($card->exists)<input value="{{ $card->employee->name }}" disabled>
            @else
                <select name="employee_id" required onchange="location='{{ route('costing.employee-cards.create') }}?employee_id='+this.value"><option value="">{{ __('— اختر —') }}</option>
                    @foreach($employees as $e)<option value="{{ $e->id }}" @selected((string) $v('employee_id') === (string) $e->id)>{{ $e->name }}{{ $e->is_direct_labor ? '' : ' — '.__('غير مباشر') }}</option>@endforeach</select>
                @if($hasContract && ! $contract)<a class="hint" href="{{ route('costing.employee-cards.create', ['employee_id' => $card->employee_id, 'from_contract' => 1]) }}">{{ __('تعبئة من العقد الساري') }}</a>@endif
            @endif
        </div>
    </div>
    <h2>{{ __('التكلفة الشهرية (ريال)') }}</h2>
    <div class="grid g4">
        @foreach($costLabels as $k => $label)
            <div class="field"><label>{{ $label }}</label><input name="{{ $k }}" type="number" step="0.01" min="0" value="{{ $v($k) }}" required dir="ltr" data-cost></div>
        @endforeach
    </div>
    <h2>{{ __('الطاقة الإنتاجية الشهرية (ساعات)') }}</h2>
    <div class="grid g4">
        <div class="field"><label>{{ __('ساعات الحضور النظرية *') }}</label><input name="theoretical_hours" type="number" step="0.01" min="0.01" value="{{ $v('theoretical_hours') }}" required dir="ltr" id="theo"></div>
    </div>
    <p class="hint">{{ __('اطرح الوقت غير المنتج المعتاد بندًا بندًا (اكتب 0 لما لا ينطبق). لا تخفّض الطاقة لتغطية ضعف الإنتاجية؛ الفرق يظهر لاحقًا في تحليل الانحرافات.') }}</p>
    <div class="grid g4">
        @foreach($hourLabels as $k => $label)
            <div class="field"><label>{{ $label }} *</label><input name="{{ $k }}" type="number" step="0.01" min="0" value="{{ $v($k) }}" required dir="ltr" data-ded></div>
        @endforeach
    </div>
    <div class="acct-figure" style="margin:12px 0">
        <span class="label">{{ __('معاينة') }}</span>
        <span class="value num" id="preview">—</span>
        <span class="hint">{{ __('التكلفة الشهرية ÷ الساعات العملية = تكلفة الساعة الإنتاجية') }}</span>
    </div>
    @include('costing._fields', ['r' => $card])
    <div class="actions"><button class="btn">{{ __('حفظ كمسودة') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
@push('scripts')
<script>
(() => { const f = document.getElementById('card-form'), out = document.getElementById('preview');
    const sum = sel => [...f.querySelectorAll(sel)].reduce((a, i) => a + (parseFloat(i.value) || 0), 0);
    const calc = () => { const cost = sum('[data-cost]'), hrs = (parseFloat(document.getElementById('theo').value) || 0) - sum('[data-ded]');
        out.textContent = cost > 0 && hrs > 0 ? cost.toFixed(2) + ' ÷ ' + hrs.toFixed(2) + ' = ' + (cost / hrs).toFixed(2) : '—'; };
    f.addEventListener('input', calc); calc(); })();
</script>
@endpush
