<?php

namespace Onionify\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detector handles TOR / .onion request detection safely across CDNs and proxies.
 *
 * Features:
 * - Direct .onion host detection
 * - Proxy/CDN header inspection (CF-Connecting-IP, X-Forwarded-For, etc.)
 * - Optional Tor exit list verification (disabled by default)
 * - Filter hook: onion_is_onion_request
 * - PHP 7.4+ compatible (works on PHP 8.x)
 */
final class Detector
{
    /**
     * Get current HTTP host in lowercase.
     */
    public function currentHost(): string
    {
        $host = sanitize_text_field($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
        return strtolower(trim((string) $host));
    }

    /**
     * Determine if this request should be treated as TOR / .onion.
     */
    public function isOnionRequest(): bool
    {
        try {
            // 1) Direct .onion host
            $host = $this->currentHost();
            if ($host !== '' && $this->endsWith($host, '.onion')) {
                $isTor = true;
            } else {
                $isTor = false;
            }

            // 2) Headers (Tor2Web / custom)
            $S = $this->getServerUpper();
            foreach (['X-TOR-EXIT-NODE','X-TOR2WEB','X-TOR-USER','X-TOR-ORIGIN'] as $h) {
                if (isset($S[$h])) {
                    $isTor = true;
                    break;
                }
            }

            // 3) Client IP (through CDN/proxy)
            $ip = $this->getClientIp($S);
            if ($ip && !$this->isPrivateIp($ip)) {
                // quick heuristic (no HTTP)
                if ($this->isLikelyTorIpHeuristic($ip)) {
                    $isTor = true;
                } else {
                    // optional official exit-list verification (cached, opt-in)
                    if ($this->shouldVerifyExitList() && $this->isTorExitNode($ip)) {
                        $isTor = true;
                    }
                }
            }

            /**
             * Allow 3rd-parties to override/extend detection.
             *
             * @param bool  $isTor  Current decision.
             * @param array $server Copy of $_SERVER.
             */
            $isTor = (bool) apply_filters('onion_is_onion_request', $isTor, $_SERVER);

            return $isTor;
        } catch (\Throwable $e) {
            // Absolutely never fatal — if anything goes wrong, fall back to clearnet.
            return false;
        }
    }

    /**
     * Opposite of isOnionRequest().
     */
    public function isClearnetRequest(): bool
    {
        return !$this->isOnionRequest();
    }

    /* ---------------------------------------------------------------------
     * Internals (defensive)
     * ------------------------------------------------------------------ */

    private function getServerUpper(): array
    {
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($k)) {
                $out[strtoupper($k)] = $v;
            }
        }
        return $out;
    }

    private function endsWith(string $haystack, string $needle): bool
    {
        $len = strlen($needle);
        if ($len === 0) {
            return true;
        }
        return substr($haystack, -$len) === $needle;
    }

    /**
     * Best-effort client IP extraction (CDN/proxy aware).
     */
    private function getClientIp(array $S): ?string
    {
        // Prefer provider headers:
        $candidates = [
            $S['CF_CONNECTING_IP'] ?? null,   // Cloudflare
            $S['TRUE_CLIENT_IP'] ?? null,     // Akamai
            $this->firstPublicFromXff($S['X_FORWARDED_FOR'] ?? null),
            $S['X_REAL_IP'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];
        foreach ($candidates as $ip) {
            if (is_string($ip) && $ip !== '') {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return null;
    }

    /**
     * Parse X-Forwarded-For, return first valid public IP.
     */
    private function firstPublicFromXff($xff): ?string
    {
        if (!is_string($xff) || $xff === '') {
            return null;
        }
        $parts = array_map('trim', explode(',', $xff));
        foreach ($parts as $p) {
            if (filter_var($p, FILTER_VALIDATE_IP) && !$this->isPrivateIp($p)) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Private/local IP ranges.
     */
    private function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (bool) preg_match('/^(10\.|127\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return ($ip === '::1' || strpos($ip, 'fc') === 0 || strpos($ip, 'fd') === 0);
        }
        return true; // treat unknown as non-public
    }

    /**
     * Very fast heuristic for TOR-ish IPv4 blocks (no network calls).
     */
    private function isLikelyTorIpHeuristic(string $ip): bool
    {
        // IPv6: commonly not exit in many setups; skip
        if (strpos($ip, ':') !== false) {
            return false;
        }
        $prefix = strtok($ip, '.');
        if ($prefix === false) {
            return false;
        }
        // Coarse prefixes where TOR exits are often seen (heuristic only)
        $common = ['91','94','95','104','109','130','142','144','145','151','152','153','171','176','178','179','185','188','193','194','195','198','204','207','208','212','213','217'];
        return in_array($prefix, $common, true);
    }

    /**
     * Should we consult the official Tor exit list?
     * - Disabled by default for performance/privacy.
     * - Enable via:
     *     define('ONIONIFY_VERIFY_ONION_EXIT', true);
     *   or filter:
     *     add_filter('onion_verify_exit_list', '__return_true');
     */
    private function shouldVerifyExitList(): bool
    {
        $allow = defined('ONIONIFY_VERIFY_ONION_EXIT') && ONIONIFY_VERIFY_ONION_EXIT;
        /**
         * Allow enabling/disabling exit-list verification.
         *
         * @param bool $allow Current decision (default false).
         */
        return (bool) apply_filters('onion_verify_exit_list', $allow);
    }

    /**
     * Check against official Tor exit list, using cached content.
     * NEVER fatal: if HTTP functions are unavailable or blocked, returns false.
     */
    private function isTorExitNode(string $ip): bool
    {
        // Use a cached blob of the exit list to avoid fetching on every request.
        $blob = get_transient('onion_exit_list_blob');
        if (!is_string($blob) || $blob === '') {
            // If we are not allowed or wp_remote_get is unavailable, skip.
            if (!$this->httpAvailable() || !$this->respectExternalHttpPolicy()) {
                return false;
            }
            // Fetch fresh list once, cache for 24h.
            $blob = $this->fetchExitList();
            if (!is_string($blob) || $blob === '') {
                return false;
            }
            set_transient('onion_exit_list_blob', $blob, DAY_IN_SECONDS);
        }

        // Cheap membership test
        // Lines in the list look like: "ExitAddress <ip> <timestamp> <fingerprint>"
        return (strpos($blob, 'ExitAddress ' . $ip . ' ') !== false);
    }

    private function httpAvailable(): bool
    {
        return function_exists('wp_remote_get') && function_exists('wp_remote_retrieve_body');
    }

    private function respectExternalHttpPolicy(): bool
    {
        // Honor WP_HTTP_BLOCK_EXTERNAL and allowed hosts if set.
        if (defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL) {
            // Allow if explicitly whitelisted
            $allowed = defined('WP_ACCESSIBLE_HOSTS') ? (string) WP_ACCESSIBLE_HOSTS : '';
            if (stripos($allowed, 'check.torproject.org') === false) {
                return false;
            }
        }
        return true;
    }

    private function fetchExitList(): ?string
    {
        try {
            $args = [
                'timeout' => 5,
                'sslverify' => true,
                'reject_unsafe_urls' => true,
                'headers' => [
                    'User-Agent' => 'Onionify/1.0 (+https://wordpress.org/)',
                ],
            ];
            $resp = wp_remote_get('https://check.torproject.org/exit-addresses', $args);
            if (is_wp_error($resp)) {
                return null;
            }
            $body = wp_remote_retrieve_body($resp);
            if (!is_string($body) || $body === '') {
                return null;
            }
            // Basic sanity check: file should contain many "ExitAddress" lines
            if (strpos($body, 'ExitAddress ') === false) {
                return null;
            }
            return $body;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
