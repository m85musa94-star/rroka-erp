@extends('layouts.app')
@section('title', $asset->exists ? __('تعديل ').$asset->asset_no : __('رفع صور'))
@section('cp')
    @include('partials.control-panel', ['crumbs' => $asset->exists
        ? [[__('الاستوديو'), route('studio.index')], [$asset->asset_no, route('studio.show', $asset)], [__('تعديل'), null]]
        : [[__('الاستوديو'), route('studio.index')], [__('رفع صور'), null]]])
@endsection
@section('content')
@unless($ready)
    <div class="alert warn">{{ __('مخزن الصور غير مربوط بعد، فلا تُحفظ الصور حتى لا تضيع. راجع دليل النشر.') }}</div>
@endunless
<form method="post" action="{{ $asset->exists ? route('studio.update', $asset) : route('studio.store') }}" enctype="multipart/form-data" class="card" id="studio-form">
    @csrf
    @if($asset->exists) @method('put') @endif
    @unless($asset->exists)
        <div class="field">
            <label>{{ __('الصور *') }}</label>
            <input type="file" name="files[]" id="files" accept="image/jpeg,image/png,image/webp" multiple required>
            <div class="hint">{{ __('حتى ٢٠ صورة في المرة (JPG أو PNG أو WEBP). الصور الكبيرة تُصغَّر تلقائيًا قبل الرفع مع الحفاظ على وضوحها.') }}</div>
            <div id="previews" class="studio-previews"></div>
        </div>
    @endunless
    <div class="grid g2">
        <div class="field"><label>{{ $asset->exists ? __('العنوان *') : __('العنوان') }}</label>
            <input name="title" value="{{ old('title', $asset->title) }}" @if($asset->exists) required @endif placeholder="{{ __('مثال: مطبخ خشب بلوط — فيلا النرجس') }}">
            @unless($asset->exists)<div class="hint">{{ __('إن تُرك فارغًا يُستخدم اسم الملف.') }}</div>@endunless
        </div>
        <div class="field"><label>{{ __('التصنيف *') }}</label>
            <select name="category" id="category" required>
                @foreach(\App\Models\StudioAsset::CATEGORIES as $c)
                    <option value="{{ $c }}" @selected(old('category', $asset->category) === $c)>{{ __("rroka.studio_category.$c") }}</option>
                @endforeach
            </select>
            <div class="hint">{{ __('صور العملاء خاصة بصاحبها: لا تظهر إلا في عروض أسعاره.') }}</div>
        </div>
        <div class="field"><label>{{ __('العميل') }}</label>
            <select name="client_id" id="client_id">
                <option value="">{{ __('— بلا —') }}</option>
                @foreach($clients as $c)
                    <option value="{{ $c->id }}" @selected((string) old('client_id', $asset->client_id) === (string) $c->id)>{{ $c->business_name }} ({{ $c->client_no }})</option>
                @endforeach
            </select>
        </div>
        <div class="field"><label>{{ __('المشروع') }}</label>
            <select name="project_id" id="project_id">
                <option value="">{{ __('— بلا —') }}</option>
                @foreach($projects as $p)
                    <option value="{{ $p->id }}" data-client="{{ $p->client_id }}" @selected((string) old('project_id', $asset->project_id) === (string) $p->id)>{{ $p->project_no }} — {{ $p->title }}</option>
                @endforeach
            </select>
            <div class="hint">{{ __('عند اختيار مشروع يُؤخذ عميله تلقائيًا.') }}</div>
        </div>
    </div>
    <div class="field"><label>{{ __('الوسوم') }}</label><input name="tags" value="{{ old('tags', $asset->tags) }}" placeholder="{{ __('مثال: مطبخ، بلوط، مودرن') }}"></div>
    <div class="field"><label>{{ __('ملاحظات') }}</label><textarea name="notes">{{ old('notes', $asset->notes) }}</textarea></div>
    <div class="actions"><button class="btn" id="submit" @disabled(! $ready)>{{ $asset->exists ? __('حفظ') : __('رفع') }}</button><a class="btn ghost" href="{{ url()->previous() }}">{{ __('إلغاء') }}</a></div>
</form>
@endsection
@push('scripts')
<script>
(() => {
    // Picking a project selects its customer.
    const project = document.getElementById('project_id'), client = document.getElementById('client_id');
    project.addEventListener('change', () => { const c = project.selectedOptions[0]?.dataset.client; if (c) client.value = c; });

    const input = document.getElementById('files');
    if (!input) return;
    const MAX_EDGE = 2400, MAX_BYTES = 2.5 * 1024 * 1024;
    const box = document.getElementById('previews');

    // Phone photos are often 5–12 MB: shrink before upload so they pass server limits and load fast.
    async function shrink(file) {
        if (file.size <= MAX_BYTES || !/^image\/(jpeg|png|webp)$/.test(file.type)) return file;
        const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' }).catch(() => null);
        if (!bmp) return file;
        const scale = Math.min(1, MAX_EDGE / Math.max(bmp.width, bmp.height));
        const c = document.createElement('canvas');
        c.width = Math.round(bmp.width * scale); c.height = Math.round(bmp.height * scale);
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(bmp, 0, 0, c.width, c.height);
        const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.86));
        return blob && blob.size < file.size ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
    }

    let busy = false;
    input.addEventListener('change', async () => {
        busy = true; box.textContent = '{{ __('جارٍ تجهيز الصور…') }}';
        const dt = new DataTransfer();
        for (const f of [...input.files].slice(0, 20)) dt.items.add(await shrink(f));
        input.files = dt.files;
        box.textContent = '';
        [...dt.files].forEach(f => {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(f); img.alt = f.name;
            box.appendChild(img);
        });
        busy = false;
    });
    document.getElementById('studio-form').addEventListener('submit', e => { if (busy) e.preventDefault(); });
})();
</script>
@endpush
