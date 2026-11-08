<?php

namespace App\Support\Store;

/**
 * Storefront themes. Each one is a full design: its own header, home page,
 * product card, typography and palette (views in resources/views/store/themes/{key},
 * styles in public/store/themes/{key}.css). Shared pages read the theme's tokens.
 */
final class Themes
{
    public const DEFAULT = 'linen';

    public const ALL = [
        'linen' => [
            'name' => 'Linen',
            'summary' => 'Calm and modern. A clean everyday store that suits any clothing shop.',
            'best_for' => 'Everyday wear',
            'fonts' => 'family=Schibsted+Grotesk:wght@400;500;600;700;800',
            'accent' => '#2F4AA0',
            'palette' => ['#FAFAF7', '#1B1F2A', '#2F4AA0', '#E4E2DC'],
        ],
        'atelier' => [
            'name' => 'Atelier',
            'summary' => 'Editorial and spacious, like a fashion magazine. Big imagery, quiet chrome.',
            'best_for' => 'Premium & boutique',
            'fonts' => 'family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400&family=Jost:wght@300;400;500;600',
            'accent' => '#8A6A3F',
            'palette' => ['#FFFFFF', '#000000', '#8A6A3F', '#EEEBE6'],
        ],
        'noor' => [
            'name' => 'Noor',
            'summary' => 'Arched frames, emerald and gold. Made for lawn, kurtas and festive wear.',
            'best_for' => 'Eastern wear',
            'fonts' => 'family=Marcellus&family=Mulish:wght@400;500;600;700',
            'accent' => '#0B5D4B',
            'palette' => ['#FFFFFF', '#0B5D4B', '#B8913A', '#FBEFF2'],
        ],
        'kinetic' => [
            'name' => 'Kinetic',
            'summary' => 'Loud condensed type, hard edges and a scrolling ticker. Built for drops.',
            'best_for' => 'Streetwear & western',
            'fonts' => 'family=Anton&family=Archivo:wght@400;500;600;700;800',
            'accent' => '#1F3BFF',
            'palette' => ['#E9E9E6', '#000000', '#1F3BFF', '#FFFFFF'],
        ],
        'playground' => [
            'name' => 'Playground',
            'summary' => 'Round, bright and friendly with wavy edges and chunky buttons.',
            'best_for' => 'Kids wear',
            'fonts' => 'family=Fredoka:wght@400;500;600;700&family=Nunito:wght@400;600;700;800',
            'accent' => '#FF6F61',
            'palette' => ['#FFFFFF', '#2D2A5A', '#FF6F61', '#FFC531', '#3BA7F5'],
        ],
    ];

    public static function exists(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::ALL);
    }

    public static function get(string $key): array
    {
        return self::ALL[$key] ?? self::ALL[self::DEFAULT];
    }

    public static function fontsUrl(string $key): string
    {
        return 'https://fonts.googleapis.com/css2?'.self::get($key)['fonts'].'&display=swap';
    }
}
