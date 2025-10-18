<?php
/**
 * Plugin Name: Onionify
 * Description: Serve WordPress cleanly over .onion with URL rewriting, Onion-Location, and privacy hardening.
 * Version: 1.0.0
 * Author: INFINITUM FORM
 * License: GPLv2 or later
 * Text Domain: tor-onion-support
 * Domain Path: /languages
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