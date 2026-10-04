{{-- Status, version and approval actions of a versioned cost record.
     Params: $type (CostingController::TYPES key), $r (record), $edit (url|null), $names (user names by id). --}}
@php($u = auth()->user())
<div class="rec-bar">
    <div class="actions">
        @if($r->isDraft())
            @if($edit && $u->hasPermission('settings.cost_rates'))<a class="btn ghost sm" href="{{ $edit }}">{{ __('تعديل') }}</a>@endif
            @if($u->hasPermission('cost_rates.approve'))
                <form method="post" action="{{ route('costing.approve', [$type, $r->id]) }}" class="inline" data-confirm="{{ __('اعتماد السجل؟ يصبح نهائيًا ولا يُعدَّل، وأي تغيير لاحق يكون بإصدار جديد.') }}">@csrf<button class="btn ok sm">{{ __('اعتماد') }}</button></form>
            @endif
            @if($u->hasPermission('settings.cost_rates'))
                <form method="post" action="{{ route('costing.cancel', [$type, $r->id]) }}" class="inline" data-confirm="{{ __('إلغاء المسودة؟') }}">@csrf<button class="btn ghost sm">{{ __('إلغاء') }}</button></form>
            @endif
        @endif
        <span class="badge">{{ __('الإصدار :v', ['v' => $r->version]) }}</span>
        @if($r->estimated)<span class="badge b-ON_HOLD">{{ __('تقديري') }}</span>@endif
        @if($r->selfApproved())<span class="badge b-ON_HOLD">{{ __('اعتماد ذاتي') }}</span>@endif
    </div>
    @include('partials.statusbar', ['path' => $r->status === 'CANCELLED' ? ['DRAFT', 'CANCELLED'] : ['DRAFT', 'APPROVED'], 'current' => $r->status, 'bad' => ['CANCELLED']])
</div>
