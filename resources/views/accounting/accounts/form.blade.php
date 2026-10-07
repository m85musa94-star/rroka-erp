@extends('layouts.app')
@section('title', $a->exists ? $a->code.' '.$a->label() : __('حساب جديد'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('دليل الحسابات'), route('accounting.accounts.index')], [$a->exists ? $a->code.' '.$a->label() : __('جديد'), null]]])
@endsection
@section('content')
@php($canEdit = auth()->user()->hasPermission('accounting.manage'))
@php($cur = old('detail_type', $a->detail_type))
<form method="post" action="{{ $a->exists ? route('accounting.accounts.update', $a) : route('accounting.accounts.store') }}">
    @csrf
    @if($a->exists) @method('put') @endif
    <div class="card">
        @if($a->exists)
        <div class="smart-buttons">
            <a class="smart-btn" href="{{ route('accounting.accounts.show', $a) }}">
                <strong>{{ $totals->n ? number_format($a->isDebitNature() ? $totals->debit - $totals->credit : $totals->credit - $totals->debit, 2) : '—' }}</strong>
                <span>{{ __('الرصيد · دفتر الأستاذ') }}</span></a>
            <a class="smart-btn" href="{{ route('accounting.journal.index', ['q' => $a->code]) }}"><strong>{{ (int) $totals->n }}</strong><span>{{ __('سطور قيود مرحَّلة') }}</span></a>
        </div>
        @endif
        <div class="grid g2" style="align-items:end">
            <div class="field"><label>{{ __('اسم الحساب *') }}</label><input name="name" required value="{{ old('name', $a->name) }}" class="input-lg"></div>
            <div class="field"><label>{{ __('الرمز *') }}</label><input name="code" required dir="ltr" inputmode="numeric" value="{{ old('code', $a->code) }}"></div>
        </div>
        <div class="grid g2">
            <div>
                <div class="field"><label>{{ __('النوع *') }}</label>
                    <select name="detail_type" id="detail_type" required>
                        <option value="">{{ __('— اختر النوع —') }}</option>
                        @foreach(collect(\App\Models\Account::DETAIL_TYPES)->groupBy(fn ($c) => $c, preserveKeys: true) as $class => $types)
                            <optgroup label="{{ __("rroka.account_type.$class") }}">
                                @foreach($types as $t => $c)<option value="{{ $t }}" @selected($cur === $t)>{{ __("rroka.detail_type.$t") }}</option>@endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <div class="hint">{{ __('النوع يحدد مكان الحساب في الميزانية وقائمة الدخل.') }}</div></div>
                <div class="field"><label><input type="checkbox" name="reconcile" value="1" id="reconcile" @checked(old('reconcile', $a->reconcile))> {{ __('تسمح بالتسوية') }}</label>
                    <div class="hint">{{ __('لمطابقة الحركات المدينة بالدائنة (فاتورة بسدادها). العملاء والموردون تسمح بها دائمًا.') }}</div></div>
            </div>
            <div>
                <div class="field"><label>{{ __('مجموعة الحسابات') }}</label>
                    <select name="parent_id"><option value="">{{ __('— بلا مجموعة —') }}</option>
                        @foreach($groupList as $g)<option value="{{ $g->id }}" data-class="{{ $g->account_type }}" @selected((string) old('parent_id', $a->parent_id) === (string) $g->id)>{{ $g->code }} {{ $g->label() }}</option>@endforeach</select>
                    <div class="hint">{{ __('من التصنيف نفسه (أصول، خصوم…).') }}</div></div>
                <div class="field"><label>{{ __('الاسم بالإنجليزية') }}</label><input name="name_en" dir="ltr" value="{{ old('name_en', $a->name_en) }}"></div>
            </div>
        </div>
        <details @if(old('system_role', $a->system_role) || old('notes', $a->notes)) open @endif>
            <summary>{{ __('خيارات متقدمة') }}</summary>
            <div class="grid g2" style="margin-top:10px">
                <div class="field"><label>{{ __('دور نظامي') }}</label>
                    <select name="system_role"><option value="">—</option>@foreach(\App\Models\Account::ROLES as $role)@continue(in_array($role, $usedRoles, true))<option value="{{ $role }}" @selected(old('system_role', $a->system_role) === $role)>{{ __("rroka.account_role.$role") }}</option>@endforeach</select>
                    <div class="hint">{{ __('الحساب الذي يُرحِّل إليه النظام آليًا (مثل ضريبة المدخلات)؛ حساب واحد لكل دور.') }}</div></div>
                <div class="field"><label>{{ __('ملاحظات') }}</label><textarea name="notes">{{ old('notes', $a->notes) }}</textarea></div>
                @if($a->exists)<div class="field"><label><input type="checkbox" name="archived" value="1" @checked(old('archived', ! $a->is_active))> {{ __('مؤرشف') }}</label>
                    <div class="hint">{{ __('الحساب المؤرشف لا يقبل قيودًا جديدة ويبقى في التقارير بحركته.') }}</div></div>@endif
            </div>
        </details>
    </div>
    @if($canEdit)<div class="actions"><button class="btn">{{ __('حفظ') }}</button><a class="btn ghost" href="{{ route('accounting.accounts.index') }}">{{ __('إلغاء') }}</a></div>@endif
    @if($a->exists)<p class="hint">{{ __('الحساب الذي عليه قيود يحتفظ برمزه ونوعه ويبقى قابلًا للقيد؛ يمكن أرشفته بدل حذفه.') }}</p>@endif
</form>
    @if($a->exists)<div style="margin:8px 0">@include('partials.delete-button', ['type' => 'accounts', 'model' => $a])</div>@endif
@if($a->exists)@include('partials.chatter', ['activity' => $activity])@endif
@endsection
@push('scripts')
<script>
(() => {
    const t = document.getElementById('detail_type'), r = document.getElementById('reconcile');
    const sync = () => { const forced = ['RECEIVABLE', 'PAYABLE'].includes(t.value); if (forced) r.checked = true; r.disabled = forced; };
    t.addEventListener('change', sync); sync();
    r.form.addEventListener('submit', () => { r.disabled = false; });
})();
</script>
@endpush
