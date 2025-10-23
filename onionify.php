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

/* -------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------- */
define('ONIONIFY_VERSION', '1.0.0');
define('ONIONIFY_PLUGIN_FILE', __FILE__);
define('ONIONIFY_PLUGIN_DIR', rtrim(plugin_dir_path(__FILE__), '/'));
define('ONIONIFY_PLUGIN_URL', rtrim(plugin_dir_url(__FILE__), '/'));

/* -------------------------------------------------------------------------
 * PHP version guard (bail early on older PHP)
 * ---------------------------------------------------------------------- */
if (version_compare(PHP_VERSION, '7.4', '<')) {
    // Admin notice on unsupported PHP; no plugin init.
    add_action('admin_notices', static function () {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Onionify requires PHP 7.4 or newer. Please upgrade PHP to use this plugin.', 'onionify');
        echo '</p></div>';
    });
    return;
}

/* -------------------------------------------------------------------------
 * Simple PSR-4 autoloader for this plugin
 * ---------------------------------------------------------------------- */
if (!defined('ONIONIFY_AUTOLOADER_REGISTERED')) {
    define('ONIONIFY_AUTOLOADER_REGISTERED', true);

    spl_autoload_register(function ($class) {
        static $cache = [];

        $prefix   = 'Onionify\\';
        $base_dir = ONIONIFY_PLUGIN_DIR . '/src/';

        // Ensure class uses our namespace
        $len = strlen($prefix);

        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        // Check cache first
        if (isset($cache[$class])) {
            if ($cache[$class]) {
                require_once $cache[$class];
            }

            return;
        }

        // Get relative class name
        $relative_class = substr($class, $len);

        // Convert namespace to path
        $file = $base_dir . str_replace('\\', DIRECTORY_SEPARATOR, $relative_class) . '.php';

        // Check and cache
        if (file_exists($file)) {
            $cache[$class] = $file;
            require_once $file;
        } else {
            $cache[$class] = false;
        }
    });
}

/* -------------------------------------------------------------------------
 * Bootstrap the plugin after all plugins are loaded
 * ---------------------------------------------------------------------- */
add_action('plugins_loaded', static function () {
    // Defensive: ensure Bootstrap class exists before calling.
    if (class_exists('\Onionify\Bootstrap')) {
        (new \Onionify\Bootstrap())->init();
    } else {
        // Surface a clear admin notice if autoload failed.
        add_action('admin_notices', static function () {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Onionify could not initialize: Bootstrap class not found. Please verify plugin files are complete.', 'onionify');
            echo '</p></div>';
        });
    }
});
