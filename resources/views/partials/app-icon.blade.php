{{-- Flat, overlapping geometric app icons (original drawings). 64×64 viewBox. --}}
@php($s = $size ?? 64)
<svg width="{{ $s }}" height="{{ $s }}" viewBox="0 0 64 64" aria-hidden="true" class="app-svg">
@switch($key)
    @case('clients')
        <circle cx="24" cy="20" r="9" fill="#8E5486"/>
        <circle cx="42" cy="24" r="7" fill="#5FD0BD"/>
        <path d="M8 50c0-10 7-17 16-17s16 7 16 17z" fill="#8E5486"/>
        <path d="M28 52c1-9 7-15 14-15s13 6 14 15z" fill="#5FD0BD" opacity=".9"/>
        @break
    @case('quotations')
        <rect x="14" y="8" width="32" height="44" rx="4" fill="#F2BD5B"/>
        <rect x="20" y="18" width="20" height="4" rx="2" fill="#8E5486"/>
        <rect x="20" y="27" width="14" height="4" rx="2" fill="#8E5486"/>
        <rect x="20" y="36" width="17" height="4" rx="2" fill="#8E5486"/>
        <circle cx="46" cy="46" r="11" fill="#5FD0BD" opacity=".95"/>
        <path d="M41 46l4 4 7-8" stroke="#fff" stroke-width="3.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        @break
    @case('projects')
        <rect x="8" y="14" width="48" height="38" rx="5" fill="#2A5F7E"/>
        <rect x="8" y="14" width="48" height="10" rx="5" fill="#5AB2F2"/>
        <rect x="15" y="30" width="22" height="6" rx="3" fill="#F2BD5B"/>
        <rect x="15" y="40" width="32" height="6" rx="3" fill="#5FD0BD"/>
        <rect x="24" y="8" width="16" height="8" rx="3" fill="#E8833A"/>
        @break
    @case('designs')
        <path d="M10 50L40 12l10 8-30 38z" fill="#5AB2F2"/>
        <path d="M10 50l4 8 6-0.5z" fill="#2A5F7E"/>
        <rect x="30" y="34" width="26" height="18" rx="3" fill="#EE8E8C" opacity=".9"/>
        <path d="M36 34v6M42 34v8M48 34v6" stroke="#8E5486" stroke-width="2.5"/>
        @break
    @case('inventory')
        <path d="M32 8l22 12v24L32 56 10 44V20z" fill="#8E5486"/>
        <path d="M32 8l22 12-22 12-22-12z" fill="#F2BD5B"/>
        <path d="M32 32v24l22-12V20z" fill="#E8833A" opacity=".85"/>
        @break
    @case('production')
        <circle cx="26" cy="36" r="16" fill="#5FD0BD"/>
        <circle cx="26" cy="36" r="6" fill="#fff"/>
        <path d="M26 16v6M26 50v6M6 36h6M40 36h6" stroke="#5FD0BD" stroke-width="6" stroke-linecap="round"/>
        <circle cx="46" cy="18" r="10" fill="#2A5F7E" opacity=".9"/>
        <circle cx="46" cy="18" r="3.5" fill="#fff"/>
        @break
    @case('quality')
        <path d="M32 6l20 8v16c0 13-9 22-20 27C21 52 12 43 12 30V14z" fill="#5AB2F2"/>
        <path d="M32 6l20 8v16c0 13-9 22-20 27z" fill="#2A5F7E" opacity=".55"/>
        <path d="M23 31l6 6 12-13" stroke="#fff" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        @break
    @case('installation')
        <path d="M8 30L32 10l24 20v24H8z" fill="#F2BD5B"/>
        <rect x="26" y="36" width="12" height="18" fill="#E8833A"/>
        <path d="M44 22l12 12-8 8-12-12z" fill="#8E5486" opacity=".9"/>
        @break
    @case('rates')
        <rect x="12" y="8" width="40" height="48" rx="6" fill="#8E5486"/>
        <rect x="18" y="14" width="28" height="10" rx="2" fill="#5FD0BD"/>
        <circle cx="23" cy="33" r="3.5" fill="#F2BD5B"/><circle cx="32" cy="33" r="3.5" fill="#F2BD5B"/><circle cx="41" cy="33" r="3.5" fill="#F2BD5B"/>
        <circle cx="23" cy="44" r="3.5" fill="#F2BD5B"/><circle cx="32" cy="44" r="3.5" fill="#F2BD5B"/>
        <rect x="37.5" y="40.5" width="7" height="7" rx="2" fill="#E8833A"/>
        @break
    @case('daftra')
        <rect x="8" y="20" width="22" height="24" rx="5" fill="#2A5F7E"/>
        <rect x="34" y="20" width="22" height="24" rx="5" fill="#5FD0BD"/>
        <path d="M24 32h16" stroke="#F2BD5B" stroke-width="6" stroke-linecap="round"/>
        @break
    @case('employees')
        <rect x="8" y="12" width="48" height="40" rx="6" fill="#5AB2F2"/>
        <circle cx="24" cy="28" r="7" fill="#fff"/>
        <path d="M13 46c1-7 5-11 11-11s10 4 11 11z" fill="#fff"/>
        <rect x="38" y="24" width="12" height="4" rx="2" fill="#2A5F7E"/>
        <rect x="38" y="33" width="9" height="4" rx="2" fill="#2A5F7E"/>
        <rect x="26" y="6" width="12" height="10" rx="3" fill="#E8833A"/>
        @break
    @case('users')
        <circle cx="32" cy="22" r="11" fill="#E8833A"/>
        <path d="M12 54c0-12 9-20 20-20s20 8 20 20z" fill="#F2BD5B"/>
        <circle cx="50" cy="16" r="6" fill="#5FD0BD"/>
        @break
    @case('roles')
        <circle cx="24" cy="26" r="14" fill="#5FD0BD"/>
        <circle cx="24" cy="26" r="5" fill="#fff"/>
        <path d="M34 34l20 20M46 46l-5 5M51 51l-4 4" stroke="#2A5F7E" stroke-width="6" stroke-linecap="round"/>
        @break
    @case('studio')
        <rect x="8" y="14" width="48" height="38" rx="6" fill="#2A5F7E"/>
        <circle cx="22" cy="26" r="5" fill="#F2BD5B"/>
        <path d="M8 46l14-14 10 10 8-7 16 13v0a6 6 0 0 1-6 4H14a6 6 0 0 1-6-6z" fill="#5FD0BD"/>
        <rect x="22" y="8" width="20" height="8" rx="3" fill="#E8833A"/>
        @break
    @case('reports')
        <rect x="10" y="30" width="10" height="24" rx="3" fill="#5FD0BD"/>
        <rect x="27" y="16" width="10" height="38" rx="3" fill="#8E5486"/>
        <rect x="44" y="24" width="10" height="30" rx="3" fill="#F2BD5B"/>
        <path d="M8 22L26 10l14 8 16-10" stroke="#E8833A" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        @break
    @case('home')
        <rect x="8" y="8" width="20" height="20" rx="5" fill="currentColor"/>
        <rect x="36" y="8" width="20" height="20" rx="5" fill="currentColor"/>
        <rect x="8" y="36" width="20" height="20" rx="5" fill="currentColor"/>
        <rect x="36" y="36" width="20" height="20" rx="5" fill="currentColor"/>
        @break
@endswitch
</svg>
