@extends('layouts.app')
@section('title', 'العملاء')
@section('content')
<div class="card">
    <div class="actions" style="justify-content:space-between;margin-bottom:12px">
        <form method="get" class="actions">
            <input name="q" value="{{ request('q') }}" placeholder="بحث بالاسم أو الجوال أو الرقم" style="width:260px">
            <button class="btn ghost sm">بحث</button>
        </form>
        @if(auth()->user()->hasPermission('clients.manage'))
            <a class="btn" href="{{ route('clients.create') }}">عميل جديد</a>
        @endif
    </div>
    <div class="table-wrap"><table>
        <tr><th>الرقم</th><th>الاسم</th><th>النوع</th><th>الجوال</th><th>المدينة</th><th>دفترة</th></tr>
        @forelse($clients as $c)
            <tr>
                <td class="num">{{ $c->client_no }}</td>
                <td><a href="{{ route('clients.show', $c) }}">{{ $c->business_name }}</a></td>
                <td>{{ __("rroka.client_type.$c->client_type") }}</td>
                <td class="num">{{ $c->phone ?? '—' }}</td>
                <td>{{ $c->city ?? '—' }}</td>
                <td>{!! $c->daftra_client_id ? '<span class="badge b-SUCCESS">مرتبط</span>' : '<span class="muted">—</span>' !!}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">لا يوجد عملاء بعد.</td></tr>
        @endforelse
    </table></div>
    {{ $clients->links('partials.pager') }}
</div>
@endsection
