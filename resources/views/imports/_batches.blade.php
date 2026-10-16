<div class="card" style="padding:0"><div class="table-wrap"><table>
    <tr><th>{{ __('الدفعة') }}</th>@if($withType ?? false)<th>{{ __('الشاشة') }}</th>@endif<th>{{ __('الملف') }}</th><th class="num">{{ __('الأسطر') }}</th><th class="num">{{ __('الأخطاء') }}</th><th>{{ __('الحالة') }}</th><th>{{ __('رفعه') }}</th><th>{{ __('التاريخ') }}</th></tr>
    @foreach($batches as $b)
        <tr class="row-link">
            <td><a href="{{ route('imports.batch', $b) }}">{{ $b->batch_no }}</a></td>
            @if($withType ?? false)<td>{{ __($b->def()['label'] ?? $b->import_type) }}</td>@endif
            <td><bdi>{{ $b->file_name }}</bdi></td>
            <td class="num">{{ $b->row_count }}</td>
            <td class="num">{{ $b->status === 'PREVIEW' ? $b->error_count : '—' }}</td>
            <td>@include('partials.badge', ['s' => $b->status])</td>
            <td>{{ $b->creator->name ?? '—' }}</td>
            <td>{{ $b->created_at->format('Y-m-d H:i') }}</td>
        </tr>
    @endforeach
</table></div></div>
