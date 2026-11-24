@props(['name'])
<svg class="endu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.72" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('activity')
            <path d="M2 12h5l3-8 4 16 3-8h5"/>
            @break
        @case('arrow')
            <path d="M3 12h17m-6-6 6 6-6 6"/>
            @break
        @case('bell')
            <path d="M18 8a6 6 0 0 0-12 0c0 6-3 7-3 8h18c0-1-3-2-3-8m-8 12a2 2 0 0 0 4 0"/>
            @break
        @case('box')
            <path d="m12 2 9 5v10l-9 5-9-5V7zm0 10V22m9-15-9 5-9-5m4.5-2.5 9 5"/>
            @break
        @case('calendar')
            <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4m8-4v4"/>
            @break
        @case('cart')
            <circle cx="8" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M2 3h3l3 13h10l3-9H6"/>
            @break
        @case('chart')
            <path d="M3 3v17h18M8 16v-4m5 4V8m5 8V5"/>
            @break
        @case('check')
            <path d="m5 12 4 4L19 6"/>
            @break
        @case('chev')
            <path d="m9 18 6-6-6-6"/>
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
            @break
        @case('close')
            <path d="m6 6 12 12M18 6 6 18"/>
            @break
        @case('doc')
            <path d="M13 2H5v20h14V8zm0 0v6h6M8 12h8m-8 4h8"/>
            @break
        @case('grid')
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
            @break
        @case('history')
            <path d="M3 12a9 9 0 1 1 2.5 6.2M3 12V6m0 6h6m3-5v5l3 2"/>
            @break
        @case('home')
            <path d="M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z"/>
            @break
        @case('key')
            <circle cx="8" cy="15" r="4"/><path d="m11 12 9-9m-3 3 3 3m-6 0 2 2"/>
            @break
        @case('menu')
            <path d="M3 6h18M3 12h18M3 18h18"/>
            @break
        @case('message')
            <path d="M21 11a8 8 0 0 1-8 8H6l-4 3v-7a8 8 0 0 1 0-4 8 8 0 0 1 8-8h3a8 8 0 0 1 8 8Z"/><path d="M7 10h8m-8 4h5"/>
            @break
        @case('percent')
            <path d="M5 19 19 5"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>
            @break
        @case('phone')
            <rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 5h4m-3 13h2"/>
            @break
        @case('plug')
            <path d="M8 3v5m8-5v5M6 8h12v2a6 6 0 0 1-6 6v5m-3 0h6"/>
            @break
        @case('plus')
            <path d="M12 5v14m-7-7h14"/>
            @break
        @case('repair')
            <path d="M14.7 6.3a4 4 0 0 0-5.6 5.5L4 17l3 3 5.2-5.2a4 4 0 0 0 5.5-5.5l-2.3 2.3-2.6-2.6z"/>
            @break
        @case('search')
            <circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>
            @break
        @case('shield')
            <path d="M12 22s7-3 7-8V5l-7-3-7 3v9c0 5 7 8 7 8Z"/><path d="m9 12 2 2 4-4"/>
            @break
        @case('store')
            <path d="M3 10h18l-2-6H5zM4 10v10h16V10m-10 10v-5h4v5"/><path d="M9 4 8 10m7-6 1 6"/>
            @break
        @case('tag')
            <path d="M20 13 12 21l-9-9V4h8l9 9Z"/><circle cx="7" cy="8" r="1"/>
            @break
        @case('users')
            <circle cx="9" cy="8" r="3"/><path d="M2 20v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2m1-14a3 3 0 0 1 0 6m2 3a4 4 0 0 1 3 4v1"/>
            @break
        @case('wallet')
            <path d="M19 7V5H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H5a2 2 0 0 1-2-2V7m18 5h-5a2 2 0 0 0 0 4h5"/>
            @break
    @endswitch
</svg>
