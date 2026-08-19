<?php

namespace App\Support;

/**
 * The public marketing site's own root URL, built the same way
 * School::portalUrl() builds a school's subdomain URL -- same
 * scheme/domain/port derivation, just with no subdomain prefix.
 *
 * Exists because several login-adjacent screens (2026-08-19: "make the
 * SKULOS logo clickable which takes one to the marketing homepage")
 * render from a school's own subdomain, where a plain "/" would hit
 * that subdomain's own root (which redirects straight back to /login)
 * rather than the real marketing site.
 */
class MarketingUrl
{
    public static function home(): string
    {
        $base = parse_url((string) config('app.url'));
        $scheme = $base['scheme'] ?? 'http';
        $domain = config('app.domain', $base['host'] ?? 'localhost');
        $port = isset($base['port']) && ! in_array($base['port'], [80, 443]) ? ':'.$base['port'] : '';

        return "{$scheme}://{$domain}{$port}/";
    }
}
