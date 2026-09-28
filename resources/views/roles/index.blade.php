@extends('layouts.app')
@section('title', 'الأدوار والصلاحيات')
@section('content')
<div class="card">
    <div class="actions" style="justify-content:space-between;margin-bottom:12px">
        <p class="muted" style="margin:0">الدور = مجموعة صلاحيات. أنشئ الأدوار أولًا، ثم أسندها إلى المستخدمين من صفحة المستخدمين.</p>
        <a class="btn" href="{{ route('roles.create') }}">+ دور جديد</a>
    </div>
    <div class="table-wrap"><table>
        <tr><th>الدور</th><th>الوصف</th><th class="num">الصلاحيات</th><th class="num">المستخدمون</th><th></th></tr>
        @foreach($roles as $r)
            <tr>
                <td><strong>{{ $r->name_ar }}</strong></td>
                <td>{{ $r->description ?? '—' }}</td>
                <td class="num">{{ $r->permissions_count }}</td>
                <td class="num">{{ $r->users_count }}</td>
                <td class="actions" style="justify-content:flex-end">
                    <a class="btn ghost sm" href="{{ route('roles.edit', $r) }}">تعديل</a>
                    @if($r->code !== 'system_admin' && $r->users_count === 0)
                        <form method="post" action="{{ route('roles.destroy', $r) }}" class="inline" onsubmit="return confirm('حذف الدور «{{ $r->name_ar }}»؟')">@csrf @method('delete')
                            <button class="btn ghost sm">حذف</button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
    </table></div>
</div>
@endsection
