@extends('layouts.app')
@section('title', __('الفترات المالية'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('المحاسبة'), route('accounting.journal.index')], [__('الفترات المالية'), null]]])
@endsection
@section('content')
@php($canClose = auth()->user()->hasPermission('accounting.close'))
<div class="card">
    <p style="margin:0">{{ __('بداية الدفاتر') }}: <strong>{{ $start ?? __('غير محددة') }}</strong></p>
    <p class="hint" style="margin-bottom:0">{{ __('يُقفل الشهر بعد مراجعته ومطابقة البنوك؛ لا يُرحَّل فيه شيء بعد إقفاله. تُقفل الشهور بالترتيب، ولا يُقفل شهر فيه مسودات. إعادة الفتح تحتاج سببًا مكتوبًا يُحفظ في السجل.') }}</p>
</div>
@if(! $start)
    <div class="card empty-state"><strong>{{ __('تاريخ بداية الدفاتر غير محدد') }}</strong></div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الشهر') }}</th><th>{{ __('الحالة') }}</th><th class="num">{{ __('قيود مرحَّلة') }}</th><th class="num">{{ __('مسودات') }}</th><th>{{ __('الإقفال') }}</th>@if($canClose)<th></th>@endif</tr>
    @foreach($rows as $r)
        @php($status = $r['p']?->status ?? 'OPEN')
        <tr>
            <td class="num"><a href="{{ route('accounting.trial-balance', ['from' => $r['start'], 'to' => \Carbon\Carbon::parse($r['start'])->endOfMonth()->toDateString()]) }}">{{ $r['month'] }}</a></td>
            <td>@include('partials.badge', ['s' => $status])</td>
            <td class="num">{{ $r['posted'] }}</td>
            <td class="num">@if($r['drafts'])<a href="{{ route('accounting.journal.index', ['f' => ['draft']]) }}">{{ $r['drafts'] }}</a>@else 0 @endif</td>
            <td>@if($status === 'CLOSED'){{ $names[$r['p']->closed_by] ?? '—' }} — {{ $r['p']->closed_at->format('Y-m-d') }}@elseif($r['p']?->reopen_reason)<span class="muted">{{ __('أُعيد فتحه: :r', ['r' => $r['p']->reopen_reason]) }}</span>@endif</td>
            @if($canClose)
                <td>
                    @if($status === 'OPEN')
                        <form method="post" action="{{ route('accounting.periods.close', $r['month']) }}" class="inline" data-confirm="{{ __('إقفال شهر :m؟ لا يُرحَّل فيه شيء بعدها.', ['m' => $r['month']]) }}">@csrf<button class="btn ghost sm">{{ __('إقفال') }}</button></form>
                    @else
                        <form method="post" action="{{ route('accounting.periods.reopen', $r['month']) }}" class="inline">@csrf
                            <input name="reopen_reason" required placeholder="{{ __('سبب إعادة الفتح') }}" style="width:200px"><button class="btn ghost sm">{{ __('إعادة فتح') }}</button></form>
                    @endif
                </td>
            @endif
        </tr>
    @endforeach
</table></div></div>
@endif
@endsection
