@extends('layouts.app')
@section('title', $batch->batch_no)
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('استيراد البيانات'), route('imports.index')], [__($def['label']), route('imports.show', $batch->import_type)], [$batch->batch_no, null]]])
@endsection
@section('content')
@php($preview = $batch->status === 'PREVIEW')
@php($ok = $batch->row_count - $batch->error_count)
@php($shown = array_slice(array_values(array_filter($columns, fn ($c) => $c['field'] !== \App\Imports\Importer::DOC)), 0, 4))
<div class="rec-bar">
    <div class="actions">
        @if($preview)
            @if($batch->error_count === 0)
                <form method="post" action="{{ route('imports.import', $batch) }}" class="inline" data-confirm="{{ __('استيراد :n سطر؟ يُحفظ الملف كله أو لا شيء، والمستندات تُنشأ مسودات.', ['n' => $batch->row_count]) }}">@csrf<button class="btn ok sm">{{ __('استيراد الآن') }}</button></form>
            @else
                <a class="btn sm" href="{{ route('imports.errors', $batch) }}">⇩ {{ __('تنزيل الملف مع الأخطاء') }}</a>
            @endif
            <form method="post" action="{{ route('imports.check', $batch) }}" class="inline">@csrf<button class="btn ghost sm">{{ __('إعادة الفحص') }}</button></form>
            <form method="post" action="{{ route('imports.discard', $batch) }}" class="inline" data-confirm="{{ __('ترك هذا الملف دون استيراد؟') }}">@csrf<button class="btn ghost sm">{{ __('ترك الملف') }}</button></form>
        @else
            @if($canApprove)
                <form method="post" action="{{ route('imports.approve', $batch) }}" class="inline" data-confirm="{{ __('اعتماد :n مسودة من هذه الدفعة؟ تُعتمد كلها أو لا شيء، وتُرحَّل للدفاتر إن كان الترحيل التلقائي مفعّلًا.', ['n' => $drafts]) }}">@csrf<button class="btn ok sm">{{ __('اعتماد مسودات الدفعة (:n)', ['n' => $drafts]) }}</button></form>
            @endif
            <a class="btn ghost sm" href="{{ route('imports.errors', $batch) }}">⇩ {{ __('تنزيل الملف مع النتيجة') }}</a>
        @endif
        <a class="btn ghost sm" href="{{ route('imports.template', $batch->import_type) }}">{{ __('قالب جديد') }}</a>
    </div>
    @include('partials.statusbar', ['path' => $batch->status === 'DISCARDED' ? ['PREVIEW', 'DISCARDED'] : ['PREVIEW', 'IMPORTED'], 'current' => $batch->status, 'bad' => ['DISCARDED']])
</div>

@if($preview && $batch->error_count > 0)
    <div class="alert bad">{{ __('في الملف :e سطر بأخطاء ولا يمكن استيراده. نزّل «الملف مع الأخطاء»، صحّح الأسطر المعلّمة في عمود «نتيجة الفحص»، ثم ارفعه من جديد. إن كان الخطأ بيانات ناقصة في المنظومة (مثل مورد غير مسجل) أضفها ثم اضغط «إعادة الفحص».', ['e' => $batch->error_count]) }}</div>
@elseif($preview)
    <div class="alert ok">{{ __('كل الأسطر سليمة (:n). لم يُحفظ شيء بعد؛ اضغط «استيراد الآن».', ['n' => $batch->row_count]) }}</div>
@endif

<div class="card">
    <h1 class="rec-title">{{ $batch->batch_no }}</h1>
    <p class="rec-sub">{{ __($def['label']) }} · <bdi>{{ $batch->file_name }}</bdi></p>
    @include('partials.kpi-strip', ['kpis' => [
        [__('الأسطر'), $batch->row_count, null, null],
        [__('سليمة'), $preview ? $ok : $batch->row_count, null, 'ok'],
        [__('بأخطاء'), $preview ? $batch->error_count : 0, null, $preview && $batch->error_count ? 'bad' : null],
        [__('سجلات أُنشئت'), $batch->status === 'IMPORTED' ? $batch->record_count : '—', null, null],
    ]])
    <dl class="kv" style="margin-top:10px">
        <dt>{{ __('رفعه') }}</dt><dd>{{ $batch->creator->name ?? '—' }} — {{ $batch->created_at->format('Y-m-d H:i') }}</dd>
        <dt>{{ __('آخر فحص') }}</dt><dd>{{ $batch->checked_at?->format('Y-m-d H:i') ?? '—' }}</dd>
        @if($batch->imported_at)<dt>{{ __('استورده') }}</dt><dd>{{ $batch->importer->name ?? '—' }} — {{ $batch->imported_at->format('Y-m-d H:i') }}</dd>@endif
    </dl>
</div>

<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th class="num">{{ __('السطر') }}</th><th></th>
        @foreach($shown as $c)<th>{{ __($c['label']) }}</th>@endforeach
        <th>{{ __('النتيجة') }}</th><th>{{ __('السجل') }}</th></tr>
    @foreach($batch->rows as $r)
        @php($res = $results[$r['row']] ?? null)
        @php($rec = $records[$r['row']] ?? null)
        <tr @class(['imp-bad' => $res && ! $res['ok']])>
            <td class="num">{{ $r['row'] }}</td>
            <td>{{ $res === null ? '' : ($res['ok'] ? '✓' : '✗') }}</td>
            @foreach($shown as $c)<td><bdi>{{ \Illuminate\Support\Str::limit($r['v'][$c['field']] ?? '', 40) }}</bdi></td>@endforeach
            {{-- Dates and numbers inside a message keep their order in a right-to-left line. --}}
            <td class="imp-msg">@if($res){!! collect($res['messages'])->map(fn ($m) => preg_replace('/(\d[\d\-\/:.,]*\d)/u', '<bdi dir="ltr">$1</bdi>', e($m)))->implode('<br>') ?: e($res['ok'] ? __('سليم') : '') !!}@endif</td>
            <td>@if($rec && ! empty($def['show']))<a href="{{ ($def['show'])($rec->record_id) }}">{{ $res['number'] ?? '#'.$rec->record_id }}</a>@elseif($rec){{ $res['number'] ?? '#'.$rec->record_id }}@endif</td>
        </tr>
    @endforeach
</table></div></div>
@include('partials.chatter', ['activity' => $activity])
@endsection
