{{-- Delete for drafts and unused master data (rules in RecordDeleteController and the database). --}}
@if(\App\Http\Controllers\Web\RecordDeleteController::offered($type, $model))
    <form method="post" action="{{ route('records.destroy', [$type, $model->id]) }}" class="inline" data-confirm="{{ __('حذف السجل نهائيًا؟') }}">@csrf @method('delete')
        <button class="btn ghost sm bad-text">{{ __('حذف') }}</button></form>
@endif
