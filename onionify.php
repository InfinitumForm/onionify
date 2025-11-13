<?php
/**
 * Plugin Name:  Onionify
 * Description:  Serve WordPress cleanly over .onion with URL rewriting, Onion-Location, and privacy hardening.
 * Version:      1.0.3
 * Author:       Ivijan-Stefan Stipić
 * Author URI:   https://orcid.org/0009-0008-3924-8683
 * License:      GPLv2 or later
 * Text Domain:  onionify
 * Domain Path:  /languages
 * Network:      true
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* -------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------- */
define('ONIONIFY_VERSION', '1.0.3');
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

\register_activation_hook(ONIONIFY_PLUGIN_FILE, function ($network_wide) {
    $key = 'onionify_welcome_pending';
    if (is_multisite() && !empty($network_wide)) {
        update_site_option($key, 1);
    } else {
        update_option($key, 1);
    }
});

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
