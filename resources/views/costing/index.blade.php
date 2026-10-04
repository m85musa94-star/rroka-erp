@extends('layouts.app')
@section('title', __('محرك التكلفة'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => [[__('محرك التكلفة'), null]]])
@endsection
@section('content')
@php($u = auth()->user())
<div class="card">
    <h2>{{ __('ما يلزم قبل حساب التكلفة المعيارية') }}</h2>
    <p class="hint" style="margin-top:0">{{ __('كل معدل يُدخل من مستند حقيقي ثم يُعتمد فيصبح نهائيًا. ما لم يُدخل يبقى ناقصًا، والتكلفة التي تحتاجه تظهر «غير مكتملة» لا رقمًا مفترضًا.') }}</p>
    <div class="table-wrap"><table>
        <tr><th>{{ __('البند') }}</th><th>{{ __('الحالة') }}</th><th></th></tr>
        <tr><td>{{ __('مراكز التكلفة') }}</td>
            <td>@if($centers)<span class="badge b-SUCCESS">{{ __(':n مركز', ['n' => $centers]) }}</span>@else<span class="badge b-CANCELLED">{{ __('لم تُضف') }}</span>@endif</td>
            <td><a href="{{ route('costing.centers') }}">{{ __('فتح') }}</a></td></tr>
        <tr><td>{{ __('سعر الكهرباء المعتمد') }}</td>
            <td>@if($energy?->id)<span class="badge b-SUCCESS">{{ number_format($energy->rate_per_kwh, 4) }} {{ __('ريال/كيلوواط ساعة') }}</span>@else<span class="badge b-CANCELLED">{{ __('غير مُدخل') }}</span>@endif</td>
            <td><a href="{{ route('costing.energy') }}">{{ __('فتح') }}</a></td></tr>
        <tr><td>{{ __('عمال الإنتاج المباشر بلا بطاقة تكلفة معتمدة') }}</td>
            <td>@if($workersMissing->isEmpty())<span class="badge b-SUCCESS">{{ __('مكتمل') }}</span>@else<span class="badge b-ON_HOLD">{{ $workersMissing->count() }}</span> <span class="muted">{{ $workersMissing->pluck('name')->take(5)->join(__('، ')) }}</span>@endif</td>
            <td><a href="{{ route('costing.employee-cards.index') }}">{{ __('فتح') }}</a></td></tr>
        <tr><td>{{ __('آلات بلا بطاقة تكلفة معتمدة') }}</td>
            <td>@if($machinesMissing->isEmpty())<span class="badge b-SUCCESS">{{ __('مكتمل') }}</span>@else<span class="badge b-ON_HOLD">{{ $machinesMissing->count() }}</span> <span class="muted">{{ $machinesMissing->pluck('code')->take(5)->join(__('، ')) }}</span>@endif</td>
            <td><a href="{{ route('costing.machine-cards.index') }}">{{ __('فتح') }}</a></td></tr>
        <tr><td>{{ __('أوعية التكاليف غير المباشرة السارية اليوم') }}</td>
            <td>@forelse($pools as $p)<span class="badge b-SUCCESS">{{ $p->kind === 'SELLING_ADMIN' ? __('بيعية وإدارية') : ($p->costCenter?->name ?? __('المصنع كله')) }}</span> @empty<span class="badge b-CANCELLED">{{ __('لا يوجد') }}</span>@endforelse</td>
            <td><a href="{{ route('costing.pools.index') }}">{{ __('فتح') }}</a></td></tr>
        <tr><td>{{ __('خامات بلا سعر معياري معتمد') }}</td>
            <td>@if($materialsMissing === 0)<span class="badge b-SUCCESS">{{ __('مكتمل') }}</span>@else<span class="badge b-ON_HOLD">{{ $materialsMissing }}</span>@endif</td>
            <td><a href="{{ route('costing.prices') }}">{{ __('فتح') }}</a></td></tr>
        <tr><td>{{ __('مسودات بانتظار الاعتماد') }}</td>
            <td>@if($drafts->sum() === 0)<span class="muted">—</span>@else<span class="badge b-DRAFT">{{ $drafts->sum() }}</span>@endif</td><td></td></tr>
    </table></div>
</div>
<div class="card">
    <h2>{{ __('كيف تُحسب المعدلات') }}</h2>
    <ul>
        <li>{{ __('أجر ساعة الموظف = تكلفته الشهرية ÷ ساعاته الإنتاجية العملية (بعد استبعاد الوقت غير المنتج بندًا بندًا).') }}</li>
        <li>{{ __('تكلفة ساعة الآلة = الإهلاك + الكهرباء + الصيانة + قطع الغيار + التشغيل الآخر، كلها للساعة العملية.') }}</li>
        <li>{{ __('معدل التحميل = التكاليف غير المباشرة المتوقعة للمركز ÷ الطاقة العملية لمحركه (ساعات عمل أو ساعات آلة).') }}</li>
        <li>{{ __('لا يوزَّع أي مصروف بالتساوي على عدد المنتجات، ولا يُحمَّل بند مرتين.') }}</li>
    </ul>
</div>
@endsection
