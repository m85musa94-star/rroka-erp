@extends('layouts.app')
@section('title', __('دليل الحسابات'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('المحاسبة'), route('accounting.journal.index')], [__('دليل الحسابات'), null]]])
@endsection
@section('content')
@php($canEdit = auth()->user()->hasPermission('accounting.manage'))
@if($empty)
<div class="card empty-state">
    <strong>{{ __('دليل الحسابات فارغ') }}</strong>
    {{ __('يمكن البدء بدليل مقترح لورشة تصنيع أثاث (أصول، خصوم، حقوق ملكية، إيرادات، تكاليف ومصروفات)، ثم مراجعته وتعديله. الحسابات البنكية تضاف حسابًا لكل بنك فعلي تحت «البنوك».') }}
    @if($canEdit)
        <form method="post" action="{{ route('accounting.accounts.template') }}" data-confirm="{{ __('إنشاء الدليل المقترح؟ يمكن تعديله بعد ذلك.') }}" style="margin-top:12px">@csrf<button class="btn">{{ __('إنشاء الدليل المقترح') }}</button></form>
    @endif
</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الرمز') }}</th><th>{{ __('الحساب') }}</th><th>{{ __('النوع') }}</th><th>{{ __('دور نظامي') }}</th><th class="num">{{ __('الرصيد') }}</th><th></th></tr>
    @foreach($rows as $r)
        @php($a = $r['a'])
        <tr @class(['grp' => ! $a->is_postable, 'muted' => ! $a->is_active])>
            <td class="num" dir="ltr">{{ $a->code }}</td>
            <td style="padding-inline-start: {{ 12 + $r['depth'] * 22 }}px">
                <a href="{{ route('accounting.accounts.show', $a) }}">{{ $a->label() }}</a>
                @unless($a->is_active)<span class="badge b-CANCELLED">{{ __('موقوف') }}</span>@endunless
            </td>
            <td>{{ __("rroka.account_type.$a->account_type") }}</td>
            <td>{{ $a->system_role ? __("rroka.account_role.$a->system_role") : '' }}</td>
            <td class="num">{{ $r['debit'] || $r['credit'] ? number_format($r['balance'], 2) : '—' }}</td>
            <td>@if($canEdit)<a class="btn ghost sm" href="{{ route('accounting.accounts.index', ['edit' => $a->id] + request()->only('inactive')) }}#account-form">{{ __('تعديل') }}</a>@endif</td>
        </tr>
    @endforeach
</table></div></div>
<p class="hint">
    {{ __('الرصيد من القيود المرحَّلة فقط، بطبيعة الحساب (الأصول والمصروفات: مدين − دائن؛ الخصوم وحقوق الملكية والإيرادات: دائن − مدين). الحساب التجميعي يعرض مجموع ما تحته.') }}
    <a href="{{ route('accounting.accounts.index', request()->boolean('inactive') ? [] : ['inactive' => 1]) }}">{{ request()->boolean('inactive') ? __('إخفاء الموقوفة') : __('إظهار الموقوفة') }}</a>
</p>
@endif

@if($canEdit && ! $empty)
@php($e = $edit)
<form id="account-form" method="post" action="{{ $e ? route('accounting.accounts.update', $e) : route('accounting.accounts.store') }}" class="card">@csrf
    @if($e) @method('put') @endif
    <h2>{{ $e ? __('تعديل الحساب :code', ['code' => $e->code]) : __('حساب جديد') }}</h2>
    <div class="grid g4">
        <div class="field"><label>{{ __('الرمز *') }}</label><input name="code" required dir="ltr" inputmode="numeric" value="{{ old('code', $e?->code) }}"><div class="hint">{{ __('أرقام فقط؛ يبدأ عادة برمز الحساب الأب.') }}</div></div>
        <div class="field"><label>{{ __('الاسم *') }}</label><input name="name" required value="{{ old('name', $e?->name) }}"></div>
        <div class="field"><label>{{ __('الاسم بالإنجليزية') }}</label><input name="name_en" dir="ltr" value="{{ old('name_en', $e?->name_en) }}"></div>
        <div class="field"><label>{{ __('النوع *') }}</label>
            <select name="account_type" required>@foreach(\App\Models\Account::TYPES as $t)<option value="{{ $t }}" @selected(old('account_type', $e?->account_type) === $t)>{{ __("rroka.account_type.$t") }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('تحت الحساب') }}</label>
            <select name="parent_id"><option value="">{{ __('— حساب رئيسي —') }}</option>@foreach($groups as $g)@continue($e && $g->id === $e->id)<option value="{{ $g->id }}" @selected((string) old('parent_id', $e?->parent_id) === (string) $g->id)>{{ $g->code }} — {{ $g->label() }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('دور نظامي') }}</label>
            <select name="system_role"><option value="">—</option>@foreach(\App\Models\Account::ROLES as $role)@continue(in_array($role, $usedRoles, true) && $e?->system_role !== $role)<option value="{{ $role }}" @selected(old('system_role', $e?->system_role) === $role)>{{ __("rroka.account_role.$role") }}</option>@endforeach</select>
            <div class="hint">{{ __('الحساب الذي يُرحِّل إليه النظام آليًا (مثل ضريبة المدخلات)؛ حساب واحد لكل دور.') }}</div></div>
        <div class="field"><label><input type="hidden" name="is_postable" value="0"><input type="checkbox" name="is_postable" value="1" @checked(old('is_postable', $e ? $e->is_postable : true))> {{ __('يقبل القيود (حساب فرعي)') }}</label>
            <div class="hint">{{ __('ألغِ الاختيار للحساب التجميعي الذي تُضاف تحته حسابات.') }}</div></div>
        @if($e)<div class="field"><label><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $e->is_active))> {{ __('نشط') }}</label></div>@endif
    </div>
    <div class="field"><label>{{ __('ملاحظات') }}</label><textarea name="notes">{{ old('notes', $e?->notes) }}</textarea></div>
    <div class="actions"><button class="btn">{{ $e ? __('حفظ') : __('إضافة') }}</button>@if($e)<a class="btn ghost" href="{{ route('accounting.accounts.index') }}">{{ __('إلغاء') }}</a>@endif</div>
    <p class="hint">{{ __('الحساب الذي عليه قيود يحتفظ برمزه ونوعه ويبقى قابلًا للقيد؛ يمكن إيقافه بدل حذفه.') }}</p>
</form>
@endif
@endsection
