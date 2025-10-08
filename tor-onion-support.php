<?php
/**
 * Plugin Name:       Tor Onion Support
 * Plugin URI:        https://wordpress.org/plugins/tor-onion-support/
 * Description:       Seamless .onion support for WordPress and Multisite without core modifications. Sets URLs, headers, and canonical behavior for Tor hidden services.
 * Version:           1.0.0
 * Author:            INFINITUM FORM
 * Author URI:        https://infinitumform.com/
 * Text Domain:       tor-onion-support
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPLv2 or later
 *
 * @package TorOnionSupport
 */

if (!defined('ABSPATH')) {
    exit;
}

// Simple PSR-4 autoloader for this plugin.
spl_autoload_register(function ($class) {
    $prefix   = 'TorOnionSupport\\';
    $base_dir = __DIR__ . '/src/';
    $len      = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

add_action('plugins_loaded', static function () {
    // Bootstrap the plugin after all plugins are loaded.
    (new \TorOnionSupport\Bootstrap())->init();
});

// Load text domain for translations (WP.org ready).
add_action('init', static function () {
    load_plugin_textdomain(
        'tor-onion-support',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});