{{-- Images attached after approval ($type = route type, $model = the document). The document itself never changes. --}}
@php([$source, , $perm] = \App\Http\Controllers\Web\DocumentAttachmentController::TYPES[$type])
@php($extra = \App\Http\Controllers\Web\DocumentAttachmentController::for($source, $model->id))
@if($model->status !== 'DRAFT' && ($extra->isNotEmpty() || auth()->user()->hasPermission($perm)))
<div class="card">
    <h2>{{ __('المستندات المؤيدة') }}</h2>
    @if($extra->isNotEmpty())
        <p>@foreach($extra as $a)<a class="btn ghost sm" href="{{ $a->url(false) }}" target="_blank" rel="noopener">{{ $a->title }}</a> @endforeach</p>
    @elseif(! $model->attachment_id)
        <p class="hint warn-text">{{ __('لا توجد صورة للمستند المؤيد.') }}</p>
    @endif
    @if(auth()->user()->hasPermission($perm))
        @if(\App\Services\StudioStorage::isReady())
            <form method="post" action="{{ route('attachments.store', [$type, $model->id]) }}" enctype="multipart/form-data" class="inline">
                @csrf
                <input type="file" name="document" accept="image/jpeg,image/png,image/webp" required>
                <button class="btn ghost sm">{{ __('إرفاق صورة') }}</button>
            </form>
            <p class="hint">{{ __('المستند المعتمد لا يتغير؛ الصورة تُحفظ بجانبه ولا تُحذف.') }}</p>
        @else
            <p class="hint">{{ __('مخزن الصور غير مربوط بعد، فلا تُحفظ الصور حتى لا تضيع. راجع دليل النشر.') }}</p>
        @endif
    @endif
</div>
@endif
