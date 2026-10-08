{{-- Shared by the screen and the print view. $r = built report, $o = options, $links = bool --}}
@php
    $money = fn ($v) => $v === null ? '' : ((float) $v < -0.004 ? '('.number_format(abs((float) $v), 2).')' : number_format((float) $v, 2));
    $pct = fn ($v) => $v === null ? '' : number_format((float) $v, 1).'%';
    $hasGroups = collect($r['columns'])->contains(fn ($c) => isset($c['group']));
@endphp
<table class="fin-report">
    <thead>
        @if($hasGroups)
            <tr><th rowspan="2"></th>
                @foreach(collect($r['columns'])->groupBy('group') as $g => $cs)<th colspan="{{ $cs->count() }}" class="num grp-head">{{ $g }}</th>@endforeach</tr>
            <tr>@foreach($r['columns'] as $c)<th class="num">{{ str_contains($c['label'], '—') ? trim(explode('—', $c['label'])[1]) : $c['label'] }}</th>@endforeach</tr>
        @else
            <tr><th></th>@foreach($r['columns'] as $c)<th @class(['num' => $c['type'] !== 'text'])>{{ $c['label'] }}</th>@endforeach</tr>
        @endif
    </thead>
    <tbody>
    @foreach($r['lines'] as $l)
        <tr class="fr-{{ $l['kind'] }} fr-l{{ $l['level'] }}">
            <td class="fr-name" style="padding-inline-start: {{ 10 + $l['level'] * 18 }}px">
                @if($l['kind'] === 'account' && ! empty($l['foldable']) && $links)
                    <a class="fold" href="{{ request()->url().'?'.http_build_query($o->query(['acc' => $l['unfolded'] ? array_values(array_diff($o->unfolded, [$l['account_id']])) : array_merge($o->unfolded, [$l['account_id']]), 'unfold' => null])) }}">{{ $l['unfolded'] ? '▾' : '▸' }}</a>
                @endif
                @if(! empty($l['code']))<bdi class="code" dir="ltr">{{ $l['code'] }}</bdi>@endif
                @if($l['kind'] === 'account' && $links && empty($l['foldable']))
                    <a href="{{ route('accounting.accounts.show', [$l['account_id'], 'from' => $r['columns'][0]['from'] ?? $o->from, 'to' => $r['columns'][0]['to'] ?? $o->to] + \App\Support\BackLink::carry(request()->getRequestUri())) }}">{{ $l['label'] }}</a>
                @elseif($l['kind'] === 'detail' && $links && ! empty($l['entry_id']))
                    <a href="{{ route('accounting.journal.show', [$l['entry_id']] + \App\Support\BackLink::carry(request()->getRequestUri())) }}">{{ $l['label'] }}</a>
                @else
                    {{ $l['label'] }}
                @endif
                @if(! empty($l['untyped']))<span class="badge b-ON_HOLD">{{ __('بلا نوع') }}</span>@endif
                @if(! empty($l['computed']))<span class="muted fr-note">{{ __('محسوب') }}</span>@endif
            </td>
            @foreach($r['columns'] as $c)
                @php($v = $l['values'][$c['key']] ?? null)
                @if($c['type'] === 'text')
                    <td class="fr-text" dir="auto">{{ $v }}</td>
                @else
                    <td class="num {{ $v !== null && (float) $v < -0.004 ? 'neg' : '' }}">{{ $c['type'] === 'pct' ? $pct($v) : $money($v) }}</td>
                @endif
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>
