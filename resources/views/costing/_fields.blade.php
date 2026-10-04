{{-- Effective date, source, estimated flag and notes: common to every cost record form. --}}
<div class="grid g3">
    <div class="field"><label>{{ __('ساري من *') }}</label><input type="date" name="effective_from" value="{{ old('effective_from', $r->effective_from?->format('Y-m-d')) }}" required></div>
    <div class="field" style="grid-column: span 2"><label>{{ __('مصدر الأرقام *') }}</label><input name="source" value="{{ old('source', $r->source) }}" required placeholder="{{ __('مثال: عقد العمل، كشف التأمينات، فاتورة الشراء، فاتورة الكهرباء') }}"></div>
</div>
<label class="perm-item"><input type="checkbox" name="estimated" value="1" @checked(old('estimated', $r->estimated))> {{ __('أرقام تقديرية (لم تُثبت بمستند بعد)') }}</label>
<div class="field"><label>{{ __('ملاحظات') }}</label><textarea name="notes">{{ old('notes', $r->notes) }}</textarea></div>
