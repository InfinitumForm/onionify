<?php
// uninstall.php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Per-site options
delete_option('onionify_onion_domain');
delete_option('onionify_send_onion_location');
delete_option('onionify_enable_hardening');
delete_option('onionify_disable_oembed');
delete_option('onionify_hardening_csp_mode');
delete_option('onionify_hardening_csp_custom');
delete_option('onionify_loopback_reroute');
delete_option('onionify_disable_external_avatars');

// Network options (Multisite)
if (is_multisite()) {
    delete_site_option('onionify_onion_map');
    delete_site_option('onionify_default_onion_domain');
    delete_site_option('onionify_default_send_onion_location');
    delete_site_option('onionify_default_enable_hardening');
    delete_site_option('onionify_default_disable_oembed');
    delete_site_option('onionify_default_hardening_csp_mode');
    delete_site_option('onionify_default_hardening_csp_custom');
}

// Transients / cache
delete_transient('onion_exit_list_blob');
