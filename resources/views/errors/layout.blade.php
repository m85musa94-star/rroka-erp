<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}"{!! \App\Support\Theme::attr() !!}>
<head>
    <meta charset="utf-8"><meta name="color-scheme" content="light dark"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') {{ __('— إر روكا') }}</title>
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('css/app.css') }}">
</head>
<body>
<div class="login"><div class="card" style="text-align:center">
    <h1>@yield('title')</h1>
    <p class="muted">@yield('message')</p>
    <a class="btn" href="{{ url('/') }}">{{ __('العودة إلى الرئيسية') }}</a>
</div></div>
</body>
</html>
