{{-- Source and approval trail of a versioned cost record. --}}
<dl class="kv" style="margin-top:10px">
    <dt>{{ __('ساري من') }}</dt><dd>{{ $r->effective_from->format('Y-m-d') }}</dd>
    <dt>{{ __('المصدر') }}</dt><dd>{{ $r->source }}</dd>
    @if($r->notes)<dt>{{ __('ملاحظات') }}</dt><dd>{{ $r->notes }}</dd>@endif
    <dt>{{ __('أدخله') }}</dt><dd>{{ $names[$r->created_by] ?? '—' }}</dd>
    @if($r->approved_at)<dt>{{ __('اعتمده') }}</dt><dd>{{ $names[$r->approved_by] ?? '—' }} — {{ $r->approved_at->format('Y-m-d H:i') }}</dd>@endif
</dl>
