@extends('layouts.app')
@section('title', __('المستخدمون'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الإعدادات'), null], [__('المستخدمون'), null]]])
@endsection
@section('content')
<div class="card">
    <div class="actions" style="justify-content:space-between;margin-bottom:12px"><p class="muted" style="margin:0">{{ __('كل مستخدم يدخل ببريده وكلمة مروره، ويرى ما تسمح به أدواره فقط.') }}</p><a class="btn" href="{{ route('users.create') }}">{{ __('+ مستخدم جديد') }}</a></div>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الاسم') }}</th><th>{{ __('البريد') }}</th><th>{{ __('الأدوار') }}</th><th>{{ __('الحالة') }}</th><th></th></tr>
        @foreach($users as $usr)
            <tr>
                <td>{{ $usr->name }}</td><td dir="ltr" class="ltr-in">{{ $usr->email }}</td>
                <td>{{ $usr->roles->pluck('name_ar')->map(fn ($n) => __($n))->join(__('، ')) ?: '—' }}</td>
                <td>{!! $usr->is_active ? __('<span class="badge b-ACTIVE">نشط</span>') : __('<span class="badge b-CANCELLED">موقوف</span>') !!}</td>
                <td><a class="btn ghost sm" href="{{ route('users.edit', $usr) }}">{{ __('تعديل') }}</a></td>
            </tr>
        @endforeach
    </table></div>
</div>
@endsection
