{{-- Odoo-style stage bar. Params: $path (ordered status codes to draw), $current, $bad (terminal "bad" statuses) --}}
@php($bad = $bad ?? [])
@php($curIdx = array_search($current, $path, true))
<div class="statusbar" aria-label="المرحلة">
    @foreach($path as $i => $st)
        <span @class(['done' => $i < $curIdx, 'cur' => $i === $curIdx, 'bad' => $i === $curIdx && in_array($st, $bad, true)])>{{ __("rroka.status.$st") }}</span>
    @endforeach
</div>
