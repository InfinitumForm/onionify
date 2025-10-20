<?php

namespace Onionify\Url;

if (!defined('ABSPATH')) {
    exit;
}

use Onionify\Domain\Detector;
use Onionify\Domain\Mapping;

/**
 * Rewrite normalizes URLs/options to the onion domain when applicable.
 */
final class Rewrite
{
    /** @var Detector */
    private $detector;

    /** @var Mapping */
    private $mapping;

    public function __construct(Detector $detector, Mapping $mapping)
    {
        $this->detector = $detector;
        $this->mapping  = $mapping;
    }

    /**
     * If request is onion, return onion version of 'home' option.
     *
     * @param mixed $pre Value to return instead of the option value.
     * @return mixed
     */
    public function filterHomeOption($pre)
    {
        if (!$this->detector->isOnionRequest()) {
            return $pre;
        }
        $onion = $this->mapping->onionHostForCurrentSite();
        if (!$onion) {
            return $pre;
        }
        // Tor hidden services typically use plain HTTP inside Tor.
        return 'http://' . $onion;
    }

    /**
     * If request is onion, return onion version of 'siteurl' option.
     *
     * @param mixed $pre Value to return instead of the option value.
     * @return mixed
     */
    public function filterSiteUrlOption($pre)
    {
        if (!$this->detector->isOnionRequest()) {
            return $pre;
        }
        $onion = $this->mapping->onionHostForCurrentSite();
        if (!$onion) {
            return $pre;
        }
        return 'http://' . $onion;
    }

    /**
     * Rewrite generated WordPress URLs to onion when serving onion requests.
     *
     * WordPress may pass $orig_scheme = null and $blog_id = null; keep signature lenient.
     *
     * @param string      $url
     * @param string|null $path
     * @param string|null $orig_scheme
     * @param int|null    $blog_id
     * @return string
     */
    public function filterUrlToOnion($url, $path = '', $orig_scheme = null, $blog_id = null): string
    {
        $url = (string) $url;

        if (!$this->detector->isOnionRequest()) {
            return $url;
        }

        $onion = $this->mapping->onionHostForCurrentSite();
        if (!$onion) {
            return $url;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        // Replace host + scheme with onion + http.
        $parts['host']   = $onion;
        $parts['scheme'] = 'http';
        unset($parts['port'], $parts['user'], $parts['pass']);

        $rebuilt = $this->buildUrl($parts);
        // Return as-is to core (display contexts handle esc_url()).
        return ($rebuilt !== '') ? $rebuilt : $url;
    }

    /**
     * Simpler variant for filters that pass only $url (and maybe $path).
     * WordPress can pass a second $path argument (e.g., content_url/plugins_url).
     *
     * @param string      $url
     * @param string|null $path
     * @return string
     */
    public function filterSimpleUrlToOnion($url, $path = null): string
    {
        $url = (string) $url;

        if (!$this->detector->isOnionRequest()) {
            return $url;
        }

        $onion = $this->mapping->onionHostForCurrentSite();
        if (!$onion) {
            return $url;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $parts['host']   = $onion;
        $parts['scheme'] = 'http';
        unset($parts['port'], $parts['user'], $parts['pass']);

        $rebuilt = $this->buildUrl($parts);
        return ($rebuilt !== '') ? $rebuilt : $url;
    }

    /**
     * Prevent canonical redirects from bouncing onion<->clearnet.
     *
     * @param string|false $redirect_url
     * @param string       $requested_url
     * @return string|false
     */
    public function avoidCanonicalBounce($redirect_url, $requested_url)
    {
        if (!$this->detector->isOnionRequest()) {
            return $redirect_url;
        }

        // If WP tries to redirect to non-onion host, skip it.
        $targetHost = wp_parse_url((string) $redirect_url, PHP_URL_HOST);
        if (is_string($targetHost) && $targetHost !== '' && substr(strtolower($targetHost), -6) !== '.onion') {
            return false;
        }

        // Extra: never canonical-bounce for sensitive/admin endpoints when on onion.
        $path = wp_parse_url((string) $requested_url, PHP_URL_PATH);
        $noBounce = [
            '/wp-login.php',
            '/wp-cron.php',
            '/xmlrpc.php',
            '/wp-admin/',
            '/wp-admin/admin-ajax.php',
        ];

        if (is_string($path)) {
            foreach ($noBounce as $p) {
                if (stripos($path, $p) === 0) {
                    return false;
                }
            }
            // REST root
            if (stripos($path, '/wp-json/') === 0) {
                return false;
            }
        }

        return $redirect_url;
    }

    /**
     * Inside Tor, force WordPress to think it's non-HTTPS to avoid mixed-content enforcement.
     */
    public function forceHttpInsideTor(bool $is_https): bool
    {
        if ($this->detector->isOnionRequest()) {
            return false;
        }
        return $is_https;
    }

    /**
     * Safely rebuild URL from parsed parts.
     * - Validates scheme (http/https only)
     * - Validates/normalizes host
     * - Validates port
     * - Encodes path/query/fragment safely
     *
     * @param array $parts
     * @return string
     */
    private function buildUrl(array $parts): string
    {
        // Scheme
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $scheme = in_array($scheme, ['http', 'https'], true) ? $scheme . '://' : '';

        // Host
        $host = isset($parts['host']) ? strtolower(trim((string) $parts['host'])) : '';
        // Host comes from our mapping or existing parsed URL: still validate strictly.
        if ($host === '' || preg_match('~[^a-z0-9\.\-]~', $host)) {
            return '';
        }

        // Port
        $port = '';
        if (isset($parts['port'])) {
            $p = (int) $parts['port'];
            if ($p > 0 && $p < 65536) {
                $port = ':' . $p;
            }
        }

        // Path
        $path = '';
        if (isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== '') {
            $path = $this->encodePath($parts['path']);
        }

        // Query (parse and rebuild to RFC3986 encoding)
        $query = '';
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            $pairs = [];
            parse_str($parts['query'], $pairs);
            $query = $pairs ? '?' . http_build_query($pairs, '', '&', PHP_QUERY_RFC3986) : '';
        }

        // Fragment
        $fragment = '';
        if (isset($parts['fragment']) && is_string($parts['fragment']) && $parts['fragment'] !== '') {
            $fragment = '#' . rawurlencode($parts['fragment']);
        }

        return $scheme . $host . $port . $path . $query . $fragment;
    }

    /**
     * Encode a path safely while preserving slashes.
     */
    private function encodePath(string $path): string
    {
        $segments = explode('/', $path);
        foreach ($segments as &$seg) {
            if ($seg === '') {
                continue;
            }
            // Remove control chars; encode each segment.
            $seg = rawurlencode(preg_replace('/[\x00-\x1F\x7F]/', '', $seg));
        }
        unset($seg);

        // Preserve leading slash if present.
        $leading = ($path !== '' && $path[0] === '/') ? '/' : '';
        return $leading . implode('/', $segments);
    }
}
