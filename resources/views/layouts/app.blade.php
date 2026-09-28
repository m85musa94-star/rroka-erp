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
@php($app = \App\Support\AppMenu::current())
<header class="topbar">
    <a href="{{ route('dashboard') }}" class="tb-home" title="التطبيقات" aria-label="التطبيقات">
        @include('partials.app-icon', ['key' => 'home', 'size' => 22])
    </a>
    @if($app)
        <a href="{{ route($app['route']) }}" class="tb-app">
            @include('partials.app-icon', ['key' => $app['key'], 'size' => 26])
            <span>{{ $app['label'] }}</span>
        </a>
        <nav class="tb-menu" aria-label="أقسام التطبيق">
            @foreach(\App\Support\AppMenu::menu($app, $u) as [$label, $url, $on])
                <a href="{{ $url }}" @class(['on' => $on])>{{ $label }}</a>
            @endforeach
        </nav>
    @else
        <span class="tb-app"><span>إر روكا للأثاث</span></span>
    @endif
    <div class="tb-spacer"></div>
    <div class="tb-user">
        <span class="tb-avatar">{{ mb_substr($u->name, 0, 1) }}</span>
        <span class="tb-name">{{ $u->name }}</span>
        <form method="post" action="{{ route('logout') }}" class="inline">@csrf
            <button class="btn ghost sm tb-logout">خروج</button>
        </form>
    </div>
</header>
@if($app)
    <nav class="tb-menu-m" aria-label="أقسام التطبيق">
        @foreach(\App\Support\AppMenu::menu($app, $u) as [$label, $url, $on])
            <a href="{{ $url }}" @class(['on' => $on])>{{ $label }}</a>
        @endforeach
    </nav>
@endif
<div class="shell">
    <main class="main">
        @hasSection('hide_title')
        @elseif(! View::hasSection('cp'))
            <div class="top"><h1>@yield('title')</h1></div>
        @endif
        @yield('cp')

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
