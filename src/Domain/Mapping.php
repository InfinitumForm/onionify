<?php

namespace Onionify\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mapping resolves clearnet <-> onion aliases.
 *
 * - Single site: get_option('onionify_onion_domain')
 * - Multisite:   site_option 'onionify_onion_map' as [blog_id => onionHost]
 */
final class Mapping
{
    /** @var Detector */
    private $detector;

    public function __construct(Detector $detector)
    {
        $this->detector = $detector;
    }

    /**
     * Get configured onion host for current site.
     */
    public function onionHostForCurrentSite(): ?string
    {
        if (is_multisite()) {
            $map     = (array) get_site_option('onionify_onion_map', []);
            $blog_id = (int) get_current_blog_id();

            $raw  = isset($map[$blog_id]) ? (string) $map[$blog_id] : '';
            $host = $this->sanitizeHost($raw);

            return ($host !== '') ? $host : null;
        }

        $single = (string) get_option('onionify_onion_domain', '');
        $host   = $this->sanitizeHost($single);

        return ($host !== '') ? $host : null;
    }

    /**
     * Get clearnet base host (derived from WP home) for current site.
     */
    public function clearnetHostForCurrentSite(): ?string
    {
        $home = (string) get_option('home');
        $host = wp_parse_url($home, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        // Normalize to lowercase ASCII host.
        $host = strtolower($host);

        // Basic allowlist for host characters.
        if (preg_match('~[^a-z0-9\.\-]~', $host)) {
            return null;
        }
        return $host;
    }

    /**
     * Basic .onion host validator.
     */
    private function sanitizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }

        // Must end with .onion
        if (substr($host, -6) !== '.onion') {
            return '';
        }

        // Allow letters, digits, dots and hyphens only.
        if (!preg_match('~^[a-z0-9\.\-]+\.onion$~', $host)) {
            return '';
        }

        return $host;
    }
}
