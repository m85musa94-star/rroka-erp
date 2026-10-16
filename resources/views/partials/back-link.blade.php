{{-- Back to the screen this page was opened from (a report, a list with its filters, a board…), with its dates and options. --}}
@if($back = \App\Support\BackLink::current())
    @php($fromReport = preg_match('#^/(accounting/)?reports(/|\?|$)#', $back) === 1)
    <div class="back-bar"><a class="btn ghost sm" href="{{ $back }}">{{ app()->getLocale() === 'ar' ? '→' : '←' }} {{ $fromReport ? __('رجوع إلى التقرير') : __('رجوع إلى الشاشة السابقة') }}</a>
        <span class="hint">{{ $fromReport ? __('بنفس الفترة والخيارات التي فُتح بها') : __('بنفس الفلاتر والفترة التي فُتحت بها') }}</span></div>
@endif
