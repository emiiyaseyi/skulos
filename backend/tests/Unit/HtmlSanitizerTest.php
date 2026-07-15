<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * The sanitizer is the stored-XSS boundary for rich-text messages shown to
 * other users, so it is tested against real attack vectors, not just the
 * happy path.
 */
class HtmlSanitizerTest extends TestCase
{
    public function test_it_keeps_allowed_formatting(): void
    {
        $html = '<p>Hello <strong>bold</strong> <em>italic</em> <u>under</u> <mark>hi</mark></p>'
            .'<ul><li>one</li><li>two</li></ul>'
            .'<ol><li>a</li></ol>'
            .'<div style="text-align:center">centered</div>';

        $out = HtmlSanitizer::clean($html);

        $this->assertStringContainsString('<strong>bold</strong>', $out);
        $this->assertStringContainsString('<em>italic</em>', $out);
        $this->assertStringContainsString('<u>under</u>', $out);
        $this->assertStringContainsString('<mark>hi</mark>', $out);
        $this->assertStringContainsString('<li>one</li>', $out);
        $this->assertStringContainsString('text-align:center', $out);
    }

    public function test_it_keeps_a_validated_highlight_colour(): void
    {
        $out = HtmlSanitizer::clean('<span style="background-color: rgb(255, 255, 0)">highlighted</span>');
        $this->assertStringContainsString('background-color:rgb(255, 255, 0)', $out);
        $this->assertStringContainsString('highlighted', $out);
    }

    public function test_it_strips_a_script_tag_entirely(): void
    {
        $out = HtmlSanitizer::clean('<p>safe</p><script>alert(document.cookie)</script>');
        $this->assertStringContainsString('safe', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function test_it_strips_event_handler_attributes(): void
    {
        $out = HtmlSanitizer::clean('<p onclick="steal()" onmouseover="x()">click</p>');
        $this->assertStringContainsString('click', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('onmouseover', $out);
        $this->assertStringNotContainsString('steal', $out);
    }

    public function test_it_removes_an_image_with_an_onerror_payload(): void
    {
        $out = HtmlSanitizer::clean('<p>hi</p><img src="x" onerror="alert(1)">');
        $this->assertStringContainsString('hi', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringNotContainsString('alert', $out);
    }

    public function test_it_drops_links_but_keeps_their_text(): void
    {
        $out = HtmlSanitizer::clean('<a href="javascript:alert(1)">click me</a>');
        $this->assertStringContainsString('click me', $out);
        $this->assertStringNotContainsString('href', $out);
        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringNotContainsString('<a', $out);
    }

    public function test_it_drops_a_style_expression_and_url_based_colour(): void
    {
        $out = HtmlSanitizer::clean('<span style="background-color: url(javascript:alert(1)); color: expression(alert(1))">x</span>');
        $this->assertStringContainsString('x', $out);
        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringNotContainsString('expression', $out);
        $this->assertStringNotContainsString('url(', $out);
    }

    public function test_it_strips_class_and_id_and_data_attributes(): void
    {
        $out = HtmlSanitizer::clean('<p class="evil" id="x" data-x="y">text</p>');
        $this->assertStringContainsString('text', $out);
        $this->assertStringNotContainsString('class', $out);
        $this->assertStringNotContainsString('data-x', $out);
        $this->assertStringNotContainsString('id=', $out);
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', HtmlSanitizer::clean(null));
        $this->assertSame('', HtmlSanitizer::clean('   '));
    }

    public function test_it_preserves_unicode_and_escapes_stray_angle_brackets(): void
    {
        $out = HtmlSanitizer::clean('<p>Adé &amp; Bísí 5 &lt; 10</p>');
        $this->assertStringContainsString('Adé', $out);
        $this->assertStringContainsString('5 &lt; 10', $out);
    }
}
