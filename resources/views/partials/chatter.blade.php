{{-- Activity feed from the audit log. Params: $activity (Collection) --}}
@if(auth()->user()->hasPermission('audit.view'))
<div class="card chatter">
    <h2>{{ __('سجل النشاط') }}</h2>
    @forelse($activity as $a)
        <div class="chat-item">
            <span @class(['chat-av', 'sys' => $a['system']])>{{ mb_substr($a['user'], 0, 1) }}</span>
            <div>
                <div class="chat-head"><strong>{{ $a['user'] }}</strong><time datetime="{{ $a['at']->toIso8601String() }}">{{ $a['at']->format('Y-m-d H:i') }}</time></div>
                <div class="chat-body">
                    {{ $a['summary'] }}
                    @if($a['changes'])
                        <ul>
                            @foreach($a['changes'] as [$field, $from, $to])
                                <li>{{ $field }}: <del>{{ $from }}</del> {{ app()->getLocale() === 'ar' ? '←' : '→' }} {{ $to }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <p class="muted">{{ __('لا يوجد نشاط مسجّل.') }}</p>
    @endforelse
</div>
@endif
