@if($log->isNotEmpty())
<div class="card">
    <h2>{{ __('سجل المزامنة مع دفترة') }}</h2>
    <div class="table-wrap"><table>
        <tr><th>{{ __('المحاولة') }}</th><th>{{ __('الحالة') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('رقم دفترة') }}</th><th>{{ __('الخطأ') }}</th></tr>
        @foreach($log as $l)
            <tr>
                <td>{{ $l->attempt }}</td>
                <td>@include('partials.badge', ['s' => $l->status])</td>
                <td class="num">{{ $l->created_at }}</td>
                <td class="num">{{ $l->daftra_id ?? '—' }}</td>
                <td>{{ $l->error_message ?? '—' }}</td>
            </tr>
        @endforeach
    </table></div>
    @if($log->contains('status', 'PENDING'))
        <p class="alert warn" style="margin:12px 0 0">{{ __('توجد محاولة لم تُحسم (انقطاع أثناء الاتصال). تحقّق يدويًا في دفترة قبل إعادة المحاولة لتجنّب التكرار.') }}</p>
    @endif
</div>
@endif
