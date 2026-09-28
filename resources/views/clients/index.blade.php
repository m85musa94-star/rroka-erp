@extends('layouts.app')
@section('title', 'العملاء')
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [['العملاء', null]],
        'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('clients.manage') ? route('clients.create') : null,
        'newLabel' => 'جديد',
        'paginator' => $clients,
        'total' => $groups?->flatten()->count(),
        'placeholder' => 'بحث بالاسم أو الجوال أو المدينة أو الرقم…',
    ])
@endsection
@section('content')
@php($colors = ['#8E5486', '#2A5F7E', '#E8833A', '#1e7a4a', '#5AB2F2', '#b3261e'])
@php($rows = $clients ?? $groups->flatten())
@if($rows->isEmpty())
    <div class="card empty-state">
        <strong>{{ $lv->isFiltered() ? 'لا نتائج مطابقة' : 'لا يوجد عملاء بعد' }}</strong>
        {{ $lv->isFiltered() ? 'غيّر البحث أو أزل الفلاتر.' : 'ابدأ بإضافة أول عميل من زر «جديد».' }}
    </div>
@elseif($lv->view === 'kanban')
    <div class="kb-cards">
        @foreach($rows as $c)
            <a class="kb-card" href="{{ route('clients.show', $c) }}">
                <span class="kb-avatar" style="background: {{ $colors[$c->id % count($colors)] }}">{{ mb_substr($c->business_name, 0, 1) }}</span>
                <span style="min-width:0">
                    <span class="kb-title" style="display:block">{{ $c->business_name }}</span>
                    <span class="kb-meta"><span dir="ltr">{{ $c->phone ?? '—' }}</span><span>{{ $c->city ?? '' }}</span></span>
                    <span class="kb-meta"><span>{{ __("rroka.client_type.$c->client_type") }}</span>@if($c->daftra_client_id)<span class="badge b-SUCCESS">دفترة</span>@endif</span>
                </span>
            </a>
        @endforeach
    </div>
@else
    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>الرقم</th><th>الاسم</th><th>النوع</th><th>الجوال</th><th>المدينة</th><th>دفترة</th></tr>
            @foreach($groups ?? ['' => $clients] as $title => $items)
                @if($groups)<tr class="grp"><td colspan="6">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td></tr>@endif
                @foreach($items as $c)
                    <tr class="row-link" onclick="location='{{ route('clients.show', $c) }}'">
                        <td class="num">{{ $c->client_no }}</td>
                        <td><a href="{{ route('clients.show', $c) }}">{{ $c->business_name }}</a></td>
                        <td>{{ __("rroka.client_type.$c->client_type") }}</td>
                        <td class="num">{{ $c->phone ?? '—' }}</td>
                        <td>{{ $c->city ?? '—' }}</td>
                        <td>{!! $c->daftra_client_id ? '<span class="badge b-SUCCESS">مرتبط</span>' : '<span class="muted">—</span>' !!}</td>
                    </tr>
                @endforeach
            @endforeach
        </table></div>
    </div>
@endif
@endsection
