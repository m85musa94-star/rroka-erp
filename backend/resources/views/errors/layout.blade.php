<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — إر روكا</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="login"><div class="card" style="text-align:center">
    <h1>@yield('title')</h1>
    <p class="muted">@yield('message')</p>
    <a class="btn" href="{{ url('/') }}">العودة إلى الرئيسية</a>
</div></div>
</body>
</html>
