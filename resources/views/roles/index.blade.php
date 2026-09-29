@extends('layouts.app')
@section('title', __('الأدوار والصلاحيات'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الإعدادات'), null], [__('الأدوار والصلاحيات'), null]]])
@endsection
@section('content')
<div class="card">
    <div class="actions" style="justify-content:space-between;margin-bottom:12px">
        <p class="muted" style="margin:0">{{ __('الدور = مجموعة صلاحيات. أنشئ الأدوار أولًا، ثم أسندها إلى المستخدمين من صفحة المستخدمين.') }}</p>
        <a class="btn" href="{{ route('roles.create') }}">{{ __('+ دور جديد') }}</a>
    </div>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الدور') }}</th><th>{{ __('الوصف') }}</th><th class="num">{{ __('الصلاحيات') }}</th><th class="num">{{ __('المستخدمون') }}</th><th></th></tr>
        @foreach($roles as $r)
            <tr>
                <td><strong>{{ __($r->name_ar) }}</strong></td>
                <td>{{ $r->description ? __($r->description) : '—' }}</td>
                <td class="num">{{ $r->permissions_count }}</td>
                <td class="num">{{ $r->users_count }}</td>
                <td class="actions" style="justify-content:flex-end">
                    <a class="btn ghost sm" href="{{ route('roles.edit', $r) }}">{{ __('تعديل') }}</a>
                    @if($r->code !== 'system_admin' && $r->users_count === 0)
                        <form method="post" action="{{ route('roles.destroy', $r) }}" class="inline" data-confirm="{{ __('حذف الدور «:name»؟', ['name' => __($r->name_ar)]) }}">@csrf @method('delete')
                            <button class="btn ghost sm">{{ __('حذف') }}</button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
    </table></div>
</div>
@endsection
