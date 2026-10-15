@extends('layouts.app')
@section('title', __('الاستثناءات'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('الاستثناءات'), null]]])
@endsection
@section('content')
@php($tone = ['RED' => 'bad', 'ORANGE' => 'warn', 'YELLOW' => 'info'])
@php($dot = ['RED' => '🔴', 'ORANGE' => '🟠', 'YELLOW' => '🟡'])
@if(empty($items))
    <div class="card empty-state"><strong>{{ __('لا استثناءات') }}</strong>{{ __('كل الفحوصات سليمة: لا مستندات غير مرحَّلة، ولا فروق مطابقة، ولا ملاحظات ضريبية.') }}</div>
@else
<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الاستثناء') }}</th><th class="num">{{ __('العدد') }}</th><th class="num">{{ __('المبلغ') }}</th><th></th></tr>
    @foreach($items as $i)
        <tr class="row-link" onclick="location='{{ route('accounting.exceptions.show', $i['key']) }}'">
            <td>{{ $dot[$i['severity']] }} <a href="{{ route('accounting.exceptions.show', $i['key']) }}"><strong>{{ $i['label'] }}</strong></a><div class="hint" style="margin:0">{{ $i['hint'] }}</div></td>
            <td class="num">{{ $i['count'] }}</td><td class="num">{{ $i['amount'] === null ? '—' : number_format($i['amount'], 2) }}</td>
            <td><span class="badge b-{{ $i['severity'] === 'RED' ? 'CANCELLED' : ($i['severity'] === 'ORANGE' ? 'ON_HOLD' : 'DRAFT') }}">{{ __("rroka.severity.{$i['severity']}") }}</span></td>
        </tr>
    @endforeach
</table></div></div>
@endif
<p class="hint">{{ __('🔴 يمنع إقفال الشهر (المستندات غير المرحَّلة والمبالغ بلا طرف تمنعه قاعدة البيانات لشهرها) · 🟠 يُراجَع قبل الإقرار الضريبي · 🟡 مراجعة دورية. المبلغ مجموع القيم المطلقة.') }}</p>
@endsection
