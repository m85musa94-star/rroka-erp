<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}"{!! \App\Support\Theme::attr() !!}>
<head>
    <meta charset="utf-8"><meta name="color-scheme" content="light dark">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') {{ __('— إر روكا') }}</title>
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('css/app.css') }}">
</head>
<body>
@php($u = auth()->user())
@php($app = \App\Support\AppMenu::current())
<header class="topbar">
    <a href="{{ route('dashboard') }}" class="tb-home" title="{{ __('التطبيقات') }}" aria-label="{{ __('التطبيقات') }}">
        @include('partials.app-icon', ['key' => 'home', 'size' => 22])
    </a>
    @if($app)
        <a href="{{ route($app['route']) }}" class="tb-app">
            @include('partials.app-icon', ['key' => $app['key'], 'size' => 26])
            <span>{{ $app['label'] }}</span>
        </a>
        <nav class="tb-menu" aria-label="{{ __('أقسام التطبيق') }}">
            @foreach(\App\Support\AppMenu::menu($app, $u) as [$label, $url, $on])
                <a href="{{ $url }}" @class(['on' => $on])>{{ $label }}</a>
            @endforeach
        </nav>
    @else
        <span class="tb-app"><span>{{ __('إر روكا للأثاث') }}</span></span>
    @endif
    <div class="tb-spacer"></div>
    <div class="tb-user">
        @include('partials.theme-toggle')
        @include('partials.lang-toggle')
        <span class="tb-avatar">{{ mb_substr($u->name, 0, 1) }}</span>
        <span class="tb-name">{{ $u->name }}</span>
        <form method="post" action="{{ route('logout') }}" class="inline">@csrf
            <button class="btn ghost sm tb-logout">{{ __('خروج') }}</button>
        </form>
    </div>
</header>
@if($app)
    <nav class="tb-menu-m" aria-label="{{ __('أقسام التطبيق') }}">
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
        @if(session('warn'))
            <div class="alert warn">{{ session('warn') }}</div>
        @endif
        @if($errors->any())
            <div class="alert bad"><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif

        @include('partials.back-link')
        @yield('content')
        <p class="hint app-version">{{ __('الإصدار') }} <bdi dir="ltr">{{ \App\Support\Release::VERSION }}</bdi></p>
    </main>
</div>
<script>
// One confirmation mechanism for every form: <form data-confirm="...">.
document.addEventListener('submit', e => {
    const msg = e.target.dataset && e.target.dataset.confirm;
    if (msg && !confirm(msg)) { e.preventDefault(); }
});
// The way back: a link inside the page content (not the menus, filters or pager) opens its
// target with ?back=<this page> (its filters and dates included), so that page shows "Back".
(() => {
    const main = document.querySelector('main.main');
    if (!main) return;
    const here = location.pathname + location.search;
    const skip = a => a.closest('.cp, .control-panel, .pager, .back-bar, nav, form') || a.target === '_blank' || a.hasAttribute('download');
    const withBack = href => {
        let u;
        try { u = new URL(href, location.href); } catch (e) { return null; }
        if (u.origin !== location.origin || u.pathname === location.pathname || u.searchParams.has('back')) return null;
        if (u.searchParams.has('export') || u.searchParams.has('print') || u.pathname.includes('/file/')) return null;
        u.searchParams.set('back', here);
        return u.pathname + u.search + u.hash;
    };
    main.querySelectorAll('a[href]').forEach(a => {
        if (skip(a)) return;
        const h = withBack(a.getAttribute('href'));
        if (h) a.setAttribute('href', h);
    });
    // Clickable rows go where their first link goes (with the way back).
    main.querySelectorAll('tr.row-link').forEach(tr => {
        const a = tr.querySelector('a[href]');
        if (!a) return;
        tr.onclick = e => { if (!e.target.closest('a, button, input, select, form')) location = a.getAttribute('href'); };
    });
})();
</script>
@stack('scripts')
</body>
</html>
