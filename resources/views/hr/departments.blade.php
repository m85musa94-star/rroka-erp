@extends('layouts.app')
@section('title', __('الأقسام والمسميات الوظيفية'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الموظفون'), route('employees.index')], [__('الأقسام والمسميات الوظيفية'), null]]])
@endsection
@section('content')
@php($manage = auth()->user()->hasPermission('hr.manage'))
<div class="grid g2">
<div class="card">
    <h2>{{ __('الأقسام') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('القسم') }}</th><th>{{ __('يتبع') }}</th><th>{{ __('المدير') }}</th><th class="num">{{ __('الموظفون') }}</th></tr>
        @forelse($departments as $d)
            <tr>
                <td>@if($manage)<details><summary>{{ $d->name }}</summary>
                    <form method="post" action="{{ route('departments.update', $d) }}" class="inline-form" style="margin-top:6px">@csrf @method('put')
                        <input name="name" value="{{ $d->name }}" required>
                        <select name="parent_id"><option value="">{{ __('— لا يتبع —') }}</option>@foreach($departments->where('id', '<>', $d->id) as $p)<option value="{{ $p->id }}" @selected($d->parent_id === $p->id)>{{ $p->name }}</option>@endforeach</select>
                        <select name="manager_id"><option value="">{{ __('— بلا مدير —') }}</option>@foreach($employees as $emp)<option value="{{ $emp->id }}" @selected($d->manager_id === $emp->id)>{{ $emp->name }}</option>@endforeach</select>
                        <label class="perm-item"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" style="width:auto" @checked($d->is_active)> {{ __('نشط') }}</label>
                        <button class="btn sm">{{ __('حفظ') }}</button>
                    </form></details>@else{{ $d->name }}@endif</td>
                <td>{{ $d->parent?->name ?? '—' }}</td>
                <td>{{ $d->manager?->name ?? '—' }}</td>
                <td class="num"><a href="{{ route('employees.index', ['department_id' => $d->id]) }}">{{ $d->employees_count }}</a></td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">{{ __('لا أقسام بعد.') }}</td></tr>
        @endforelse
    </table></div>
    @if($manage)
        <form method="post" action="{{ route('departments.store') }}" class="inline-form" style="margin-top:12px">@csrf
            <input name="name" required placeholder="{{ __('اسم القسم (الورشة، الدهان، التركيب، الإدارة…)') }}" style="min-width:240px">
            <select name="parent_id"><option value="">{{ __('— لا يتبع —') }}</option>@foreach($departments as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
            <button class="btn sm">{{ __('إضافة قسم') }}</button>
        </form>
    @endif
</div>
<div class="card">
    <h2>{{ __('المسميات الوظيفية') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('المسمى') }}</th><th>{{ __('القسم') }}</th><th class="num">{{ __('الموظفون') }}</th></tr>
        @forelse($jobs as $j)
            <tr>
                <td>@if($manage)<details><summary>{{ $j->name }}</summary>
                    <form method="post" action="{{ route('jobs.update', $j) }}" class="inline-form" style="margin-top:6px">@csrf @method('put')
                        <input name="name" value="{{ $j->name }}" required>
                        <select name="department_id"><option value="">{{ __('— بلا —') }}</option>@foreach($departments as $d)<option value="{{ $d->id }}" @selected($j->department_id === $d->id)>{{ $d->name }}</option>@endforeach</select>
                        <input name="description" value="{{ $j->description }}" placeholder="{{ __('الوصف') }}">
                        <label class="perm-item"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" style="width:auto" @checked($j->is_active)> {{ __('نشط') }}</label>
                        <button class="btn sm">{{ __('حفظ') }}</button>
                    </form></details>@else{{ $j->name }}@endif</td>
                <td>{{ $j->department?->name ?? '—' }}</td>
                <td class="num">{{ $j->employees_count }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">{{ __('لا مسميات بعد.') }}</td></tr>
        @endforelse
    </table></div>
    @if($manage)
        <form method="post" action="{{ route('jobs.store') }}" class="inline-form" style="margin-top:12px">@csrf
            <input name="name" required placeholder="{{ __('المسمى (نجار، مشرف ورشة، محاسب…)') }}" style="min-width:220px">
            <select name="department_id"><option value="">{{ __('— القسم —') }}</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
            <button class="btn sm">{{ __('إضافة مسمى') }}</button>
        </form>
    @endif
</div>
</div>
@endsection
