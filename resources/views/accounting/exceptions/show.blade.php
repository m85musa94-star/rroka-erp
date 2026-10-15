@extends('layouts.app')
@section('title', $def['label'])
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الاستثناءات'), route('accounting.exceptions.index')], [$def['label'], null]]])
@endsection
@section('content')
<div class="card">
    <h1 class="rec-title">{{ $def['label'] }}</h1>
    <p class="rec-sub">{{ $def['hint'] }}</p>
    @if($rows->isEmpty())
        <p class="muted">{{ __('لا شيء هنا الآن.') }}</p>
    @else
    <div class="table-wrap"><table>
        <tr><th>{{ __('المستند') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('البيان') }}</th><th class="num">{{ __('المبلغ') }}</th></tr>
        @foreach($rows as $r)
            <tr><td>@if($r->url)<a href="{{ $r->url }}" dir="ltr">{{ $r->doc_no }}</a>@else{{ $r->doc_no }}@endif</td>
                <td class="num">{{ $r->doc_date ? \Illuminate\Support\Carbon::parse($r->doc_date)->format('Y-m-d') : '—' }}</td>
                <td>{{ $r->label }}</td><td class="num">{{ $r->amount === null ? '—' : number_format($r->amount, 2) }}</td></tr>
        @endforeach
    </table></div>
    @endif
</div>
@endsection
