<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تسجيل الدخول — إر روكا</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="login">
    <div class="card">
        <h1>إر روكا للأثاث</h1>
        <p class="muted" style="margin-top:0">نظام التشغيل والتكلفة</p>
        @if($errors->any())
            <div class="alert bad">{{ $errors->first() }}</div>
        @endif
        <form method="post" action="{{ route('login') }}">
            @csrf
            <div class="field">
                <label for="email">البريد الإلكتروني</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus dir="ltr">
            </div>
            <div class="field">
                <label for="password">كلمة المرور</label>
                <input id="password" name="password" type="password" required dir="ltr">
            </div>
            <div class="field">
                <label style="display:flex;gap:6px;align-items:center;color:var(--ink)">
                    <input type="checkbox" name="remember" value="1" style="width:auto"> تذكّرني على هذا الجهاز
                </label>
            </div>
            <button class="btn" style="width:100%">دخول</button>
        </form>
    </div>
</div>
</body>
</html>
