{{-- Colour theme: automatic (device), light, dark. --}}
@php($mode = \App\Support\Theme::current())
@php($icons = [
    'system' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>',
    'light' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
    'dark' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>',
])
@php($labels = ['system' => __('تلقائي (حسب الجهاز)'), 'light' => __('فاتح'), 'dark' => __('داكن')])
<details class="theme-menu">
    <summary class="btn ghost sm" title="{{ __('مظهر الألوان') }}" aria-label="{{ __('مظهر الألوان') }}">{!! $icons[$mode] !!}</summary>
    <form method="post" action="{{ route('theme') }}" class="theme-list">@csrf
        @foreach(\App\Support\Theme::MODES as $m)
            <button type="submit" name="mode" value="{{ $m }}" aria-pressed="{{ $m === $mode ? 'true' : 'false' }}">{!! $icons[$m] !!} {{ $labels[$m] }}</button>
        @endforeach
    </form>
</details>
