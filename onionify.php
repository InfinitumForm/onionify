<?php
/**
 * Plugin Name: Onionify
 * Description: Serve WordPress cleanly over .onion with URL rewriting, Onion-Location, and privacy hardening.
 * Version: 1.0.0
 * Author: Ivijan-Stefan Stipić
 * Author URI: https://www.linkedin.com/in/ivijanstefanstipic/
 * License: GPLv2 or later
 * Text Domain: onionify
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

// Simple PSR-4 autoloader for this plugin.
spl_autoload_register(function ($class) {
    $prefix   = 'Onionify\\';
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

// Bootstrap the plugin after all plugins are loaded.
add_action('plugins_loaded', static function () {
    (new \Onionify\Bootstrap())->init();
});