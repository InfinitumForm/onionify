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
     * @param string|NULL $path
     * @param string|NULL $orig_scheme
     * @param int|NULL    $blog_id
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
        if (!$parts || empty($parts['host'])) {
            return $url;
        }

        // Replace host + scheme with onion + http.
        $parts['host']   = $onion;
        $parts['scheme'] = 'http';
        unset($parts['port']);

        return $this->buildUrl($parts);
    }

    /**
     * Simpler variant for filters that pass only $url (and maybe $path).
     * WordPress can pass a second $path argument (e.g., content_url/plugins_url).
     *
     * @param string      $url
     * @param string|NULL $path
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
        if (!$parts || empty($parts['host'])) {
            return $url;
        }

        $parts['host']   = $onion;
        $parts['scheme'] = 'http';
        unset($parts['port']);

        return $this->buildUrl($parts);
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
		if ($targetHost && substr(strtolower($targetHost), -6) !== '.onion') {
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
     *
     * @param array $parts
     * @return string
     */
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
