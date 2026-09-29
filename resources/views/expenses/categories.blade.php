@extends('layouts.app')
@section('title', __('تصنيفات المصروفات'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('المصروفات'), route('expenses.index')], [__('تصنيفات المصروفات'), null]]])
@endsection
@section('content')
<div class="card">
    <div class="table-wrap"><table>
        <tr><th>{{ __('التصنيف') }}</th><th>{{ __('غير مباشر للورشة') }}</th><th>{{ __('حساب دفترة المقابل') }}</th><th>{{ __('الحالة') }}</th><th></th></tr>
        @forelse($categories as $c)
            @php($f = 'cat-'.$c->id)
            <tr><td>{{ $c->name }}</td>
                <td><input type="hidden" name="is_overhead" value="0" form="{{ $f }}"><input type="checkbox" name="is_overhead" value="1" style="width:auto" form="{{ $f }}" @checked($c->is_overhead)></td>
                <td><input name="daftra_account_ref" value="{{ $c->daftra_account_ref }}" form="{{ $f }}" placeholder="{{ __('يُحدَّد بعد التحقق من ربط دفترة') }}"></td>
                <td><input type="hidden" name="is_active" value="0" form="{{ $f }}"><label class="perm-item"><input type="checkbox" name="is_active" value="1" style="width:auto" form="{{ $f }}" @checked($c->is_active)> {{ __('نشط') }}</label></td>
                <td><form method="post" action="{{ route('expense-categories.update', $c) }}" id="{{ $f }}">@csrf @method('put')<button class="btn ghost sm">{{ __('حفظ') }}</button></form></td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">{{ __('لا تصنيفات بعد.') }}</td></tr>
        @endforelse
    </table></div>
    <form method="post" action="{{ route('expense-categories.store') }}" class="inline-form" style="margin-top:12px">@csrf
        <input name="name" required placeholder="{{ __('اسم التصنيف (إيجار، كهرباء، نقل، صيانة…)') }}" style="min-width:260px">
        <label class="perm-item"><input type="checkbox" name="is_overhead" value="1" style="width:auto"> {{ __('مصروف غير مباشر للورشة') }}</label>
        <button class="btn sm">{{ __('إضافة تصنيف') }}</button>
    </form>
    <p class="hint">{{ __('«غير مباشر للورشة»: إيجار الورشة وكهرباؤها وصيانة الآلات ونحوها، ويُقارن مجموعه بما حُمّل على المشاريع بنسبة المصروفات غير المباشرة. الإدارية والتسويقية لا تُحدَّد كذلك.') }}</p>
</div>
@endsection
