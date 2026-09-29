@extends('layouts.app')
@section('title', __('الموظفون'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('الموظفون'), null]],
        'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('hr.manage') ? route('employees.create') : null,
        'paginator' => $employees,
        'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث بالاسم أو الرقم أو المسمى أو الهاتف…'),
    ])
@endsection
@section('content')
@php($colors = ['#8E5486', '#2A5F7E', '#E8833A', '#1e7a4a', '#5AB2F2', '#b3261e'])
@if($alerts && $alerts->isNotEmpty())
    <div class="alert warn">
        <strong>{{ __('وثائق تحتاج تجديدًا') }}</strong>
        <ul>
            @foreach($alerts->take(8) as $d)
                <li><a href="{{ route('employees.show', $d->employee_id) }}">{{ $d->employee->name }}</a> — {{ __("rroka.doc_type.$d->doc_type") }}:
                    {{ $d->state() === 'expired' ? __('انتهت في :date', ['date' => $d->expiry_date->format('Y-m-d')]) : __('تنتهي في :date', ['date' => $d->expiry_date->format('Y-m-d')]) }}</li>
            @endforeach
        </ul>
        @if($alerts->count() > 8)<a href="{{ route('employees.index', ['f' => ['docs']]) }}">{{ __('عرض الكل') }}</a>@endif
    </div>
@endif
@php($rows = $employees ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state">
        <strong>{{ $lv->isFiltered() ? __('لا نتائج مطابقة') : __('لا يوجد موظفون بعد') }}</strong>
        {{ $lv->isFiltered() ? __('غيّر البحث أو أزل الفلاتر.') : __('أضف الموظفين من زر «جديد».') }}
    </div>
@elseif($lv->view === 'kanban')
    @foreach($groups ?? ['' => $employees] as $title => $items)
        @if($groups)<h3 class="studio-group">{{ $title }} <span class="grp-count">({{ $items->count() }})</span></h3>@endif
        <div class="kb-cards" style="margin-bottom:16px">
            @foreach($items as $e)
                <a class="kb-card" href="{{ route('employees.show', $e) }}">
                    <span class="kb-avatar" style="background: {{ $colors[$e->id % count($colors)] }}">{{ mb_substr($e->name, 0, 1) }}</span>
                    <span style="min-width:0">
                        <span class="kb-title" style="display:block">{{ $e->name }}</span>
                        <span class="kb-meta"><span>{{ $e->job?->name ?? $e->trade ?? '—' }}</span></span>
                        <span class="kb-meta"><span>{{ $e->department?->name }}</span>@unless($e->is_active)<span class="badge b-CANCELLED">{{ __('انتهت خدمته') }}</span>@endunless</span>
                    </span>
                </a>
            @endforeach
        </div>
    @endforeach
@else
    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>{{ __('الرقم') }}</th><th>{{ __('الاسم') }}</th><th>{{ __('المسمى الوظيفي') }}</th><th>{{ __('القسم') }}</th><th>{{ __('المدير المباشر') }}</th><th>{{ __('هاتف العمل') }}</th><th>{{ __('الحالة') }}</th></tr>
            @foreach($groups ?? ['' => $employees] as $title => $items)
                @if($groups)<tr class="grp"><td colspan="7">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td></tr>@endif
                @foreach($items as $e)
                    <tr class="row-link" onclick="location='{{ route('employees.show', $e) }}'">
                        <td class="num">{{ $e->employee_no }}</td>
                        <td><a href="{{ route('employees.show', $e) }}">{{ $e->name }}</a></td>
                        <td>{{ $e->job?->name ?? $e->trade ?? '—' }}</td>
                        <td>{{ $e->department?->name ?? '—' }}</td>
                        <td>{{ $e->manager?->name ?? '—' }}</td>
                        <td class="num">{{ $e->work_phone ?? '—' }}</td>
                        <td>@if($e->is_active)<span class="badge b-ACTIVE">{{ __('على رأس العمل') }}</span>@else<span class="badge b-CANCELLED">{{ __('انتهت خدمته') }}</span>@endif</td>
                    </tr>
                @endforeach
            @endforeach
        </table></div>
    </div>
@endif
@endsection
