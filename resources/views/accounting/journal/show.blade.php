@extends('layouts.app')
@section('title', $e->title())
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('القيود'), route('accounting.journal.index')], [$e->title(), null]]])
@endsection
@section('content')
@php($u = auth()->user())
@php($diff = round($debit - $credit, 2))
<div class="rec-bar">
    <div class="actions">
        @if($e->status === 'DRAFT')
            @if($u->hasPermission('accounting.manage') && $e->source_type !== 'REVERSAL')<a class="btn ghost sm" href="{{ route('accounting.journal.edit', $e) }}">{{ __('تعديل') }}</a>@endif
            @if($u->hasPermission('accounting.post'))
                <form method="post" action="{{ route('accounting.journal.post', $e) }}" class="inline" data-confirm="{{ __('ترحيل القيد؟ يصبح نهائيًا ويُصحَّح بعدها بقيد عكسي فقط.') }}">@csrf<button class="btn ok sm">{{ __('ترحيل') }}</button></form>
            @endif
            @if($u->hasPermission('accounting.manage'))
                <form method="post" action="{{ route('accounting.journal.destroy', $e) }}" class="inline" data-confirm="{{ __('حذف المسودة؟') }}">@csrf @method('delete')<button class="btn ghost sm">{{ __('حذف') }}</button></form>
            @endif
        @endif
    </div>
    @include('partials.statusbar', ['path' => ['DRAFT', 'POSTED'], 'current' => $e->status])
</div>
<div class="card">
    <h1 class="rec-title" dir="auto">{{ $e->title() }}</h1>
    <p class="rec-sub">{{ $e->description }}</p>
    <dl class="kv">
        <dt>{{ __('التاريخ') }}</dt><dd>{{ $e->entry_date->format('Y-m-d') }}</dd>
        <dt>{{ __('النوع') }}</dt><dd>{{ __("rroka.journal_source.$e->source_type") }}</dd>
        <dt>{{ __('المرجع') }}</dt><dd>@if($e->isDocument() && ($src = $e->sourceUrl()))<a href="{{ $src }}" dir="ltr">{{ $e->reference }}</a>@else{{ $e->reference ?: '—' }}@endif</dd>
        <dt>{{ __('أعدّه') }}</dt><dd>{{ $names[$e->created_by] ?? '—' }}</dd>
        @if($e->posted_at)<dt>{{ __('رحّله') }}</dt><dd>{{ $names[$e->posted_by] ?? '—' }} — {{ $e->posted_at->format('Y-m-d H:i') }} @if($e->selfPosted())<span class="badge b-ON_HOLD">{{ __('ترحيل ذاتي') }}</span>@endif</dd>@endif
        @if($e->reverses)<dt>{{ __('يعكس القيد') }}</dt><dd><a href="{{ route('accounting.journal.show', $e->reverses) }}" dir="ltr">{{ $e->reverses->entry_no }}</a></dd>@endif
        @if($e->reversedBy)<dt>{{ __('عُكس بالقيد') }}</dt><dd><a href="{{ route('accounting.journal.show', $e->reversedBy) }}" dir="auto">{{ $e->reversedBy->title() }}</a> @include('partials.badge', ['s' => $e->reversedBy->status])</dd>@endif
    </dl>
</div>
<div class="card">
    <h2>{{ __('السطور') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الحساب') }}</th><th>{{ __('البيان') }}</th><th>{{ __('المشروع') }}</th><th>{{ __('مركز التكلفة') }}</th><th class="num">{{ __('مدين') }}</th><th class="num">{{ __('دائن') }}</th></tr>
        @foreach($e->lines as $l)
            <tr><td><a href="{{ route('accounting.accounts.show', $l->account) }}"><bdi dir="ltr">{{ $l->account->code }}</bdi> — {{ $l->account->label() }}</a></td>
                <td>{{ $l->description }}</td><td>{{ $l->project?->project_no }}</td><td>{{ $l->costCenter?->code }}</td>
                <td class="num">{{ (float) $l->debit ? number_format($l->debit, 2) : '' }}</td><td class="num">{{ (float) $l->credit ? number_format($l->credit, 2) : '' }}</td></tr>
        @endforeach
        <tr><th colspan="4">{{ __('المجموع') }}</th><th class="num">{{ number_format($debit, 2) }}</th><th class="num">{{ number_format($credit, 2) }}</th></tr>
    </table></div>
    @if($e->status === 'DRAFT' && ($diff != 0 || $e->lines->count() < 2))
        <p class="hint warn-text">{{ __('القيد غير متوازن (الفرق :d)؛ لا يُرحَّل حتى يتساوى المدين والدائن.', ['d' => number_format(abs($diff), 2)]) }}</p>
    @endif
</div>
@if($e->isDocument())
<p class="hint">{{ __('قيد آلي أُنشئ عند اعتماد المستند؛ لا يُعدَّل ولا يُعكس يدويًا. أي تصحيح يكون بقيد يدوي مستقل يُشار فيه إلى هذا القيد.') }}</p>
@elseif($e->isPosted() && ! $e->reversedBy && $u->hasPermission('accounting.post'))
<form method="post" action="{{ route('accounting.journal.reverse', $e) }}" class="card">@csrf
    <h2>{{ __('تصحيح القيد بقيد عكسي') }}</h2>
    <p class="hint">{{ __('القيد المرحَّل لا يُعدَّل ولا يُحذف. يُنشأ قيد معاكس له تمامًا كمسودة، وبعد ترحيله يُسجَّل القيد الصحيح من جديد.') }}</p>
    <div class="grid g3">
        <div class="field"><label>{{ __('تاريخ القيد العكسي *') }}</label><input type="date" name="entry_date" value="{{ old('entry_date', today()->format('Y-m-d')) }}" min="{{ $e->entry_date->format('Y-m-d') }}" required></div>
        <div class="field" style="grid-column: span 2"><label>{{ __('سبب التصحيح *') }}</label><input name="reason" value="{{ old('reason') }}" required></div>
    </div>
    <div class="actions"><button class="btn ghost">{{ __('إعداد القيد العكسي') }}</button></div>
</form>
@endif
@include('partials.chatter', ['activity' => $activity])
@endsection
