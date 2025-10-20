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
        if ($this->detector->isClearnetRequest() && (bool) get_option('onionify_send_onion_location', true)) {
            $onion = $this->mapping->onionHostForCurrentSite();
            if ($onion) {
                // REQUEST_URI may include query string and user input; sanitize rigorously.
                $request_uri_raw = $_SERVER['REQUEST_URI'] ?? '/';
                $request_uri     = $this->sanitizeRequestUri($request_uri_raw);

                // Build absolute onion URL safely. Use http by default for onion.
                $onion_url = 'http://' . $onion . $request_uri;

                // Final defense: strip CRLF to prevent header injection.
                $onion_url = str_replace(["\r", "\n"], '', $onion_url);

                // Use header() with sanitized value.
                header('Onion-Location: ' . $onion_url);
            }
        }

        // Only send hardening headers for onion requests and if enabled.
        if ($this->detector->isOnionRequest() && (bool) get_option('onionify_enable_hardening', false)) {
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
                // Normalize newlines and strip CR/LF to avoid header injection; keep spaces.
                $csp = str_replace(["\r\n", "\r"], "\n", $custom);
                $csp = str_replace(["\n"], ' ', $csp);
                $csp = trim($csp);
            }

            if ($csp !== '' && $mode !== 'off') {
                // Prevent CRLF injection in header value (defense-in-depth).
                $csp = str_replace(["\r", "\n"], '', $csp);
                header('Content-Security-Policy: ' . $csp);
            }

            // Extra: lock down powerful features for onion visitors.
            header('Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=(), browsing-topics=()');
        }

        // Send Vary without replacing prior Vary headers.
        header('Vary: Accept-Encoding, Cookie, Host', false);
    }

    /* -----------------------------------------------------------------
     * Internals (sanitization helpers)
     * ----------------------------------------------------------------- */

    /**
     * Sanitize REQUEST_URI for safe reinsertion into an absolute URL.
     * - wp_unslash() then raw sanitize
     * - ensure leading slash
     * - remove control chars
     * - allow only path/query/fragment-safe characters
     */
    private function sanitizeRequestUri($raw): string
    {
        $uri = is_string($raw) ? wp_unslash($raw) : '/';

        // Strip CRLF and other controls to guard headers.
        $uri = preg_replace('/[\x00-\x1F\x7F]/', '', (string) $uri);

        // Ensure it starts with slash.
        if ($uri === '' || $uri[0] !== '/') {
            $uri = '/' . ltrim($uri, " \t\n\r\0\x0B/");
        }

        // Parse and rebuild path + query + fragment to avoid dangerous characters.
        $parts = wp_parse_url($uri);
        $path  = isset($parts['path']) ? $this->sanitizePath($parts['path']) : '/';
        $query = isset($parts['query']) ? $this->sanitizeQuery($parts['query']) : '';
        $frag  = isset($parts['fragment']) ? $this->sanitizeFragment($parts['fragment']) : '';

        $rebuilt = $path;
        if ($query !== '') {
            $rebuilt .= '?' . $query;
        }
        if ($frag !== '') {
            $rebuilt .= '#' . $frag;
        }

        return $rebuilt;
    }

    /**
     * Sanitize a URL path, encoding each segment safely.
     */
    private function sanitizePath(string $path): string
    {
        // Guarantee leading slash.
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        $segments = explode('/', $path);
        foreach ($segments as &$seg) {
            // Leave empty segments as-is (for // or trailing slash)
            if ($seg === '') {
                continue;
            }
            // Remove control chars; then rawurlencode to keep UTF-8 safe.
            $seg = rawurlencode(preg_replace('/[\x00-\x1F\x7F]/', '', $seg));
        }
        unset($seg);

        // Preserve leading slash.
        return implode('/', $segments);
    }

    /**
     * Sanitize query string while preserving key=value pairs.
     */
    private function sanitizeQuery(string $query): string
    {
        // Remove control chars.
        $query = preg_replace('/[\x00-\x1F\x7F]/', '', $query);
        // Parse into pairs and rebuild encoded.
        $pairs = [];
        parse_str($query, $pairs);

        if (!is_array($pairs) || $pairs === []) {
            // Fallback: encode whole query safely.
            return rawurlencode($query);
        }

        // http_build_query handles encoding and array structures.
        // Use RFC3986 (rawurlencode) encoding.
        return http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Sanitize URL fragment.
     */
    private function sanitizeFragment(string $frag): string
    {
        $frag = preg_replace('/[\x00-\x1F\x7F]/', '', $frag);
        return rawurlencode($frag);
    }
}
