@php($otherLocale = app()->getLocale() === 'ar' ? 'en' : 'ar')
<form method="post" action="{{ route('locale', $otherLocale) }}" class="inline">@csrf
    <button class="btn ghost sm lang-toggle" lang="{{ $otherLocale }}" title="{{ $otherLocale === 'en' ? 'Switch to English' : 'التبديل إلى العربية' }}">{{ $otherLocale === 'en' ? 'English' : 'العربية' }}</button>
</form>
