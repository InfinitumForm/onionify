<?php

namespace Onionify\Http;

if (!defined('ABSPATH')) {
    exit;
}

use Onionify\Domain\Detector;
use Onionify\Domain\Mapping;

/**
 * Loopback safely reroutes internal HTTP (cron, REST, admin-ajax) from .onion to clearnet host.
 * Prevents "loopback failed" and cron/site health issues when site is accessed via .onion.
 */
final class Loopback
{
    private Detector $detector;
    private Mapping $mapping;

    /** Internal-only paths we may reroute */
    private array $internalPaths = [
        '/wp-cron.php',
        '/wp-admin/admin-ajax.php',
        '/xmlrpc.php',
    ];

    public function __construct(Detector $detector, Mapping $mapping)
    {
        $this->detector = $detector;
        $this->mapping  = $mapping;
    }

    /**
     * Register filters for HTTP rerouting.
     */
    public function register(): void
    {
        // General HTTP — short-circuit onion → clearnet for internal endpoints.
        add_filter('pre_http_request', [$this, 'maybeProxyInternalHttp'], 9, 3);

        // Cron calls — adjust URL from onion → clearnet.
        add_filter('cron_request', [$this, 'rerouteCronRequest'], 9, 1);
    }

    /**
     * If on onion (and enabled), proxy internal HTTP calls to clearnet equivalent and return response.
     *
     * @param false|array $pre  Preemptive response. If not false, short-circuits the request.
     * @param array       $args Request args.
     * @param string      $url  Target URL.
     * @return false|array
     */
    public function maybeProxyInternalHttp($pre, array $args, string $url)
    {
        // Avoid recursion if our own reroute already fired.
        if (!empty($args['headers']['X-TOS-Loopback'])) {
            return $pre;
        }

        if (!$this->detector->isOnionRequest()) {
            return $pre;
        }
        if (!(bool) get_option('onionify_loopback_reroute', true)) {
            return $pre;
        }

        $onion = $this->mapping->onionHostForCurrentSite();
        $clear = $this->mapping->clearnetHostForCurrentSite();
        if (!$onion || !$clear) {
            return $pre;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $pre;
        }

        // Only proxy if the target is our own onion host and path is internal (cron/ajax/rest/xmlrpc)
        $hostLower  = strtolower((string) $parts['host']);
        $path       = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';
        $isInternal = $this->isInternalPath($path) || $this->isRestPath($path);

        if ($hostLower === $onion && $isInternal) {
            // Build clearnet URL
            $parts['host']   = $clear;
            $home            = (string) get_option('home');
            $homeScheme      = wp_parse_url($home, PHP_URL_SCHEME);
            $scheme          = in_array($homeScheme, ['http', 'https'], true) ? $homeScheme : 'https';
            $parts['scheme'] = $scheme;

            // Drop auth components to avoid leaking credentials.
            unset($parts['user'], $parts['pass']);

            $newUrl = $this->buildUrl($parts);
            $newUrl = esc_url_raw($newUrl);

            if ($newUrl === '') {
                return $pre;
            }

            // Perform actual request and short-circuit original call.
            // Ensure we don't recurse (set a custom header flag).
			$args['headers'] = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
			$args['headers']['X-TOS-Loopback'] = '1';
			$args['reject_unsafe_urls'] = true;
			if (!isset($args['sslverify'])) {
				$args['sslverify'] = true;
			}

            return wp_remote_request($newUrl, $args);
        }

        return $pre;
    }

    /**
     * Reroute cron URL onion → clearnet to avoid local resolution failures.
     *
     * @param array $cron Cron request args with 'url' and 'key'.
     * @return array
     */
    public function rerouteCronRequest(array $cron): array
    {
        if (!$this->detector->isOnionRequest()) {
            return $cron;
        }
        if (!(bool) get_option('onionify_loopback_reroute', true)) {
            return $cron;
        }

        $onion = $this->mapping->onionHostForCurrentSite();
        $clear = $this->mapping->clearnetHostForCurrentSite();
        if (!$onion || !$clear) {
            return $cron;
        }

        if (empty($cron['url']) || !is_string($cron['url'])) {
            return $cron;
        }

        $parts = wp_parse_url($cron['url']);
        if (!is_array($parts) || empty($parts['host'])) {
            return $cron;
        }

        $hostLower = strtolower((string) $parts['host']);
        if ($hostLower === $onion) {
            $home       = (string) get_option('home');
            $homeScheme = wp_parse_url($home, PHP_URL_SCHEME);
            $scheme     = in_array($homeScheme, ['http', 'https'], true) ? $homeScheme : 'https';

            $parts['host']   = $clear;
            $parts['scheme'] = $scheme;

            // Drop auth to avoid credential propagation.
            unset($parts['user'], $parts['pass']);

            $new = esc_url_raw($this->buildUrl($parts));
            if ($new !== '') {
                $cron['url'] = $new;
            }
        }

        return $cron;
    }

    private function isInternalPath(string $path): bool
    {
        foreach ($this->internalPaths as $p) {
            if (stripos($path, $p) === 0) {
                return true;
            }
        }
        return false;
    }

    private function isRestPath(string $path): bool
    {
        // WordPress REST root defaults to /wp-json/
        return (stripos($path, '/wp-json/') === 0);
    }

    /**
     * Assemble a URL from wp_parse_url() parts defensively.
     * - Validates host (basic chars)
     * - Ensures scheme is http/https
     * - Encodes path/query/fragment safely
     */
    private function buildUrl(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $scheme = in_array($scheme, ['http', 'https'], true) ? $scheme . '://' : '';

        $host = isset($parts['host']) ? strtolower(trim(sanitize_text_field((string) $parts['host']))) : '';
        if ($host === '' || preg_match('~[^a-z0-9\.\-]~', $host)) {
            return '';
        }

        $port = '';
        if (isset($parts['port'])) {
            $p = (int) $parts['port'];
            if ($p > 0 && $p < 65536) {
                $port = ':' . $p;
            }
        }

        // User/pass are intentionally ignored (unset in callers).

        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';
        $path = $this->encodePath($path);

        $query = '';
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            // Parse and rebuild to RFC3986 encode.
            $pairs = [];
            parse_str($parts['query'], $pairs);
            $query = $pairs ? '?' . http_build_query($pairs, '', '&', PHP_QUERY_RFC3986) : '';
        }

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
        if ($path === '') {
            return '';
        }
        $segments = explode('/', $path);
        foreach ($segments as &$seg) {
            // Leave empty segments as-is.
            if ($seg === '') {
                continue;
            }
            // Remove control chars; then encode.
            $seg = rawurlencode(preg_replace('/[\x00-\x1F\x7F]/', '', $seg));
        }
        unset($seg);

        // Preserve leading slash if it existed.
        $leading = ($path[0] === '/') ? '/' : '';
        return $leading . implode('/', $segments);
    }
}
