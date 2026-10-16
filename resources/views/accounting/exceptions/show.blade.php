@extends('layouts.app')
@section('title', $def['label'])
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الاستثناءات'), route('accounting.exceptions.index')], [$def['label'], null]]])
@endsection
@section('content')
<div class="card">
    <h1 class="rec-title">{{ $def['label'] }}</h1>
    <p class="rec-sub">{{ $def['hint'] }}</p>
    @if($key === 'backlog' && $rows->isNotEmpty() && auth()->user()->hasPermission('accounting.post'))
        <form method="post" action="{{ route('accounting.posting.post-all') }}" data-confirm="{{ __('ترحيل كل المستندات المعروضة؟') }}" style="margin-bottom:10px">@csrf<button class="btn sm">{{ __('ترحيل الكل') }}</button></form>
    @endif
    @if($rows->isEmpty())
        <p class="muted">{{ __('لا شيء هنا الآن.') }}</p>
    @else
    <div class="table-wrap"><table>
        <tr><th>{{ __('المستند') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('البيان') }}</th><th class="num">{{ __('المبلغ') }}</th><th>{{ __('الإجراء') }}</th></tr>
        @foreach($rows as $r)
            <tr><td>@if($r->url)<a href="{{ $r->url }}" dir="ltr">{{ $r->doc_no }}</a>@else{{ $r->doc_no }}@endif</td>
                <td class="num">{{ $r->doc_date ? \Illuminate\Support\Carbon::parse($r->doc_date)->format('Y-m-d') : '—' }}</td>
                <td>{{ $r->label }}</td><td class="num">{{ $r->amount === null ? '—' : number_format($r->amount, 2) }}</td>
                <td class="actions">@forelse(\App\Accounting\Exceptions::actions($key, $r) as [$label, $url, $method])
                    @if($method === 'post')<form method="post" action="{{ $url }}" class="inline">@csrf<button class="btn sm">{{ $label }}</button></form>
                    @else<a class="btn ghost sm" href="{{ $url }}">{{ $label }}</a>@endif
                @empty<span class="muted">{{ __('للمراجعة فقط') }}</span>@endforelse</td></tr>
        @endforeach
    </table></div>
    @endif
</div>
@endsection
