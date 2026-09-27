@extends('layouts.app')
@section('title', 'عروض الأسعار')
@section('content')
<div class="card">
    <div class="actions" style="justify-content:space-between;margin-bottom:12px">
        <form method="get" class="actions">
            <select name="status" onchange="this.form.submit()" style="width:200px">
                <option value="">كل الحالات</option>
                @foreach(['DRAFT','SENT','APPROVED','REJECTED','EXPIRED','CANCELLED'] as $s)
                    <option value="{{ $s }}" @selected(request('status') === $s)>{{ __("rroka.status.$s") }}</option>
                @endforeach
            </select>
        </form>
        @if(auth()->user()->hasPermission('quotations.manage'))
            <a class="btn" href="{{ route('quotations.create') }}">عرض سعر جديد</a>
        @endif
    </div>
    <div class="table-wrap"><table>
        <tr><th>الرقم</th><th>العميل</th><th>التاريخ</th><th class="num">الصافي قبل الضريبة</th><th>الحالة</th></tr>
        @forelse($quotations as $q)
            <tr>
                <td><a href="{{ route('quotations.show', $q) }}">{{ $q->quotation_no }}</a></td>
                <td>{{ $q->client->business_name }}</td>
                <td class="num">{{ $q->issue_date->format('Y-m-d') }}</td>
                <td class="num">{{ number_format($totals[$q->id] ?? 0, 2) }}</td>
                <td>@include('partials.badge', ['s' => $q->status])</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">لا توجد عروض.</td></tr>
        @endforelse
    </table></div>
    {{ $quotations->links('partials.pager') }}
</div>
@endsection
