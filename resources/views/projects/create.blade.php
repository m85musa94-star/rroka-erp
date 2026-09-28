@extends('layouts.app')
@section('title', 'مشروع جديد')
@section('cp')
    @include('partials.control-panel', ['crumbs' => [['المشاريع', route('projects.index')], ['جديد', null]]])
@endsection
@section('content')
@if($quotations->isEmpty())
    <div class="alert warn">لا توجد عروض أسعار معتمدة بلا مشروع. المشروع لا يُنشأ إلا على عرض سعر معتمد.</div>
@else
<form method="post" action="{{ route('projects.store') }}" class="card">
    @csrf
    <div class="grid g2">
        <div class="field"><label>عرض السعر المعتمد *</label>
            <select name="quotation_id" required>
                @foreach($quotations as $q)
                    <option value="{{ $q->id }}" @selected((string) old('quotation_id', $selected) === (string) $q->id)>{{ $q->quotation_no }} — {{ $q->client->business_name }}</option>
                @endforeach
            </select>
            <div class="hint">العميل وقيمة العقد تُنقل من العرض آليًا ولا تُعدَّل.</div>
        </div>
        <div class="field"><label>عنوان المشروع *</label><input name="title" value="{{ old('title') }}" required placeholder="مثال: مطبخ فيلا حي النرجس"></div>
        <div class="field"><label>تاريخ البدء *</label><input type="date" name="start_date" value="{{ old('start_date', now()->format('Y-m-d')) }}" required></div>
        <div class="field"><label>تاريخ التسليم المستهدف</label><input type="date" name="target_date" value="{{ old('target_date') }}"></div>
        <div class="field"><label>مدير المشروع</label>
            <select name="manager_id"><option value="">—</option>
                @foreach($users as $u)<option value="{{ $u->id }}" @selected((string) old('manager_id') === (string) $u->id)>{{ $u->name }}</option>@endforeach
            </select>
        </div>
    </div>
    <div class="actions"><button class="btn">إنشاء المشروع</button></div>
</form>
@endif
@endsection
