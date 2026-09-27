@extends('layouts.app')
@section('title', 'الأدوار والصلاحيات')
@section('content')
<div class="card">
    <div class="actions" style="margin-bottom:12px"><a class="btn" href="{{ route('roles.create') }}">دور جديد</a></div>
    <div class="table-wrap"><table>
        <tr><th>الدور</th><th>الرمز</th><th>عدد الصلاحيات</th><th>الوصف</th><th></th></tr>
        @foreach($roles as $r)
            <tr><td>{{ $r->name_ar }}</td><td dir="ltr" style="text-align:right">{{ $r->code }}</td><td>{{ $r->permissions_count }}</td>
                <td>{{ $r->description ?? '—' }}</td><td><a class="btn ghost sm" href="{{ route('roles.edit', $r) }}">تعديل</a></td></tr>
        @endforeach
    </table></div>
</div>
@endsection
