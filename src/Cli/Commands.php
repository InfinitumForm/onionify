<?php

namespace Onionify\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use WP_CLI;
use WP_CLI_Command;

/**
 * WP-CLI commands for managing onion mapping and settings.
 *
 * Examples:
 *   wp tor-onion list
 *   wp tor-onion map 12 exampleonionaddress.onion
 *   wp tor-onion set --hardening=on --oembed=off --csp=strict
 */
final class Commands extends WP_CLI_Command
{
    /**
     * List current multisite mapping (or single-site onion).
     *
     * ## EXAMPLES
     *   wp tor-onion list
     */
    public function list($_, $assoc_args): void
    {
        if (is_multisite()) {
            $map   = (array) get_site_option('onionify_onion_map', []);
            $items = [];

            foreach (get_sites(['number' => 2000]) as $site) {
                $blog_id = (int) $site->blog_id;
                $details = get_blog_details($blog_id);

                $items[] = [
                    'blog_id' => $blog_id,
                    'domain'  => isset($details->domain) ? (string) $details->domain : '',
                    'path'    => isset($details->path) ? (string) $details->path : '',
                    'onion'   => isset($map[$blog_id]) ? (string) $map[$blog_id] : '',
                ];
            }

            \WP_CLI\Utils\format_items('table', $items, ['blog_id', 'domain', 'path', 'onion']);
        } else {
            $home  = (string) get_option('home');
            $onion = (string) (get_option('onionify_onion_domain') ?: '(not set)');

            WP_CLI::log('Single-site:');
            WP_CLI::log('  Home:  ' . $home);
            WP_CLI::log('  Onion: ' . $onion);
        }
    }

    /**
     * Map a blog_id to an onion host (multisite) or set single-site onion host.
     *
     * ## OPTIONS
     *
     * <id>
     * : Blog ID (use 0 for single-site).
     *
     * <host>
     * : Onion host, e.g. exampleonionaddress.onion
     *
     * ## EXAMPLES
     *   wp tor-onion map 12 exampleonionaddress.onion
     *   wp tor-onion map 0 exampleonionaddress.onion
     */
    public function map($args, $_): void
    {
        // Basic arity check (keeps original logic but avoids notices).
        if (!is_array($args) || count($args) < 2) {
            WP_CLI::error('Usage: wp tor-onion map <blog_id|0> <example.onion>');
        }

        // Sanitize inputs.
        $id_raw   = (string) $args[0];
        $host_raw = (string) $args[1];

        $id   = absint($id_raw);
        $host = $this->sanitize_host($host_raw);

        if ($host === '') {
            WP_CLI::error('Invalid onion host. Expected something like exampleonionaddress.onion');
        }

        if ($id === 0 && !is_multisite()) {
            update_option('onionify_onion_domain', $host);
            WP_CLI::success('Set single-site onion host to: ' . $host);
            return;
        }

        if (!is_multisite()) {
            WP_CLI::error('Multisite not enabled. Use blog_id=0 for single-site.');
        }

        // Optional: assert blog exists (does not change algorithmic flow).
        $details = get_blog_details($id, false);
        if (!$details) {
            WP_CLI::error('Blog ID does not exist: ' . $id);
        }

        $map        = (array) get_site_option('onionify_onion_map', []);
        $map[$id]   = $host;
        update_site_option('onionify_onion_map', $map);

        WP_CLI::success("Mapped blog_id {$id} to onion host: {$host}");
    }

    /**
     * Quick-toggle settings for hardening.
     *
     * ## OPTIONS
     * [--hardening=<on|off>]
     * [--oembed=<on|off>]
     * [--csp=<strict|relaxed|off>]
     *
     * ## EXAMPLES
     *   wp tor-onion set --hardening=on --oembed=off --csp=strict
     */
    public function set($_, $assoc_args): void
    {
        // Sanitize and normalize toggles.
        if (isset($assoc_args['hardening'])) {
            $hardening = $this->sanitize_on_off($assoc_args['hardening']);
            update_option('onionify_enable_hardening', $hardening);
        }

        if (isset($assoc_args['oembed'])) {
            $oembed_off = $this->sanitize_on_off($assoc_args['oembed']);
            // Original logic: anything not "on" becomes true (disabled). With sanitization we keep intent:
            update_option('onionify_disable_oembed', $oembed_off);
        }

        if (isset($assoc_args['csp'])) {
            $mode = $this->sanitize_csp_mode($assoc_args['csp']);
            update_option('onionify_hardening_csp_mode', $mode);
        }

        WP_CLI::success('Settings updated.');
    }

    /* -----------------------------------------------------------------
     * Internal sanitizers (WP coding standards: sanitize_* on inputs)
     * ----------------------------------------------------------------- */

    /**
     * Validate onion host: lowercase, .onion suffix, allowed chars.
     */
    private function sanitize_host(string $host): string
    {
        $host = sanitize_text_field($host);
        $host = strtolower(trim($host));

        if ($host === '') {
            return '';
        }
        if (substr($host, -6) !== '.onion') {
            return '';
        }
        if (!preg_match('~^[a-z0-9\-\.]+\.onion$~', $host)) {
            return '';
        }
        return $host;
    }

    /**
     * Normalize "on"/"off" toggles into booleans.
     */
    private function sanitize_on_off($val): bool
    {
        $val = is_string($val) ? strtolower(sanitize_text_field($val)) : $val;

        // Accept typical truthy forms used in CLI flags.
        if ($val === 'on' || $val === '1' || $val === 'true' || $val === 'yes' || $val === 1 || $val === true) {
            return true;
        }
        return false;
    }

    /**
     * Allow only expected CSP modes.
     */
    private function sanitize_csp_mode($val): string
    {
        $val     = is_string($val) ? sanitize_key($val) : '';
        $allowed = ['strict', 'relaxed', 'off'];
        return in_array($val, $allowed, true) ? $val : 'strict';
    }
}
