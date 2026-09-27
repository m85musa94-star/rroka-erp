@extends('layouts.app')
@section('title', 'المشاريع')
@section('content')
<div class="card">
    @if(auth()->user()->hasPermission('projects.manage'))
        <div class="actions" style="margin-bottom:12px"><a class="btn" href="{{ route('projects.create') }}">مشروع جديد</a></div>
    @endif
    <div class="table-wrap"><table>
        <tr><th>الرقم</th><th>العنوان</th><th>العميل</th><th class="num">قيمة العقد</th><th>البدء</th><th>التسليم المستهدف</th><th>الحالة</th></tr>
        @forelse($projects as $p)
            <tr>
                <td><a href="{{ route('projects.show', $p) }}">{{ $p->project_no }}</a></td>
                <td>{{ $p->title }}</td>
                <td>{{ $p->client->business_name }}</td>
                <td class="num">{{ number_format($p->contract_value, 2) }}</td>
                <td class="num">{{ $p->start_date->format('Y-m-d') }}</td>
                <td class="num">{{ $p->target_date?->format('Y-m-d') ?? '—' }}</td>
                <td>@include('partials.badge', ['s' => $p->status])</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">لا توجد مشاريع. يُنشأ المشروع من عرض سعر معتمد.</td></tr>
        @endforelse
    </table></div>
    {{ $projects->links('partials.pager') }}
</div>
@endsection
