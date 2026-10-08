{{-- Back to the report this page was opened from, with the report's original dates and options. --}}
@if($back = \App\Support\BackLink::current())
    <div class="back-bar"><a class="btn ghost sm" href="{{ $back }}">{{ app()->getLocale() === 'ar' ? '→' : '←' }} {{ __('رجوع إلى التقرير') }}</a>
        <span class="hint">{{ __('بنفس الفترة والخيارات التي فُتح بها') }}</span></div>
@endif
