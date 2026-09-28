@extends('layouts.app')
@section('title', 'المشاريع')
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [['المشاريع', null]],
        'lv' => $lv,
        'newUrl' => auth()->user()->hasPermission('projects.manage') ? route('projects.create') : null,
        'paginator' => $projects,
        'total' => $groups?->flatten()->count() ?? $columns?->flatten()->count(),
        'placeholder' => 'بحث بعنوان المشروع أو رقمه أو العميل…',
    ])
@endsection
@section('content')
@if($columns)
    <div class="kanban">
        @foreach($columns as $st => $items)
            <div class="kb-col">
                <div class="kb-head">
                    <span>@include('partials.badge', ['s' => $st]) <span class="muted" style="font-weight:400">{{ $items->count() }}</span></span>
                    <span class="kb-sum">{{ number_format($items->sum('contract_value'), 2) }}</span>
                </div>
                @forelse($items as $p)
                    @php($late = $p->target_date && $p->target_date->lt(today()) && ! in_array($p->status, ['COMPLETED', 'CANCELLED']))
                    <a class="kb-card" href="{{ route('projects.show', $p) }}">
                        <div class="kb-title">{{ $p->title }}</div>
                        <div class="kb-meta"><span>{{ $p->client->business_name }}</span><span dir="ltr">{{ number_format($p->contract_value, 2) }}</span></div>
                        <div class="kb-meta"><span>{{ $p->project_no }}</span>
                            <span @class(['na' => $late])>{{ $p->target_date ? 'التسليم '.$p->target_date->format('Y-m-d') : '' }}</span></div>
                    </a>
                @empty
                    <div class="kb-empty">لا مشاريع</div>
                @endforelse
            </div>
        @endforeach
    </div>
    <p class="hint">المشروع يُنشأ من عرض سعر معتمد فقط.</p>
@else
    @php($rows = $projects ?? $groups->flatten())
    @if($rows->isEmpty())
        <div class="card empty-state">
            <strong>{{ $lv->isFiltered() ? 'لا نتائج مطابقة' : 'لا توجد مشاريع بعد' }}</strong>
            {{ $lv->isFiltered() ? 'غيّر البحث أو أزل الفلاتر.' : 'المشروع يُنشأ من عرض سعر معتمد.' }}
        </div>
    @else
    <div class="card" style="padding:0">
        <div class="table-wrap"><table>
            <tr><th>الرقم</th><th>العنوان</th><th>العميل</th><th class="num">قيمة العقد</th><th>البدء</th><th>التسليم المستهدف</th><th>المرحلة</th></tr>
            @foreach($groups ?? ['' => $projects] as $title => $items)
                @if($groups)<tr class="grp"><td colspan="3">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td><td class="num">{{ number_format($items->sum('contract_value'), 2) }}</td><td colspan="3"></td></tr>@endif
                @foreach($items as $p)
                    @php($late = $p->target_date && $p->target_date->lt(today()) && ! in_array($p->status, ['COMPLETED', 'CANCELLED']))
                    <tr class="row-link" onclick="location='{{ route('projects.show', $p) }}'">
                        <td><a href="{{ route('projects.show', $p) }}">{{ $p->project_no }}</a></td>
                        <td>{{ $p->title }}</td>
                        <td>{{ $p->client->business_name }}</td>
                        <td class="num">{{ number_format($p->contract_value, 2) }}</td>
                        <td class="num">{{ $p->start_date->format('Y-m-d') }}</td>
                        <td class="num"><span @class(['na' => $late])>{{ $p->target_date?->format('Y-m-d') ?? '—' }}</span></td>
                        <td>@include('partials.badge', ['s' => $p->status])</td>
                    </tr>
                @endforeach
            @endforeach
        </table></div>
    </div>
    @endif
@endif
@endsection
