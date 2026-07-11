<?php

namespace App\Support;

/**
 * A school picks its own brand colour (settings-branding.blade.php's
 * swatch grid / native picker), so any text rendered directly on top of
 * it can no longer assume a fixed dark ink reads well, a school that
 * picks a dark colour (navy, purple, black) with hardcoded dark text
 * becomes unreadable (user report 2026-07-11, a screenshot of a "Resume"
 * button with black text on a dark green background).
 *
 * The plain WCAG "equal contrast" crossover point (luminance 0.179) is
 * the wrong threshold here: by that standard, black text on a mid
 * saturated green like #12A97B technically has a higher contrast ratio
 * than white text does (5.9:1 vs 3.0:1), yet the user flagged that exact
 * green button as unreadable. Saturated mid tones read as "a colour", not
 * "light enough for dark text", regardless of what the contrast ratio
 * says. The threshold below is picked from two real data points instead:
 * the default brand gold #E8A33D (luminance 0.437, dark text keeps
 * working fine, unchanged) must stay above it, the reported green
 * #12A97B (luminance 0.299) must fall below it. 0.4 sits between them —
 * effectively "only a genuinely light/pale colour gets dark text, every
 * other colour gets white", matching "black only works fine on white
 * background" rather than a strict contrast-ratio reading.
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

        return $luminance > 0.4 ? $darkInk : $lightInk;
    }
}
