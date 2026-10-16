{{-- «Import from Excel» on a screen whose records can be entered from a template (ImportController::forScreen). --}}
@auth
@php($imports = \App\Http\Controllers\Web\ImportController::forScreen(\Illuminate\Support\Facades\Route::currentRouteName(), auth()->user()))
@if($imports)
<details class="imp-drop">
    <summary class="btn ghost sm">⇪ {{ __('استيراد من Excel') }}</summary>
    <div class="imp-menu">
        @foreach($imports as $key => $def)
            <div class="imp-item">
                <strong>{{ __($def['label']) }}</strong>
                <span>
                    <a href="{{ route('imports.template', $key) }}">{{ __('تنزيل القالب') }}</a>
                    · <a href="{{ route('imports.show', $key) }}">{{ __('رفع ملف معبأ') }}</a>
                </span>
            </div>
        @endforeach
    </div>
</details>
@endif
@endauth
