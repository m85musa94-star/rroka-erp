{{-- Latest studio images of a customer or project. Params: $scope ['client_id' => id] or ['project_id' => id] --}}
@php($u = auth()->user())
@if($u->hasPermission('studio.view'))
    @php($images = \App\Models\StudioAsset::where($scope)->orderByDesc('id')->limit(12)->get())
    @php($count = \App\Models\StudioAsset::where($scope)->count())
    <div class="card">
        <div class="rec-bar" style="margin:0 0 10px;padding:0;border:0">
            <h2 style="margin:0">{{ __('الصور') }} <span class="grp-count">({{ $count }})</span></h2>
            <div class="actions">
                @if($count > 0)<a class="btn ghost sm" href="{{ route('studio.index', $scope) }}">{{ __('عرض الكل في الاستوديو') }}</a>@endif
                @if($u->hasPermission('studio.manage'))<a class="btn ghost sm" href="{{ route('studio.create', $scope) }}">{{ __('رفع صور') }}</a>@endif
            </div>
        </div>
        @if($images->isEmpty())
            <p class="muted">{{ __('لا توجد صور بعد.') }}</p>
        @else
            <div class="studio-grid" style="grid-template-columns:repeat(auto-fill,minmax(130px,1fr));margin:0">
                @foreach($images as $a)
                    <a class="studio-card" href="{{ route('studio.show', $a) }}" title="{{ $a->title }}">
                        <span class="studio-thumb"><img src="{{ $a->url() }}" alt="{{ $a->title }}" loading="lazy"></span>
                        <span class="studio-cap"><span class="badge st-{{ $a->category }}">{{ __("rroka.studio_category.$a->category") }}</span></span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
@endif
