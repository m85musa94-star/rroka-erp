@extends('layouts.app')
@section('title', __('مستندات لم تُرحَّل'))
@section('cp')
    @include('partials.control-panel', [
        'crumbs' => [[__('المحاسبة'), route('accounting.journal.index')], [__('مستندات لم تُرحَّل'), null]], 'lv' => $lv,
        'paginator' => $rows, 'total' => $groups?->flatten()->count(),
        'placeholder' => __('بحث برقم المستند أو البيان…'),
    ])
@endsection
@section('content')
@php($canPost = auth()->user()->hasPermission('accounting.post'))
<div class="card">
    <p style="margin:0">{{ __('مستندات معتمدة بتاريخ بداية الدفاتر أو بعده ولا قيد لها. كل مستند إما يُرحَّل بقيده، أو يُستبعد بسبب مكتوب (مثل: مشمول في القيد الافتتاحي).') }}</p>
    @unless($auto)<p class="hint" style="margin-bottom:0">{{ __('الترحيل الآلي متوقف؛ ما يُعتمد الآن يُضاف هنا.') }} <a href="{{ route('accounting.posting.settings') }}">{{ __('الربط المحاسبي') }}</a></p>@endunless
    @if($canPost && $total)
        <form method="post" action="{{ route('accounting.posting.post-all') }}" style="margin-top:10px" data-confirm="{{ __('ترحيل كل المستندات (:n)؟ كل قيد يُرحَّل نهائيًا بتاريخ مستنده.', ['n' => $total]) }}">@csrf
            <button class="btn ok">{{ __('ترحيل الكل (:n)', ['n' => $total]) }}</button></form>
    @endif
</div>
@php($list = $rows ?? $groups->flatten())
@if($list->isEmpty())
    <div class="card empty-state"><strong>{{ __('لا مستندات تنتظر الترحيل') }}</strong>{{ __('كل مستند معتمد له قيده أو استُبعد بسبب.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table class="o-list">
    <tr><th>{{ __('التاريخ') }}</th><th>{{ __('النوع') }}</th><th>{{ __('المستند') }}</th><th>{{ __('البيان') }}</th><th class="num">{{ __('المبلغ') }}</th>@if($canPost)<th></th>@endif</tr>
    @foreach($groups ?? ['' => $rows] as $title => $items)
        @if($groups)
            <tr class="grp"><td colspan="4">{{ $title }}<span class="grp-count">({{ $items->count() }})</span></td><td class="num">{{ number_format($items->sum('amount'), 2) }}</td>@if($canPost)<td></td>@endif</tr>
        @endif
        @foreach($items as $d)
            <tr>
                <td class="num">{{ $d->doc_date->format('Y-m-d') }}</td>
                <td>{{ __("rroka.journal_source.$d->source_type") }}@if($d->movement_type) <span class="muted">· {{ __("rroka.movement_type.$d->movement_type") }}</span>@endif</td>
                <td>@if($url = $d->documentUrl())<a href="{{ $url }}" dir="ltr">{{ $d->doc_no }}</a>@else<span dir="ltr">{{ $d->doc_no }}</span>@endif</td>
                <td><bdi>{{ $d->description }}</bdi></td>
                <td class="num">{{ $d->amount === null ? '—' : number_format($d->amount, 2) }}</td>
                @if($canPost)
                    <td style="white-space:nowrap">
                        <form method="post" action="{{ route('accounting.posting.post', [$d->source_type, $d->source_id]) }}" class="inline">@csrf<button class="btn ok sm">{{ __('ترحيل') }}</button></form>
                        <details class="inline-details"><summary class="btn ghost sm">{{ __('استبعاد') }}</summary>
                            <form method="post" action="{{ route('accounting.posting.exclude', [$d->source_type, $d->source_id]) }}" class="inline">@csrf
                                <input name="reason" required maxlength="500" placeholder="{{ __('سبب الاستبعاد') }}" style="width:200px"><button class="btn ghost sm">{{ __('تأكيد') }}</button></form>
                        </details>
                    </td>
                @endif
            </tr>
        @endforeach
    @endforeach
</table></div></div>
@endif

@if($exclusions->isNotEmpty())
<div class="card">
    <h2>{{ __('مستندات مستبعدة من الدفاتر') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('النوع') }}</th><th>{{ __('المستند') }}</th><th>{{ __('السبب') }}</th><th>{{ __('بواسطة') }}</th>@if($canPost)<th></th>@endif</tr>
        @foreach($exclusions as $x)
            <tr><td>{{ __("rroka.journal_source.$x->source_type") }}</td>
                <td>@if($url = \App\Models\JournalEntry::documentUrl($x->source_type, $x->source_id))<a href="{{ $url }}" dir="ltr">#{{ $x->source_id }}</a>@else<span dir="ltr">#{{ $x->source_id }}</span>@endif</td>
                <td><bdi>{{ $x->reason }}</bdi></td><td>{{ $names[$x->created_by] ?? '—' }} — {{ \Carbon\Carbon::parse($x->created_at)->format('Y-m-d') }}</td>
                @if($canPost)<td><form method="post" action="{{ route('accounting.posting.unexclude', $x->id) }}" class="inline" data-confirm="{{ __('إلغاء الاستبعاد وإعادة المستند لقائمة الترحيل؟') }}">@csrf @method('delete')<button class="btn ghost sm">{{ __('إلغاء الاستبعاد') }}</button></form></td>@endif
            </tr>
        @endforeach
    </table></div>
</div>
@endif
@endsection
