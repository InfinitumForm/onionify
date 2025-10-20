<?php

namespace Onionify\Http;

if (!defined('ABSPATH')) {
    exit;
}

use Onionify\Domain\Detector;
use Onionify\Domain\Mapping;

/**
 * Headers adds Onion-Location and optional security headers.
 */
final class Headers
{
    private Detector $detector;
    private Mapping $mapping;

    public function __construct(Detector $detector, Mapping $mapping)
    {
        $this->detector = $detector;
        $this->mapping  = $mapping;
    }

    /**
     * Send headers early in template_redirect.
     */
    public function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        // Advertise Onion-Location from clearnet if enabled and mapping is set.
        if ($this->detector->isClearnetRequest() && get_option('onionify_send_onion_location', true)) {
            $onion = $this->mapping->onionHostForCurrentSite();
            if ($onion) {
                $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
                $onion_url   = 'http://' . $onion . $request_uri;
                header('Onion-Location: ' . $onion_url);
            }
        }

        // Only send hardening headers for onion requests and if enabled.
        if ($this->detector->isOnionRequest() && get_option('onionify_enable_hardening', false)) {
            // Baseline isolation headers.
            header('Cross-Origin-Embedder-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-origin');
            header('X-Frame-Options: SAMEORIGIN');

            // Content-Security-Policy based on admin setting.
            $mode = (string) get_option('onionify_hardening_csp_mode', 'strict'); // strict|relaxed|off|custom
            $csp  = '';

            if ($mode === 'strict') {
                // Very locked down: only self + data: images; inline styles allowed for themes.
                $csp = "default-src 'self'; img-src 'self' data:; media-src 'self'; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";
            } elseif ($mode === 'relaxed') {
                // Allow inline script too (legacy themes/plugins).
                $csp = "default-src 'self'; img-src 'self' data:; media-src 'self'; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";
            } elseif ($mode === 'custom') {
                $custom = (string) get_option('onionify_hardening_csp_custom', '');
                $csp    = trim($custom);
            }

            if ($csp !== '' && $mode !== 'off') {
                header('Content-Security-Policy: ' . $csp);
            }
			
			// Extra: lock down powerful features for onion visitors.
			header('Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=(), browsing-topics=()');
        }

        header('Vary: Accept-Encoding, Cookie, Host', false);
    }
}
