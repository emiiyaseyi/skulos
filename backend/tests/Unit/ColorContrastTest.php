<?php

namespace Tests\Unit;

use App\Support\ColorContrast;
use Tests\TestCase;

/**
 * A school picks its own brand colour, so button/badge text on top of it
 * can no longer assume a fixed dark ink always reads well (user report
 * 2026-07-11: a dark green button with black text). ColorContrast picks
 * black or white based on the background's actual WCAG luminance.
 */
class ColorContrastTest extends TestCase
{
    public function test_light_colours_get_dark_ink(): void
    {
        $this->assertSame('#241503', ColorContrast::inkFor('#E8A33D')); // the default gold
        $this->assertSame('#241503', ColorContrast::inkFor('#FFFFFF'));
        $this->assertSame('#241503', ColorContrast::inkFor('#F5F500'));
    }

    public function test_dark_colours_get_light_ink(): void
    {
        $this->assertSame('#FFFFFF', ColorContrast::inkFor('#2E5FDB')); // a dark blue swatch
        $this->assertSame('#FFFFFF', ColorContrast::inkFor('#000000'));
        $this->assertSame('#FFFFFF', ColorContrast::inkFor('#5B4636')); // a dark brown swatch
        $this->assertSame('#FFFFFF', ColorContrast::inkFor('#6C4FE0'));
    }

    /**
     * The exact colour from the user's "not fixed" follow up screenshot:
     * a mid saturated green where black text technically has a *higher*
     * WCAG contrast ratio than white (5.9:1 vs 3.0:1), yet reads as
     * unreadable in practice, this is why the threshold is 0.4, not the
     * plain WCAG crossover of 0.179.
     */
    public function test_a_mid_saturated_green_that_technically_passes_wcag_with_black_still_gets_white(): void
    {
        $this->assertSame('#FFFFFF', ColorContrast::inkFor('#12A97B'));
    }

    public function test_an_invalid_or_missing_colour_falls_back_to_dark_ink(): void
    {
        $this->assertSame('#241503', ColorContrast::inkFor(null));
        $this->assertSame('#241503', ColorContrast::inkFor('not-a-colour'));
        $this->assertSame('#241503', ColorContrast::inkFor('#ZZZZZZ'));
    }
}
