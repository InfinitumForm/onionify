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
        if (!$this->detector->isOnionRequest()) {
            return $pre;
        }
        if (!get_option('onionify_loopback_reroute', true)) {
            return $pre;
        }

        $onion = $this->mapping->onionHostForCurrentSite();
        $clear = $this->mapping->clearnetHostForCurrentSite();
        if (!$onion || !$clear) {
            return $pre;
        }

        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return $pre;
        }

        // Only proxy if the target is our own onion host and path is internal (cron/ajax/rest/xmlrpc)
        $path = $parts['path'] ?? '/';
        $isInternal = $this->isInternalPath($path) || $this->isRestPath($path);

        if (strtolower($parts['host']) === $onion && $isInternal) {
            // Build clearnet URL
            $parts['host']   = $clear;
            // Prefer scheme from DB home (likely http/https)
            $homeScheme = wp_parse_url(get_option('home'), PHP_URL_SCHEME) ?: 'https';
            $parts['scheme'] = $homeScheme;
            $newUrl = $this->buildUrl($parts);

            // Perform actual request and short-circuit original call.
            // Ensure we don't recurse (set a custom header flag).
            $args['headers'] = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
            $args['headers']['X-TOS-Loopback'] = '1';

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
        if (!get_option('onionify_loopback_reroute', true)) {
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
        if (!$parts || empty($parts['host'])) {
            return $cron;
        }

        if (strtolower($parts['host']) === $onion) {
            $parts['host']   = $clear;
            $homeScheme = wp_parse_url(get_option('home'), PHP_URL_SCHEME) ?: 'https';
            $parts['scheme'] = $homeScheme;
            $cron['url']     = $this->buildUrl($parts);
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

    private function buildUrl(array $parts): string
    {
        $scheme   = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host     = $parts['host'] ?? '';
        $port     = isset($parts['port']) ? ':' . $parts['port'] : '';
        $user     = $parts['user'] ?? '';
        $pass     = isset($parts['pass']) ? ':' . $parts['pass']  : '';
        $auth     = ($user || $pass) ? "$user$pass@" : '';
        $path     = $parts['path'] ?? '';
        $query    = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return $scheme . $auth . $host . $port . $path . $query . $fragment;
    }
}
