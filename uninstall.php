<?php
/**
 * Uninstall Tor Onion Support
 *
 * Fired when the plugin is deleted from WordPress admin.
 * Cleans up options and site options created by the plugin.
 *
 * @package Onionify
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Helper to safely delete a list of options if they exist.
 */
function onion_support_delete_options(array $keys, bool $sitewide = false): void
{
    foreach ($keys as $key) {
        if ($sitewide && is_multisite()) {
            delete_site_option($key);
        } else {
            delete_option($key);
        }
    }
}

/**
 * Per-site options
 */
$single_options = [
    'onionify_onion_domain',
    'onionify_send_onion_location',
    'onionify_enable_hardening',
    'onionify_disable_oembed',
    'onionify_hardening_csp_mode',
    'onionify_hardening_csp_custom',
];

/**
 * Site-wide (network) options
 */
$network_options = [
    'onionify_onion_map',
    'onionify_default_onion_domain',
    'onionify_default_send_onion_location',
    'onionify_default_enable_hardening',
    'onionify_default_disable_oembed',
    'onionify_default_hardening_csp_mode',
    'onionify_default_hardening_csp_custom',
];

if (is_multisite()) {
    // For each site in the network, remove per-site options
    $sites = get_sites(['fields' => 'ids']);
    foreach ($sites as $site_id) {
        switch_to_blog($site_id);
        onion_support_delete_options($single_options, false);
        restore_current_blog();
    }

    // Then remove global site options
    onion_support_delete_options($network_options, true);
} else {
    onion_support_delete_options($single_options, false);
}
