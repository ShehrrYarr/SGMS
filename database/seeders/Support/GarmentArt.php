<?php

namespace Database\Seeders\Support;

/**
 * Flat SVG illustrations for the demo store: one per product colour, home
 * page banners and size charts. Lets the demo look real without shipping
 * photographs. Shops replace them with their own photos.
 */
class GarmentArt
{
    /** Garment silhouettes on a 600 x 750 canvas. */
    protected const SHAPES = [
        'shirt' => [
            'body' => 'M262 118 Q300 138 338 118 L420 150 Q452 300 478 468 L428 482 Q412 380 398 292 L400 652 L200 652 L202 292 Q188 380 172 482 L122 468 Q148 300 180 150 Z',
            'detail' => '<path d="M262 118 L300 188 L282 116 Z M338 118 L300 188 L318 116 Z" fill="{dark}"/><path d="M300 190 V650" stroke="{dark}" stroke-width="3"/><g fill="{button}">{buttons}</g><rect x="222" y="250" width="56" height="58" rx="4" fill="none" stroke="{dark}" stroke-width="3"/><path d="M122 468 L172 482 M428 482 L478 468" stroke="{dark}" stroke-width="10"/>',
        ],
        'polo' => [
            'body' => 'M262 120 Q300 140 338 120 L430 156 L478 288 L414 312 L400 262 L402 652 L198 652 L200 262 L186 312 L122 288 L170 156 Z',
            'detail' => '<path d="M262 120 L300 172 L276 116 Z M338 120 L300 172 L324 116 Z" fill="{dark}"/><path d="M300 172 V286" stroke="{dark}" stroke-width="3"/><g fill="{button}"><circle cx="300" cy="206" r="5"/><circle cx="300" cy="248" r="5"/></g><path d="M122 288 L186 312 M414 312 L478 288" stroke="{dark}" stroke-width="9"/><path d="M198 640 H402" stroke="{dark}" stroke-width="8"/>',
        ],
        'tee' => [
            'body' => 'M256 120 Q300 160 344 120 L430 150 L484 282 L414 306 L400 258 L402 652 L198 652 L200 258 L186 306 L116 282 L170 150 Z',
            'detail' => '<path d="M256 120 Q300 160 344 120" fill="none" stroke="{dark}" stroke-width="10"/><path d="M116 282 L186 306 M414 306 L484 282" stroke="{dark}" stroke-width="4"/>',
        ],
        'hoodie' => [
            'body' => 'M232 132 Q236 64 300 58 Q364 64 368 132 L432 160 Q466 320 486 488 L432 500 Q418 400 404 300 L404 640 L196 640 L196 300 Q182 400 168 500 L114 488 Q134 320 168 160 Z',
            'detail' => '<path d="M248 136 Q252 92 300 88 Q348 92 352 136 Q300 168 248 136 Z" fill="{dark}"/><path d="M286 160 V236 M314 160 V236" stroke="{light}" stroke-width="5" stroke-linecap="round"/><path d="M226 470 H374 L392 560 H208 Z" fill="none" stroke="{dark}" stroke-width="4"/><path d="M196 620 H404 M114 488 L168 500 M432 500 L486 488" stroke="{dark}" stroke-width="16"/>',
        ],
        'jeans' => [
            'body' => 'M198 108 H402 L414 662 H318 L300 310 L282 662 H186 Z',
            'detail' => '<rect x="198" y="108" width="204" height="34" fill="{dark}"/><path d="M300 142 V300" stroke="{dark}" stroke-width="4"/><path d="M212 150 Q242 186 270 150 M330 150 Q358 186 388 150" fill="none" stroke="{stitch}" stroke-width="3" stroke-dasharray="7 6"/><path d="M186 640 H282 M318 640 H414" stroke="{stitch}" stroke-width="3" stroke-dasharray="7 6"/><g fill="{dark}"><rect x="228" y="104" width="10" height="44"/><rect x="292" y="104" width="10" height="44"/><rect x="362" y="104" width="10" height="44"/></g>',
        ],
        'chinos' => [
            'body' => 'M200 108 H400 L410 662 H316 L300 300 L284 662 H190 Z',
            'detail' => '<rect x="200" y="108" width="200" height="32" fill="{dark}"/><path d="M300 140 V296" stroke="{dark}" stroke-width="3"/><path d="M214 150 L246 214 M386 150 L354 214" stroke="{dark}" stroke-width="3"/><path d="M190 646 H284 M316 646 H410" stroke="{dark}" stroke-width="6"/><circle cx="300" cy="124" r="5" fill="{button}"/>',
        ],
        'joggers' => [
            'body' => 'M196 108 H404 L398 600 L392 652 H322 L314 600 L300 296 L286 600 L278 652 H208 L202 600 Z',
            'detail' => '<rect x="196" y="108" width="208" height="40" rx="6" fill="{dark}"/><path d="M290 148 Q284 186 278 200 M310 148 Q316 186 322 200" stroke="{light}" stroke-width="4" fill="none" stroke-linecap="round"/><rect x="206" y="604" width="74" height="48" rx="6" fill="{dark}"/><rect x="320" y="604" width="74" height="48" rx="6" fill="{dark}"/><rect x="352" y="300" width="40" height="64" rx="5" fill="none" stroke="{dark}" stroke-width="3"/>',
        ],
        'kurta' => [
            'body' => 'M266 106 Q300 116 334 106 L414 140 Q440 300 458 460 L410 470 Q400 380 392 292 L404 690 L322 690 L316 560 L300 560 L284 560 L278 690 L196 690 L208 292 Q200 380 190 470 L142 460 Q160 300 186 140 Z',
            'detail' => '<path d="M266 106 L300 100 L334 106 L334 92 Q300 84 266 92 Z" fill="{dark}"/><path d="M300 108 V300" stroke="{dark}" stroke-width="3"/><g fill="none" stroke="{gold}" stroke-width="3" stroke-dasharray="2 7" stroke-linecap="round"><path d="M282 112 V300 M318 112 V300"/><path d="M266 120 Q300 150 334 120"/><path d="M196 676 H278 M322 676 H404"/><path d="M142 446 L190 456 M410 456 L458 446"/></g><g fill="{gold}"><circle cx="300" cy="140" r="4"/><circle cx="300" cy="190" r="4"/><circle cx="300" cy="240" r="4"/></g>',
        ],
    ];

    public static function garment(string $type, string $hex): string
    {
        $shape = self::SHAPES[$type] ?? self::SHAPES['tee'];
        $light = self::isLight($hex);
        $bg = $light ? '#E6E8EC' : '#EEF0F3';
        $dark = self::shade($hex, $light ? -0.16 : -0.28);
        $buttons = implode('', array_map(fn ($y) => '<circle cx="300" cy="'.$y.'" r="5"/>', [228, 290, 352, 414, 476, 538]));

        $detail = strtr($shape['detail'], [
            '{dark}' => $dark,
            '{light}' => $light ? '#9aa1ab' : '#f4f4f4',
            '{button}' => $light ? '#b9bec6' : self::shade($hex, 0.45),
            '{stitch}' => '#d9a441',
            '{gold}' => '#c9a24a',
            '{buttons}' => $buttons,
        ]);

        $outline = $light ? ' stroke="#c9cdd3" stroke-width="2"' : '';

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 750" width="600" height="750">
  <defs>
    <linearGradient id="shade" x1="0" x2="1" y1="0" y2="0">
      <stop offset="0" stop-color="#fff" stop-opacity=".16"/><stop offset=".55" stop-color="#fff" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".14"/>
    </linearGradient>
    <radialGradient id="floor" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="#000" stop-opacity=".16"/><stop offset="1" stop-color="#000" stop-opacity="0"/></radialGradient>
  </defs>
  <rect width="600" height="750" fill="{$bg}"/>
  <ellipse cx="300" cy="704" rx="190" ry="18" fill="url(#floor)"/>
  <path d="{$shape['body']}" fill="{$hex}"{$outline}/>
  {$detail}
  <path d="{$shape['body']}" fill="url(#shade)"/>
</svg>
SVG;
    }

    /**
     * Wide banner (1600 x 900): flat colour field, two large shapes and a row
     * of garments. No text; the theme renders the heading.
     *
     * @param  array<int, array{0: string, 1: string}>  $garments  [type, hex]
     */
    public static function banner(string $bg, string $shapeA, string $shapeB, array $garments): string
    {
        $count = count($garments);
        $items = '';

        foreach ($garments as $i => [$type, $hex]) {
            $shape = self::SHAPES[$type];
            $x = 1600 - 140 - ($count - $i) * 330;
            $y = 140 + ($i % 2) * 40;
            $dark = self::shade($hex, self::isLight($hex) ? -0.16 : -0.28);
            $detail = strtr($shape['detail'], [
                '{dark}' => $dark, '{light}' => '#f4f4f4', '{button}' => self::shade($hex, 0.45),
                '{stitch}' => '#d9a441', '{gold}' => '#c9a24a',
                '{buttons}' => implode('', array_map(fn ($y) => '<circle cx="300" cy="'.$y.'" r="5"/>', [228, 290, 352, 414, 476, 538])),
            ]);
            $items .= "<g transform=\"translate({$x} {$y}) scale(.86)\"><ellipse cx=\"300\" cy=\"704\" rx=\"170\" ry=\"16\" fill=\"#000\" opacity=\".12\"/><path d=\"{$shape['body']}\" fill=\"{$hex}\"/>{$detail}<path d=\"{$shape['body']}\" fill=\"url(#bshade)\"/></g>";
        }

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 900" width="1600" height="900">
  <defs>
    <linearGradient id="bshade" x1="0" x2="1"><stop offset="0" stop-color="#fff" stop-opacity=".16"/><stop offset=".55" stop-color="#fff" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".14"/></linearGradient>
  </defs>
  <rect width="1600" height="900" fill="{$bg}"/>
  <circle cx="1240" cy="420" r="380" fill="{$shapeA}"/>
  <rect x="-120" y="560" width="760" height="460" rx="230" fill="{$shapeB}" transform="rotate(-12 260 790)"/>
  {$items}
</svg>
SVG;
    }

    /** @param  array<int, array<int, string>>  $rows  first row is the header */
    public static function sizeChart(string $title, array $rows, string $note = 'All measurements in inches, taken flat.'): string
    {
        $cols = count($rows[0]);
        $width = 760;
        $cell = ($width - 80) / $cols;
        $height = 150 + count($rows) * 54 + 40;
        $out = '';

        foreach ($rows as $r => $row) {
            $y = 120 + $r * 54;
            $out .= $r === 0
                ? "<rect x=\"40\" y=\"{$y}\" width=\"".($width - 80)."\" height=\"54\" fill=\"#1b1f2a\"/>"
                : ($r % 2 === 0 ? "<rect x=\"40\" y=\"{$y}\" width=\"".($width - 80)."\" height=\"54\" fill=\"#f3f4f6\"/>" : '');

            foreach ($row as $c => $value) {
                $x = 40 + $c * $cell + $cell / 2;
                $fill = $r === 0 ? '#ffffff' : '#1b1f2a';
                $weight = $r === 0 || $c === 0 ? '700' : '400';
                $out .= "<text x=\"{$x}\" y=\"".($y + 34)."\" text-anchor=\"middle\" font-size=\"20\" font-weight=\"{$weight}\" fill=\"{$fill}\">".htmlspecialchars($value).'</text>';
            }
        }

        $noteY = $height - 30;

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$width} {$height}" width="{$width}" height="{$height}" font-family="Arial, Helvetica, sans-serif">
  <rect width="{$width}" height="{$height}" fill="#ffffff"/>
  <text x="40" y="72" font-size="30" font-weight="700" fill="#1b1f2a">{$title}</text>
  {$out}
  <text x="40" y="{$noteY}" font-size="17" fill="#646a76">{$note}</text>
</svg>
SVG;
    }

    protected static function isLight(string $hex): bool
    {
        [$r, $g, $b] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 180;
    }

    /** Lighten (amount > 0) or darken (amount < 0) a hex colour. */
    protected static function shade(string $hex, float $amount): string
    {
        $channels = array_map('hexdec', str_split(ltrim($hex, '#'), 2));

        return '#'.implode('', array_map(function ($c) use ($amount) {
            $v = $amount < 0 ? $c * (1 + $amount) : $c + (255 - $c) * $amount;

            return str_pad(dechex((int) round(max(0, min(255, $v)))), 2, '0', STR_PAD_LEFT);
        }, $channels));
    }
}
