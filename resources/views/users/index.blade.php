@extends('layouts.app')
@section('title', 'المستخدمون')
@section('content')
<div class="card">
    <div class="actions" style="justify-content:space-between;margin-bottom:12px"><p class="muted" style="margin:0">كل مستخدم يدخل ببريده وكلمة مروره، ويرى ما تسمح به أدواره فقط.</p><a class="btn" href="{{ route('users.create') }}">+ مستخدم جديد</a></div>
    <div class="table-wrap"><table>
        <tr><th>الاسم</th><th>البريد</th><th>الأدوار</th><th>الحالة</th><th></th></tr>
        @foreach($users as $usr)
            <tr>
                <td>{{ $usr->name }}</td><td dir="ltr" style="text-align:right">{{ $usr->email }}</td>
                <td>{{ $usr->roles->pluck('name_ar')->join('، ') ?: '—' }}</td>
                <td>{!! $usr->is_active ? '<span class="badge b-ACTIVE">نشط</span>' : '<span class="badge b-CANCELLED">موقوف</span>' !!}</td>
                <td><a class="btn ghost sm" href="{{ route('users.edit', $usr) }}">تعديل</a></td>
            </tr>
        @endforeach
    </table></div>
</div>
@endsection
