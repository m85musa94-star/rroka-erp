<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') — إر روكا</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
@php($u = auth()->user())
<div class="shell">
    <aside class="side">
        <div class="logo">إر روكا للأثاث<small>نظام التشغيل والتكلفة</small></div>
        <nav>
            <a href="{{ route('dashboard') }}" @class(['on' => request()->routeIs('dashboard')])>الرئيسية</a>
            @if($u->hasPermission('clients.view'))
                <a href="{{ route('clients.index') }}" @class(['on' => request()->routeIs('clients.*')])>العملاء</a>
            @endif
            @if($u->hasPermission('quotations.view'))
                <a href="{{ route('quotations.index') }}" @class(['on' => request()->routeIs('quotations.*')])>عروض الأسعار</a>
            @endif
            @if($u->hasPermission('projects.view'))
                <a href="{{ route('projects.index') }}" @class(['on' => request()->routeIs('projects.*')])>المشاريع</a>
            @endif
            @if($u->hasPermission('settings.cost_rates') || $u->hasPermission('users.manage'))
                <div class="sep">الإعدادات</div>
            @endif
            @if($u->hasPermission('settings.cost_rates'))
                <a href="{{ route('rates.index') }}" @class(['on' => request()->routeIs('rates.*')])>معدلات التكلفة</a>
            @endif
            @if($u->hasPermission('users.manage'))
                <a href="{{ route('users.index') }}" @class(['on' => request()->routeIs('users.*')])>المستخدمون</a>
                <a href="{{ route('roles.index') }}" @class(['on' => request()->routeIs('roles.*')])>الأدوار والصلاحيات</a>
            @endif
        </nav>
    </aside>
    <main class="main">
        <div class="top">
            <h1>@yield('title')</h1>
            <div class="who">
                <span>{{ $u->name }}</span>
                <form method="post" action="{{ route('logout') }}" class="inline">@csrf
                    <button class="btn ghost sm">خروج</button>
                </form>
            </div>
        </div>

        @if(session('ok'))
            <div class="alert ok">{{ session('ok') }}</div>
        @endif
        @if($errors->any())
            <div class="alert bad"><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif

        @yield('content')
    </main>
</div>
@stack('scripts')
</body>
</html>
