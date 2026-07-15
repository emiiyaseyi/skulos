<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * A deliberately narrow allowlist sanitizer for the small set of rich-text
 * formatting the message/broadcast composer produces (bold, italic,
 * underline, strikethrough, highlight, text colour, lists, alignment,
 * paragraphs). User-submitted HTML is shown to OTHER users, so this is the
 * stored-XSS boundary: everything not explicitly allowed is stripped.
 *
 * No dependency (symfony/html-sanitizer could not be fetched in this
 * offline-ish environment, 2026-07-15), so this is hand-rolled on
 * DOMDocument with a conservative allowlist. It intentionally does NOT
 * permit links, images, media, forms, class/id/data-* attributes, or any
 * event handler, only presentational formatting tags and a couple of
 * strictly-validated style properties. When in doubt it drops.
 */
class HtmlSanitizer
{
    /** Formatting tags kept as-is (attributes still stripped/validated). */
    private const ALLOWED_TAGS = [
        'p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's',
        'strike', 'del', 'mark', 'sub', 'sup', 'blockquote', 'ul', 'ol', 'li',
    ];

    /** Tags removed together with all their contents (never unwrapped). */
    private const DANGEROUS_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input',
        'button', 'textarea', 'select', 'option', 'link', 'meta', 'base',
        'svg', 'math', 'template', 'noscript', 'title', 'head',
    ];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // NOIMPLIED/NODEFDTD keep DOMDocument from wrapping the fragment in
        // its own <html><body>; the __root__ wrapper gives us a stable
        // container to read the cleaned inner HTML back out of.
        $dom->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__root__">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('__root__');
        if (! $root) {
            return '';
        }

        self::cleanChildren($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    private static function cleanChildren(DOMNode $node): void
    {
        // Snapshot first: the list mutates as we unwrap/remove.
        foreach (iterator_to_array($node->childNodes) as $child) {
            self::cleanNode($child);
        }
    }

    private static function cleanNode(DOMNode $node): void
    {
        // Text nodes are safe (DOMDocument stores them decoded and
        // re-encodes entities on save); comments are dropped.
        if ($node->nodeType === XML_TEXT_NODE) {
            return;
        }
        if ($node->nodeType === XML_COMMENT_NODE) {
            $node->parentNode?->removeChild($node);

            return;
        }
        if (! $node instanceof DOMElement) {
            $node->parentNode?->removeChild($node);

            return;
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, self::DANGEROUS_TAGS, true)) {
            $node->parentNode?->removeChild($node);

            return;
        }

        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            // Unknown/disallowed but non-dangerous (e.g. <font>, <a>, <img>):
            // drop the tag but keep its already-cleaned children/text.
            self::cleanChildren($node);
            self::unwrap($node);

            return;
        }

        // Allowed tag: strip every attribute, then restore a validated style.
        $style = $node->getAttribute('style');
        foreach (iterator_to_array($node->attributes) as $attr) {
            $node->removeAttribute($attr->nodeName);
        }
        $cleanStyle = self::sanitizeStyle($style);
        if ($cleanStyle !== '') {
            $node->setAttribute('style', $cleanStyle);
        }

        self::cleanChildren($node);
    }

    private static function unwrap(DOMElement $node): void
    {
        $parent = $node->parentNode;
        if (! $parent) {
            return;
        }
        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    private static function sanitizeStyle(string $style): string
    {
        $kept = [];
        foreach (explode(';', $style) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '' || ! str_contains($declaration, ':')) {
                continue;
            }
            [$prop, $value] = explode(':', $declaration, 2);
            $prop = strtolower(trim($prop));
            $value = trim($value);

            if ($prop === 'text-align' && in_array(strtolower($value), ['left', 'right', 'center', 'justify'], true)) {
                $kept[] = 'text-align:'.strtolower($value);
            } elseif (in_array($prop, ['background-color', 'color'], true) && self::isSafeColor($value)) {
                $kept[] = $prop.':'.$value;
            }
        }

        return implode(';', $kept);
    }

    private static function isSafeColor(string $value): bool
    {
        $named = ['transparent', 'yellow', 'red', 'green', 'blue', 'black', 'white',
            'orange', 'pink', 'cyan', 'magenta', 'lime', 'gray', 'grey', 'purple', 'brown'];

        return (bool) preg_match('/^#[0-9a-fA-F]{3,8}$/', $value)
            || (bool) preg_match('/^rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)$/', $value)
            || (bool) preg_match('/^rgba\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*(?:0|1|0?\.\d+)\s*\)$/', $value)
            || in_array(strtolower($value), $named, true);
    }
}
