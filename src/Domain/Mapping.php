<?php

namespace TorOnionSupport\Domain;

/**
 * Mapping resolves clearnet <-> onion aliases.
 *
 * - Single site: get_option('tos_onion_domain')
 * - Multisite:   site_option 'tos_onion_map' as [blog_id => onionHost]
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
            $map = get_site_option('tos_onion_map', []);
            $blog_id = get_current_blog_id();
            $host = $map[$blog_id] ?? '';
            return $host ? strtolower($host) : null;
        }

        $single = (string) get_option('tos_onion_domain', '');
        return $single ? strtolower($single) : null;
    }

    /**
     * Get clearnet base host (derived from WP home) for current site.
     */
    public function clearnetHostForCurrentSite(): ?string
    {
        $home = (string) get_option('home');
        $host = wp_parse_url($home, PHP_URL_HOST);
        if (!$host) {
            return null;
        }
        return strtolower($host);
    }
}
