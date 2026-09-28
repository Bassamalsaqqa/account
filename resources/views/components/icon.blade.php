@props(['name', 'class' => 'w-5 h-5'])

@php
$icons = [
    'store' => '<path d="M4 9h16M5 9v11h14V9M3 9l2-5h14l2 5M9 13v7M15 13v7"/>',
    'branch' => '<path d="M4 20h16M6 20V8h12v12M9 8V4h6v4M9 12h2M13 12h2M9 16h2M13 16h2"/>',
    'expand' => '<path d="m8 10 4-4 4 4M8 14l4 4 4-4"/>',
    'dashboard' => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
    'receipt' => '<path d="M6 3h12v18l-2-1.5L14 21l-2-1.5L10 21l-2-1.5L6 21V3Z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
    'quote' => '<path d="M6 3h12v18H6zM9 8h6M9 12h6M9 16h3"/><path d="M15 16h.01"/>',
    'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 20c.6-4 2.6-6 6-6s5.4 2 6 6"/><circle cx="17" cy="9" r="2"/><path d="M15 15c3 0 5 2 5.5 5"/>',
    'cart' => '<path d="M3 4h2l2 11h10l2-7H6"/><circle cx="9" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/>',
    'truck' => '<path d="M3 6h11v10H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
    'box' => '<path d="m12 3 8 4-8 4-8-4 8-4ZM4 7v10l8 4 8-4V7M12 11v10"/>',
    'expiry' => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M9 14l6 6M15 14l-6 6"/>',
    'bank' => '<path d="m3 9 9-5 9 5M5 10h14M6 10v8M10 10v8M14 10v8M18 10v8M4 20h16"/>',
    'check' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M8 15h3"/>',
    'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20V7"/>',
    'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1l2-1.5-2-3.5-2.5 1a7 7 0 0 0-1.8-1L14 3h-4l-.6 3a7 7 0 0 0-1.8 1l-2.5-1-2 3.5L5.1 11a7 7 0 0 0 0 2L3 14.5 5 18l2.5-1a7 7 0 0 0 1.8 1l.7 3h4l.6-3a7 7 0 0 0 1.8-1l2.5 1 2-3.5L18.9 13a7 7 0 0 0 .1-1Z"/>',
    'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/>',
    'bell' => '<path d="M6 9a6 6 0 0 1 12 0v5l2 3H4l2-3V9ZM10 20h4"/>',
    'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.8 2.8 0 1 1 4.5 2.2c-1.4 1-2 1.4-2 2.8M12 17h.01"/>',
    'file' => '<path d="M6 2h8l4 4v16H6zM14 2v5h5M9 13h6M9 17h6"/>',
    'phone' => '<path d="M6 3h4l1 5-2 1c1 4 3 6 6 7l1-2 5 1v4c0 1-1 2-2 2C10 21 3 14 3 5c0-1 1-2 3-2Z"/>',
    'pin' => '<path d="M12 21s6-5 6-11a6 6 0 1 0-12 0c0 6 6 11 6 11Z"/><circle cx="12" cy="10" r="2"/>',
    'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/>',
    'money' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M7 9H5v2M17 15h2v-2"/>',
    'percent' => '<path d="m6 18 12-12"/><circle cx="7" cy="7" r="2"/><circle cx="17" cy="17" r="2"/>',
    'shield' => '<path d="M12 3 5 6v5c0 5 3 8 7 10 4-2 7-5 7-10V6l-7-3Z"/>',
    'lock' => '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
    'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v6l4 2"/>',
    'number' => '<path d="M10 3 8 21M16 3l-2 18M4 9h16M3 15h16"/>',
    'print' => '<path d="M7 9V3h10v6M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2M7 14h10v7H7z"/>',
    'share' => '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8 11 7.5-4.5M8 13l7.5 4.5"/>',
    'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
    'chevron' => '<path d="m14 6-6 6 6 6"/>',
    'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m9 12 2 2 4-4"/>',
];
$svgPath = $icons[$name] ?? $icons['help'];
@endphp

<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
    {!! $svgPath !!}
</svg>
