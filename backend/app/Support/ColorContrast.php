<?php

namespace App\Support;

/**
 * A school picks its own brand colour (settings-branding.blade.php's
 * swatch grid / native picker), so any text rendered directly on top of
 * it can no longer assume a fixed dark ink reads well, a school that
 * picks a dark colour (navy, purple, black) with hardcoded dark text
 * becomes unreadable (user report 2026-07-11, a screenshot of a "Resume"
 * button with black text on a dark green background). Standard WCAG
 * relative luminance: pick black ink above the crossover point where
 * black and white contrast equally against the background, white below
 * it, so text stays readable against any colour a school could pick.
 */
class ColorContrast
{
    public static function inkFor(?string $hex, string $darkInk = '#241503', string $lightInk = '#FFFFFF'): string
    {
        $hex = ltrim((string) $hex, '#');
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return $darkInk;
        }

        $channel = function (int $value) {
            $value /= 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        $r = $channel((int) hexdec(substr($hex, 0, 2)));
        $g = $channel((int) hexdec(substr($hex, 2, 2)));
        $b = $channel((int) hexdec(substr($hex, 4, 2)));

        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        // Crossover point where a background contrasts black and white
        // equally (WCAG relative luminance of sRGB gray at that point).
        return $luminance > 0.179 ? $darkInk : $lightInk;
    }
}
