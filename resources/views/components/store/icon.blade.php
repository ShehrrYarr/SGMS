@props(['name', 'size' => null])
@php
    // Small inline SVG set (24px grid, 1.75 stroke). Inline icons keep the store fast: no icon font download.
    $paths = [
        'bag' => '<path d="M6 7h12l1 13H5L6 7Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/>',
        'heart' => '<path d="M12 20s-7-4.4-9-8.6C1.6 8.3 3.4 5 6.7 5c2 0 3.3 1.1 4.3 2.5C12 6.1 13.3 5 15.3 5c3.3 0 5.1 3.3 3.7 6.4C19 15.6 12 20 12 20Z"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.3-4.3"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 20c1.5-3.5 4.4-5 8-5s6.5 1.5 8 5"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'chevron-left' => '<path d="m15 5-7 7 7 7"/>',
        'chevron-right' => '<path d="m9 5 7 7-7 7"/>',
        'chevron-down' => '<path d="m5 9 7 7 7-7"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'minus' => '<path d="M5 12h14"/>',
        'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'truck' => '<path d="M3 6h11v10H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/>',
        'return' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
        'shield' => '<path d="M12 3 5 6v5c0 4.4 2.9 8.3 7 9.5 4.1-1.2 7-5.1 7-9.5V6l-7-3Z"/><path d="m9 12 2 2 4-4"/>',
        'filter' => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
        'check' => '<path d="m5 12 5 5 9-10"/>',
        'star' => '<path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9L12 3.5Z"/>',
        'ruler' => '<path d="M3 15.5 15.5 3 21 8.5 8.5 21 3 15.5Z"/><path d="m7 12 2 2M10 9l2 2M13 6l2 2"/>',
        'package' => '<path d="M4 8l8-4 8 4v8l-8 4-8-4V8Z"/><path d="m4 8 8 4 8-4M12 12v8"/>',
        'pin' => '<path d="M12 21s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12Z"/><circle cx="12" cy="9" r="2.5"/>',
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h0"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'cash' => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9v0M18 15v0"/>',
        'bank' => '<path d="M3 10 12 4l9 6M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18"/>',
        'wallet' => '<path d="M4 7a2 2 0 0 1 2-2h12v4"/><rect x="3" y="8" width="18" height="12" rx="2"/><circle cx="16.5" cy="14" r="1.2"/>',
        'tag' => '<path d="M3 12V4h8l10 10-8 8L3 12Z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
        'whatsapp' => '<path d="M4 20l1.2-3.6A8.5 8.5 0 1 1 8 19.1L4 20Z"/><path d="M9 8.5c0 3.5 3 6.5 6.5 6.5l1-1.6-2-1-1 .9a5 5 0 0 1-2.3-2.3l.9-1-1-2L9 8.5Z"/>',
        'instagram' => '<rect x="3.5" y="3.5" width="17" height="17" rx="4.5"/><circle cx="12" cy="12" r="4"/><path d="M17.3 6.7h0"/>',
        'facebook' => '<path d="M14 8h3V4h-3a4 4 0 0 0-4 4v3H7v4h3v6h4v-6h3l1-4h-4V8Z"/>',
        'tiktok' => '<path d="M14 3v11.5a3.5 3.5 0 1 1-3-3.46"/><path d="M14 3c.5 2.8 2.3 4.5 5 4.8"/>',
        'arrow-left' => '<path d="M19 12H5m6-6-6 6 6 6"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.8"/>',
    ];
    $filled = in_array($name, ['star'], true);
@endphp
<svg {{ $attributes->merge(['class' => 'sf-icon', 'aria-hidden' => 'true']) }} viewBox="0 0 24 24" @if ($size) width="{{ $size }}" height="{{ $size }}" @endif fill="{{ $filled ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" focusable="false">{!! $paths[$name] ?? '' !!}</svg>
