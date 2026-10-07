{{-- The journal entry of an approved document (dt/dd pair inside a .kv list). Shown to accounting users only. --}}
@php($au = auth()->user())
@if($approved && collect(['accounting.view', 'accounting.manage', 'accounting.post', 'accounting.close'])->contains(fn ($p) => $au->hasPermission($p)))
    @php($je = \App\Models\JournalEntry::forDocument($type, $id))
    <dt>{{ __('القيد المحاسبي') }}</dt>
    <dd>@if($je)<a href="{{ route('accounting.journal.show', $je) }}" dir="ltr">{{ $je->entry_no }}</a>
        @elseif(\Illuminate\Support\Facades\DB::table('posting_exclusions')->where('source_type', $type)->where('source_id', $id)->exists())<span class="badge b-CANCELLED">{{ __('مستبعد من الدفاتر') }}</span>
        @else<a class="badge b-ON_HOLD" href="{{ route('accounting.posting.backlog') }}">{{ __('لم يُرحَّل') }}</a>@endif</dd>
@endif
