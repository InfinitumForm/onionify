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
            $map = (array) get_site_option('onionify_onion_map', []);
            $items = [];
            foreach (get_sites(['number' => 2000]) as $site) {
                $blog_id = (int) $site->blog_id;
                $details = get_blog_details($blog_id);
                $items[] = [
                    'blog_id' => $blog_id,
                    'domain'  => $details->domain,
                    'path'    => $details->path,
                    'onion'   => $map[$blog_id] ?? '',
                ];
            }
            WP_CLI\Utils\format_items('table', $items, ['blog_id','domain','path','onion']);
        } else {
            WP_CLI::log('Single-site:');
            WP_CLI::log('  Home:  ' . get_option('home'));
            WP_CLI::log('  Onion: ' . (get_option('onionify_onion_domain') ?: '(not set)'));
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
        [$id, $host] = $args;

        if ((int) $id === 0 && !is_multisite()) {
            update_option('onionify_onion_domain', $host);
            WP_CLI::success('Set single-site onion host to: ' . $host);
            return;
        }

        if (!is_multisite()) {
            WP_CLI::error('Multisite not enabled. Use blog_id=0 for single-site.');
        }

        $map = (array) get_site_option('onionify_onion_map', []);
        $map[(int) $id] = $host;
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
        if (isset($assoc_args['hardening'])) {
            update_option('onionify_enable_hardening', $assoc_args['hardening'] === 'on');
        }
        if (isset($assoc_args['oembed'])) {
            update_option('onionify_disable_oembed', $assoc_args['oembed'] !== 'on' ? true : false);
        }
        if (isset($assoc_args['csp'])) {
            $allowed = ['strict','relaxed','off'];
            $mode = in_array($assoc_args['csp'], $allowed, true) ? $assoc_args['csp'] : 'strict';
            update_option('onionify_hardening_csp_mode', $mode);
        }
        WP_CLI::success('Settings updated.');
    }
}
