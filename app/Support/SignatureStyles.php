<?php

namespace App\Support;

/**
 * The signature styles offered on the sign page: the signer's name written in one of these, as
 * an alternative to drawing it.
 *
 * The fonts are open-licence families (SIL Open Font License / Apache 2.0) from Google Fonts,
 * loaded by the signer's browser. The browser turns the chosen style into a picture, and that
 * picture is what is stored and printed on the CRF — the PDF never needs the font itself. A style
 * whose font cannot be loaded is simply not offered; drawing always works.
 */
class SignatureStyles
{
    public const DRAWN = 'drawn';

    public const TYPED = 'typed';

    /**
     * key => label, font family, weight, and how it is written: "upper" (capitals), "slant"
     * (leaning forward), "line" (underline | flourish drawn under the name).
     */
    public const STYLES = [
        'bold_signature' => ['label' => 'Bold Signature', 'font' => 'Kaushan Script', 'weight' => 400],
        'classic_script' => ['label' => 'Classic Script', 'font' => 'Great Vibes', 'weight' => 400],
        'all_caps_bold' => ['label' => 'All Caps (Bold)', 'font' => 'Oswald', 'weight' => 700, 'upper' => true],
        'modern_signature' => ['label' => 'Modern Signature', 'font' => 'Sacramento', 'weight' => 400],
        'thin_signature' => ['label' => 'Thin Signature', 'font' => 'Mrs Saint Delafield', 'weight' => 400],
        'brush_signature' => ['label' => 'Brush Signature', 'font' => 'Caveat Brush', 'weight' => 400],
        'underline_signature' => ['label' => 'Signature with Underline', 'font' => 'Allura', 'weight' => 400, 'line' => 'underline'],
        'stylish_signature' => ['label' => 'Stylish Signature', 'font' => 'Alex Brush', 'weight' => 400],
        'bold_sans_serif' => ['label' => 'Bold Sans Serif', 'font' => 'Montserrat', 'weight' => 800],
        'elegant_signature' => ['label' => 'Elegant Signature', 'font' => 'Pinyon Script', 'weight' => 400],
        'dynamic_signature' => ['label' => 'Dynamic Signature', 'font' => 'Mr Dafoe', 'weight' => 400, 'slant' => true],
        'handwritten_caps' => ['label' => 'Handwritten Caps', 'font' => 'Amatic SC', 'weight' => 700, 'upper' => true],
        'connected_script' => ['label' => 'Connected Script', 'font' => 'Dancing Script', 'weight' => 600],
        'serif_bold' => ['label' => 'Serif (Bold)', 'font' => 'Playfair Display', 'weight' => 800],
        'modern_sans_serif' => ['label' => 'Modern Sans Serif', 'font' => 'Poppins', 'weight' => 500],
        'flourish_signature' => ['label' => 'Flourish Signature', 'font' => 'Italianno', 'weight' => 400, 'line' => 'flourish'],
        'minimalist' => ['label' => 'Minimalist', 'font' => 'La Belle Aurore', 'weight' => 400],
        'marker_style' => ['label' => 'Marker Style', 'font' => 'Permanent Marker', 'weight' => 400],
        'clean_signature' => ['label' => 'Clean Signature', 'font' => 'Parisienne', 'weight' => 400],
        'heavy_bold_signature' => ['label' => 'Heavy Bold Signature', 'font' => 'Pacifico', 'weight' => 400],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::STYLES);
    }

    public static function label(?string $key): ?string
    {
        return self::STYLES[$key]['label'] ?? null;
    }

    /**
     * What the sign page needs to show and draw each style.
     *
     * @return list<array{key: string, label: string, font: string, weight: int, upper: bool, slant: bool, line: ?string, css: string}>
     */
    public static function forPage(): array
    {
        return collect(self::STYLES)->map(fn (array $style, string $key) => [
            'key' => $key,
            'label' => $style['label'],
            'font' => $style['font'],
            'weight' => $style['weight'],
            'upper' => $style['upper'] ?? false,
            'slant' => $style['slant'] ?? false,
            'line' => $style['line'] ?? null,
            // One request per font: a family the font service does not have then costs that style only.
            'css' => 'https://fonts.googleapis.com/css2?family='.str_replace(' ', '+', $style['font']).':wght@'.$style['weight'].'&display=swap',
        ])->values()->all();
    }
}
