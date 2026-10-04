@extends('layouts.app')
@section('title', __('مراكز التكلفة'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('محرك التكلفة'), route('costing.index')], [__('مراكز التكلفة'), null]]])
@endsection
@section('content')
@php($canEdit = auth()->user()->hasPermission('settings.cost_rates'))
@if($centers->isEmpty() && $canEdit)
<div class="card">
    <p style="margin-top:0">{{ __('المواصفة المعتمدة تقترح سبعة مراكز: النجارة، الماكينات، التنجيد، الدهان، التجميع، التركيب، خدمات الإنتاج.') }}</p>
    <form method="post" action="{{ route('costing.centers.spec') }}" data-confirm="{{ __('إنشاء المراكز السبعة الواردة في المواصفة؟') }}">@csrf<button class="btn">{{ __('إنشاء مراكز المواصفة') }}</button></form>
</div>
@endif
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الرمز') }}</th><th>{{ __('الاسم') }}</th><th>{{ __('محرك التحميل') }}</th><th>{{ __('القسم') }}</th><th>{{ __('نشط') }}</th>@if($canEdit)<th></th>@endif</tr>
    @forelse($centers as $c)
        <tr>
            @if($canEdit)
                @php($f = 'cc'.$c->id)
                <td><input form="{{ $f }}" name="code" value="{{ $c->code }}" dir="ltr" style="width:110px"></td>
                <td><input form="{{ $f }}" name="name" value="{{ $c->name }}"></td>
                <td><select form="{{ $f }}" name="driver">@foreach(\App\Models\CostCenter::DRIVERS as $d)<option value="{{ $d }}" @selected($c->driver === $d)>{{ __("rroka.driver.$d") }}</option>@endforeach</select></td>
                <td><select form="{{ $f }}" name="department_id"><option value="">—</option>@foreach($departments as $d)<option value="{{ $d->id }}" @selected($c->department_id === $d->id)>{{ $d->name }}</option>@endforeach</select></td>
                <td><input form="{{ $f }}" type="hidden" name="is_active" value="0"><input form="{{ $f }}" type="checkbox" name="is_active" value="1" @checked($c->is_active)></td>
                <td><form id="{{ $f }}" method="post" action="{{ route('costing.centers.update', $c) }}">@csrf @method('put')<button class="btn ghost sm">{{ __('حفظ') }}</button></form></td>
            @else
                <td class="num">{{ $c->code }}</td><td>{{ $c->name }}</td><td>{{ __("rroka.driver.$c->driver") }}</td><td>{{ $departments->firstWhere('id', $c->department_id)?->name ?? '—' }}</td><td>{{ $c->is_active ? __('نعم') : __('لا') }}</td>
            @endif
        </tr>
    @empty
        <tr><td colspan="6" class="muted">{{ __('لم تُضف مراكز تكلفة بعد.') }}</td></tr>
    @endforelse
</table></div></div>
@if($canEdit)
<form method="post" action="{{ route('costing.centers.store') }}" class="card">@csrf
    <h2>{{ __('مركز تكلفة جديد') }}</h2>
    <div class="grid g4">
        <div class="field"><label>{{ __('الرمز *') }}</label><input name="code" required dir="ltr" value="{{ old('code') }}"></div>
        <div class="field"><label>{{ __('الاسم *') }}</label><input name="name" required value="{{ old('name') }}"></div>
        <div class="field"><label>{{ __('محرك التحميل *') }}</label><select name="driver" required>@foreach(\App\Models\CostCenter::DRIVERS as $d)<option value="{{ $d }}">{{ __("rroka.driver.$d") }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('القسم') }}</label><select name="department_id"><option value="">—</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
    </div>
    <div class="actions"><button class="btn">{{ __('إضافة') }}</button></div>
</form>
@endif
<p class="hint">{{ __('محرك التحميل: كيف تُحمَّل التكاليف غير المباشرة للمركز على المنتج — بساعات العمل المباشر (النجارة، التنجيد، الدهان…) أو بساعات الآلات (الماكينات).') }}</p>
@endsection
