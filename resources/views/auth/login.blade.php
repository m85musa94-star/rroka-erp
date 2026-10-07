<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}"{!! \App\Support\Theme::attr() !!}>
<head>
    <meta charset="utf-8"><meta name="color-scheme" content="light dark">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('تسجيل الدخول — إر روكا') }}</title>
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('css/app.css') }}">
</head>
<body>
<div class="login">
    <div class="card">
        <div style="display:flex;justify-content:flex-end;gap:6px;margin-bottom:6px">@include('partials.theme-toggle')@include('partials.lang-toggle')</div>
        <h1>{{ __('إر روكا للأثاث') }}</h1>
        <p class="muted" style="margin-top:0">{{ __('نظام التشغيل والتكلفة') }}</p>
        @if($errors->any())
            <div class="alert bad">{{ $errors->first() }}</div>
        @endif
        <form method="post" action="{{ route('login') }}">
            @csrf
            <div class="field">
                <label for="email">{{ __('البريد الإلكتروني') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus dir="ltr" autocapitalize="none" autocorrect="off" spellcheck="false" autocomplete="username">
            </div>
            <div class="field">
                <label for="password">{{ __('كلمة المرور') }}</label>
                <input id="password" name="password" type="password" required dir="ltr" autocomplete="current-password">
            </div>
            <div class="field">
                <label style="display:flex;gap:6px;align-items:center;color:var(--ink)">
                    <input type="checkbox" name="remember" value="1" style="width:auto"> {{ __('تذكّرني على هذا الجهاز') }}
                </label>
            </div>
            <button class="btn" style="width:100%">{{ __('دخول') }}</button>
        </form>
    </div>
</div>
</body>
</html>
