@extends('layouts.app')
@section('title', __('معدلات التكلفة'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الإعدادات'), null], [__('معدلات التكلفة'), null]]])
@endsection
@section('content')
<div class="alert warn">
    {{ __('أدخل أرقامًا حقيقية من واقع الورشة فقط. لا تُعدَّل المعدلات ولا تُحذف: أي تغيير يُسجَّل معدلًا جديدًا بتاريخ سريان جديد،') }}
    {{ __('فتبقى تكلفة الأعمال السابقة على معدلاتها وقت التنفيذ.') }}
</div>

<div class="card">
    <h2>{{ __('العمال وأجر الساعة') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('العامل') }}</th><th>{{ __('المهنة') }}</th><th class="num">{{ __('أجر الساعة الساري') }}</th><th>{{ __('ساري من') }}</th><th>{{ __('أساس الاحتساب') }}</th><th>{{ __('معدل جديد') }}</th></tr>
        @forelse($workers as $w)
            @php($r = $w->rates->first(fn ($x) => $x->effective_from->lte(today())) ?? $w->rates->first())
            <tr>
                <td>{{ $w->name }}</td><td>{{ $w->trade ?? '—' }}</td>
                <td class="num">{!! $r ? number_format($r->hourly_cost, 2) : __('<span class="na">غير مُدخل</span>') !!}</td>
                <td class="num">{{ $r?->effective_from->format('Y-m-d') ?? '—' }}</td>
                <td>{{ $r?->basis_note ?? '—' }}</td>
                <td>
                    <form method="post" action="{{ route('rates.workers.rate', $w) }}" class="grid" style="grid-template-columns:90px 130px 1fr auto;gap:6px">
                        @csrf
                        <input name="hourly_cost" type="number" step="0.01" min="0.01" placeholder="{{ __('ريال/ساعة') }}" required dir="ltr">
                        <input name="effective_from" type="date" value="{{ now()->format('Y-m-d') }}" required>
                        <input name="basis_note" placeholder="{{ __('مثال: راتب ٣٠٠٠ + سكن ٥٠٠ + إقامة وتأمين ٤٠٠ ÷ ٢٠٨ ساعة') }}" required>
                        <button class="btn sm">{{ __('حفظ') }}</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('لم يُضف عمال بعد.') }}</td></tr>
        @endforelse
    </table></div>
    <form method="post" action="{{ route('rates.workers.store') }}" class="actions" style="margin-top:12px">
        @csrf
        <input name="name" placeholder="{{ __('اسم العامل') }}" required style="width:220px">
        <input name="trade" placeholder="{{ __('المهنة (نجار، دهّان…)') }}" style="width:200px">
        <button class="btn ghost sm">{{ __('إضافة عامل') }}</button>
    </form>
</div>

<div class="card">
    <h2>{{ __('الآلات وتكلفة ساعة التشغيل') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الرمز') }}</th><th>{{ __('الآلة') }}</th><th class="num">{{ __('تكلفة الساعة السارية') }}</th><th>{{ __('ساري من') }}</th><th>{{ __('أساس الاحتساب') }}</th><th>{{ __('معدل جديد') }}</th></tr>
        @forelse($machines as $mc)
            @php($r = $mc->rates->first(fn ($x) => $x->effective_from->lte(today())) ?? $mc->rates->first())
            <tr>
                <td>{{ $mc->code }}</td><td>{{ $mc->name }}</td>
                <td class="num">{!! $r ? number_format($r->hourly_cost, 2) : __('<span class="na">غير مُدخل</span>') !!}</td>
                <td class="num">{{ $r?->effective_from->format('Y-m-d') ?? '—' }}</td>
                <td>{{ $r?->basis_note ?? '—' }}</td>
                <td>
                    <form method="post" action="{{ route('rates.machines.rate', $mc) }}" class="grid" style="grid-template-columns:90px 130px 1fr auto;gap:6px">
                        @csrf
                        <input name="hourly_cost" type="number" step="0.01" min="0.01" placeholder="{{ __('ريال/ساعة') }}" required dir="ltr">
                        <input name="effective_from" type="date" value="{{ now()->format('Y-m-d') }}" required>
                        <input name="basis_note" placeholder="{{ __('مثال: إهلاك + كهرباء + صيانة ÷ ساعات التشغيل') }}" required>
                        <button class="btn sm">{{ __('حفظ') }}</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('لم تُضف آلات بعد.') }}</td></tr>
        @endforelse
    </table></div>
    <form method="post" action="{{ route('rates.machines.store') }}" class="actions" style="margin-top:12px">
        @csrf
        <input name="code" placeholder="{{ __('الرمز (CNC-1)') }}" required style="width:140px" dir="ltr">
        <input name="name" placeholder="{{ __('اسم الآلة') }}" required style="width:220px">
        <button class="btn ghost sm">{{ __('إضافة آلة') }}</button>
    </form>
</div>

<div class="card">
    <h2>{{ __('نسبة المصروفات الصناعية غير المباشرة') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('ساري من') }}</th><th>{{ __('الأساس') }}</th><th class="num">{{ __('النسبة') }}</th><th>{{ __('أساس الاحتساب') }}</th></tr>
        @forelse($overheads as $o)
            <tr><td class="num">{{ $o->effective_from->format('Y-m-d') }}</td><td>{{ __("rroka.overhead_basis.$o->basis") }}</td>
                <td class="num">{{ $o->rate_pct + 0 }}%</td><td>{{ $o->basis_note }}</td></tr>
        @empty
            <tr><td colspan="4"><span class="na">{{ __('غير مُدخلة') }}</span> {{ __('— لن يظهر ربح أي مشروع حتى تُدخل.') }}</td></tr>
        @endforelse
    </table></div>
    <form method="post" action="{{ route('rates.overhead.store') }}" class="grid g4" style="margin-top:12px;align-items:end">
        @csrf
        <div class="field"><label>{{ __('الأساس') }}</label>
            <select name="basis">@foreach(['PCT_OF_DIRECT_LABOR', 'PCT_OF_PRIME_COST'] as $b)<option value="{{ $b }}">{{ __("rroka.overhead_basis.$b") }}</option>@endforeach</select>
        </div>
        <div class="field"><label>{{ __('النسبة %') }}</label><input name="rate_pct" type="number" step="0.01" min="0" required dir="ltr"></div>
        <div class="field"><label>{{ __('ساري من') }}</label><input name="effective_from" type="date" value="{{ now()->format('Y-m-d') }}" required></div>
        <div class="field"><label>{{ __('أساس الاحتساب') }}</label><input name="basis_note" placeholder="{{ __('مثال: (إيجار + كهرباء عامة + …) ÷ العمالة المباشرة السنوية') }}" required></div>
        <div><button class="btn">{{ __('حفظ النسبة') }}</button></div>
    </form>
</div>
@endsection
