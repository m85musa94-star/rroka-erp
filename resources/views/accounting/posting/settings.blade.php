@extends('layouts.app')
@section('title', __('الربط المحاسبي'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('المحاسبة'), route('accounting.journal.index')], [__('الربط المحاسبي'), null]]])
@endsection
@section('content')
@php($u = auth()->user())
@php($canEdit = $u->hasPermission('accounting.manage'))
@php($opt = function ($list, $selected) { $h = '<option value="">—</option>'; foreach ($list as $a) { $h .= '<option value="'.$a->id.'"'.((int) $selected === $a->id ? ' selected' : '').'>'.e($a->code.' — '.$a->label()).'</option>'; } return $h; })
@php($money = $accounts->whereIn('account_type', ['ASSET', 'LIABILITY']))
@php($spend = $accounts->whereIn('account_type', ['EXPENSE', 'ASSET']))
<div class="card">
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;justify-content:space-between">
        <div>
            <h2 style="margin:0 0 4px">{{ __('الترحيل الآلي') }}
                @if($settings->auto_posting)<span class="badge b-APPROVED">{{ __('مُفعَّل') }}</span>@else<span class="badge b-ON_HOLD">{{ __('متوقف') }}</span>@endif</h2>
            <p class="hint" style="margin:0">
                @if($settings->auto_posting)
                    {{ __('منذ :d: كل مصروف أو فاتورة مشتريات أو تحويل خزينة يُعتمد، وكل صرف أو إرجاع أو تسوية مخزون، يُنشئ قيده المرحَّل في اللحظة نفسها. إن نقص حساب يُرفض الاعتماد برسالة واضحة.', ['d' => \Carbon\Carbon::parse($settings->auto_posting_since)->format('Y-m-d H:i')]) }}
                @else
                    {{ __('المستندات تُعتمد كالمعتاد ولا تُنشأ لها قيود؛ تنتظر في «مستندات لم تُرحَّل». يُفعَّل الترحيل الآلي بعد اكتمال الربط أدناه.') }}
                @endif
            </p>
        </div>
        @if($u->hasPermission('accounting.close'))
            <form method="post" action="{{ route('accounting.posting.toggle') }}" data-confirm="{{ $settings->auto_posting ? __('إيقاف الترحيل الآلي؟ المستندات المعتمدة بعدها لن تدخل الدفاتر حتى تُرحَّل يدويًا.') : __('تفعيل الترحيل الآلي؟ كل اعتماد بعد الآن يُنشئ قيدًا مرحَّلًا نهائيًا.') }}">@csrf
                <input type="hidden" name="auto_posting" value="{{ $settings->auto_posting ? 0 : 1 }}">
                <button class="btn {{ $settings->auto_posting ? 'ghost' : 'ok' }}" @disabled(! $settings->auto_posting && count($gaps))>{{ $settings->auto_posting ? __('إيقاف') : __('تفعيل الترحيل الآلي') }}</button>
            </form>
        @endif
    </div>
    @if($backlog)
        <p style="margin:10px 0 0"><a href="{{ route('accounting.posting.backlog') }}">{{ __(':n مستند معتمد لم يُرحَّل بعد', ['n' => $backlog]) }}</a></p>
    @endif
</div>

@if(count($gaps))
<div class="alert warn">
    <strong>{{ __('الربط غير مكتمل') }}</strong> — {{ __('لا يُفعَّل الترحيل الآلي قبل تحديد الحسابات التالية:') }}
    <ul style="margin:6px 0 0">
        @foreach($gaps as $g)
            <li>@switch($g->gap_type)
                @case('ROLE'){{ __('دور نظامي') }}: {{ __("rroka.account_role.$g->label") }}@break
                @case('PAYMENT_ACCOUNT'){{ __('خزينة/بنك/عهدة') }}: <bdi>{{ $g->label }}</bdi>@break
                @default{{ __('بند مصروف') }}: <bdi>{{ $g->label }}</bdi>
            @endswitch</li>
        @endforeach
    </ul>
</div>
@endif

@if($accounts->isEmpty())
    <div class="card empty-state"><strong>{{ __('دليل الحسابات فارغ') }}</strong>{{ __('أنشئ دليل الحسابات أولًا ثم ارجع لتحديد الربط.') }}
        <a href="{{ route('accounting.accounts.index') }}">{{ __('دليل الحسابات') }}</a></div>
@else
<form method="post" action="{{ route('accounting.posting.links') }}">@csrf @method('put')
<fieldset @disabled(! $canEdit) style="border:0;padding:0;margin:0">
<div class="card">
    <h2>{{ __('الحسابات النظامية') }}</h2>
    <p class="hint">{{ __('حساب واحد لكل دور؛ يُستخدم في كل قيد آلي يحتاجه.') }}</p>
    <div class="table-wrap"><table>
        <tr><th>{{ __('الدور') }}</th><th>{{ __('يُستخدم في') }}</th><th style="width:40%">{{ __('الحساب') }}</th></tr>
        @foreach([
            'INVENTORY' => __('مدين بالمشتريات وإرجاع الخامات، دائن بصرف الخامات للمشاريع'),
            'WIP' => __('تكلفة المشاريع الجارية: الخامات المصروفة والمصروفات على المشاريع (لكل مشروع)'),
            'INPUT_VAT' => __('ضريبة القيمة المضافة على المصروفات والمشتريات'),
            'PAYABLE' => __('المستحق للموردين من فواتير المشتريات (لكل مورد)'),
            'INVENTORY_ADJUSTMENT' => __('فروقات جرد المخزون (زيادة أو نقص)'),
        ] as $role => $use)
            <tr><td>{{ __("rroka.account_role.$role") }}</td><td class="muted">{{ $use }}</td>
                <td><select name="roles[{{ $role }}]">{!! $opt($accounts->filter(fn ($a) => ! $a->system_role || $a->system_role === $role), $roles[$role]?->id) !!}</select></td></tr>
        @endforeach
    </table></div>
</div>

<div class="card">
    <h2>{{ __('الصناديق والبنوك والعهد') }}</h2>
    <p class="hint">{{ __('الحساب الذي يُقيَّد عليه ما يُدفع من كل خزينة. يمكن ربط عدة عهد بحساب «عهد الموظفين» نفسه؛ القيد يحمل اسم الموظف.') }}</p>
    @if($paymentAccounts->isEmpty())
        <p class="muted">{{ __('لا توجد خزائن بعد.') }} <a href="{{ route('treasury.accounts.index') }}">{{ __('الصناديق والبنوك والعهد') }}</a></p>
    @else
    <div class="table-wrap"><table>
        <tr><th>{{ __('الخزينة') }}</th><th>{{ __('النوع') }}</th><th style="width:40%">{{ __('الحساب') }}</th></tr>
        @foreach($paymentAccounts as $p)
            <tr @class(['muted' => ! $p->is_active])><td><bdi>{{ $p->name }}</bdi> @unless($p->is_active)<span class="badge b-CANCELLED">{{ __('مغلق') }}</span>@endunless</td>
                <td>{{ __("rroka.account_kind.$p->kind") }}</td>
                <td><select name="payment[{{ $p->id }}]">{!! $opt($money, $p->account_id) !!}</select></td></tr>
        @endforeach
    </table></div>
    @endif
</div>

<div class="card">
    <h2>{{ __('بنود المصروفات') }}</h2>
    <p class="hint">{{ __('حساب المصروف لكل بند. المصروف المرتبط بمشروع يُحمَّل على «أعمال تحت التنفيذ» لذلك المشروع بدلًا من حساب البند.') }}</p>
    @if($categories->isEmpty())
        <p class="muted">{{ __('لا توجد بنود مصروفات بعد.') }} <a href="{{ route('expense-categories.index') }}">{{ __('تصنيفات المصروفات') }}</a></p>
    @else
    <div class="table-wrap"><table>
        <tr><th>{{ __('البند') }}</th><th>{{ __('التصنيف') }}</th><th style="width:40%">{{ __('الحساب') }}</th></tr>
        @foreach($categories as $c)
            <tr @class(['muted' => ! $c->is_active])><td><bdi>{{ $c->name }}</bdi> @unless($c->is_active)<span class="badge b-CANCELLED">{{ __('موقوف') }}</span>@endunless</td>
                <td>{{ $c->is_overhead ? __('غير مباشر للورشة') : __('إداري وعمومي') }}</td>
                <td><select name="category[{{ $c->id }}]">{!! $opt($spend, $c->account_id) !!}</select></td></tr>
        @endforeach
    </table></div>
    @endif
</div>
@if($canEdit)<div class="actions"><button class="btn">{{ __('حفظ الربط') }}</button></div>@endif
</fieldset>
</form>
@endif

<div class="card">
    <h2>{{ __('ماذا يُقيَّد عند الاعتماد؟') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('المستند') }}</th><th>{{ __('مدين') }}</th><th>{{ __('دائن') }}</th></tr>
        <tr><td>{{ __('مصروف') }}</td><td>{{ __('حساب البند (أو أعمال تحت التنفيذ للمشروع) + ضريبة المدخلات') }}</td><td>{{ __('الخزينة أو البنك أو العهدة التي دُفع منها') }}</td></tr>
        <tr><td>{{ __('فاتورة مشتريات') }}</td><td>{{ __('مخزون الخامات (الصافي بعد الخصم) + ضريبة المدخلات') }}</td><td>{{ __('الموردون (باسم المورد)') }}</td></tr>
        <tr><td>{{ __('تحويل خزينة / صرف عهدة / إرجاعها') }}</td><td>{{ __('الخزينة المستلمة') }}</td><td>{{ __('الخزينة المحوِّلة') }}</td></tr>
        <tr><td>{{ __('صرف خامات لمشروع') }}</td><td>{{ __('أعمال تحت التنفيذ (المشروع)') }}</td><td>{{ __('مخزون الخامات (بمتوسط التكلفة)') }}</td></tr>
        <tr><td>{{ __('إرجاع خامات من مشروع') }}</td><td>{{ __('مخزون الخامات') }}</td><td>{{ __('أعمال تحت التنفيذ (المشروع)') }}</td></tr>
        <tr><td>{{ __('تسوية جرد (زيادة / نقص)') }}</td><td>{{ __('المخزون / فروقات الجرد') }}</td><td>{{ __('فروقات الجرد / المخزون') }}</td></tr>
    </table></div>
    <p class="hint">{{ __('قيد المستند نهائي ولا يُعكس يدويًا؛ أي تصحيح يكون بقيد يدوي مستقل. المستندات المؤرخة قبل بداية الدفاتر جزء من الأرصدة الافتتاحية ولا تُرحَّل.') }}</p>
</div>
@endsection
