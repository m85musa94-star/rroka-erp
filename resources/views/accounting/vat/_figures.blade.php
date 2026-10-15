{{-- The return of a period: $r from VatReport::build. --}}
@php($b = $r['buckets'])
@php($row = fn ($k) => [number_format($b[$k]->amount, 2), number_format($b[$k]->vat, 2), $b[$k]->docs])
<div class="table-wrap"><table class="fin-report">
    <tr><th>{{ __('البند') }}</th><th class="num">{{ __('المبلغ قبل الضريبة') }}</th><th class="num">{{ __('الضريبة') }}</th><th class="num">{{ __('المستندات') }}</th></tr>
    <tr class="fr-heading"><td colspan="4">{{ __('المبيعات') }}</td></tr>
    @foreach(['SALES_STD', 'SALES_ZERO', 'SALES_CREDIT'] as $k)
        <tr><td>{{ __("rroka.vat_bucket.$k") }}</td>@foreach($row($k) as $c)<td class="num">{{ $c }}</td>@endforeach</tr>
    @endforeach
    <tr class="fr-total"><td>{{ __('ضريبة المخرجات') }}</td><td></td><td class="num">{{ number_format($r['output'], 2) }}</td><td></td></tr>
    <tr class="fr-heading"><td colspan="4">{{ __('المشتريات والمصروفات') }}</td></tr>
    @foreach(['PURCH_DEDUCT', 'PURCH_VERIFY', 'PURCH_NOVAT'] as $k)
        <tr><td>@if($k === 'PURCH_VERIFY')⚠ @endif{{ __("rroka.vat_bucket.$k") }}</td>@foreach($row($k) as $c)<td class="num">{{ $c }}</td>@endforeach</tr>
    @endforeach
    <tr><td>{{ __('الاستيراد (لا تتوفر بياناته في المنظومة)') }}</td><td class="num">—</td><td class="num">—</td><td></td></tr>
    <tr class="fr-total"><td>{{ __('ضريبة المدخلات القابلة للخصم') }}</td><td></td><td class="num">{{ number_format($r['input'], 2) }}</td><td></td></tr>
    <tr class="fr-grand"><td>{{ $r['net'] >= 0 ? __('صافي المستحق للهيئة') : __('صافي المسترد من الهيئة') }}</td><td></td><td class="num">{{ number_format(abs($r['net']), 2) }}</td><td></td></tr>
</table></div>
