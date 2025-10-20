<?php

namespace Onionify\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Onionify\Domain\Detector;

/**
 * Settings registers admin UI for single-site and network (multisite),
 * provides Network Defaults via site_options, and per-site overrides.
 *
 * It also installs pre_option_* fallbacks so that when a per-site option
 * is not set, code reading get_option() will transparently receive the
 * network default (if present), without core edits.
 */
final class Settings
{
    private Detector $detector;

    /** @var array<string,array{type:string,default:mixed,site:string,network:string}> */
    private array $defs;

    public function __construct()
    {
        $this->detector = new Detector();

        // Central registry for option keys (per-site and network default counterparts).
        $this->defs = [
            'onionify_onion_domain' => [
                'type'    => 'string',
                'default' => '',
                'site'    => 'onionify_onion_domain',
                'network' => 'onionify_default_onion_domain',
            ],
            'onionify_send_onion_location' => [
                'type'    => 'boolean',
                'default' => true,
                'site'    => 'onionify_send_onion_location',
                'network' => 'onionify_default_send_onion_location',
            ],
            'onionify_enable_hardening' => [
                'type'    => 'boolean',
                'default' => false,
                'site'    => 'onionify_enable_hardening',
                'network' => 'onionify_default_enable_hardening',
            ],
            'onionify_disable_oembed' => [
                'type'    => 'boolean',
                'default' => true,
                'site'    => 'onionify_disable_oembed',
                'network' => 'onionify_default_disable_oembed',
            ],
            'onionify_hardening_csp_mode' => [
                'type'    => 'string',
                'default' => 'strict', // strict|relaxed|off|custom
                'site'    => 'onionify_hardening_csp_mode',
                'network' => 'onionify_default_hardening_csp_mode',
            ],
            'onionify_hardening_csp_custom' => [
                'type'    => 'string',
                'default' => '',
                'site'    => 'onionify_hardening_csp_custom',
                'network' => 'onionify_default_hardening_csp_custom',
            ],
        ];
    }

    /**
     * Hook admin pages and install pre_option_* fallbacks.
     */
    public function register(): void
    {
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_menu', [$this, 'addMenu']);

        if (is_multisite()) {
            add_action('network_admin_menu', [$this, 'addNetworkMenu']);
            add_action('network_admin_edit_onionify_save_network', [$this, 'saveNetworkSettings']);
            add_action('network_admin_edit_onionify_save_defaults', [$this, 'saveNetworkDefaults']);
        }

        // Install pre_option_* filters: when a site option is empty/unset,
        // transparently fall back to the corresponding site_option (network default).
        foreach ($this->defs as $key => $meta) {
            add_filter("pre_option_{$meta['site']}", function ($pre) use ($meta) {
                // If a per-site value exists (even "0" for booleans), return as-is.
                if ($pre !== false && $pre !== null && $pre !== '') {
                    return $pre;
                }
                // Otherwise, return network default (if set), else hard default.
                $net = get_site_option($meta['network'], $meta['default']);
                return $net;
            });
        }

        // Add contextual help (examples for Custom CSP).
        add_action('load-settings_page_onionify_settings', [$this, 'addSettingsHelpTab']);
        add_action('load-admin_page_onionify_network', [$this, 'addNetworkHelpTab']);
        add_action('load-admin_page_onionify_network_defaults', [$this, 'addNetworkHelpTab']);
    }

    /**
     * Register single-site settings and fields (with per-site overrides).
     */
    public function registerSettings(): void
    {
        // --- Register options with sanitizers ---
        register_setting('onionify_settings', 'onionify_onion_domain', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeHost'],
            'default'           => $this->defs['onionify_onion_domain']['default'],
        ]);

        register_setting('onionify_settings', 'onionify_send_onion_location', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitizeBool'],
            'default'           => $this->defs['onionify_send_onion_location']['default'],
        ]);

        register_setting('onionify_settings', 'onionify_enable_hardening', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitizeBool'],
            'default'           => $this->defs['onionify_enable_hardening']['default'],
        ]);

        register_setting('onionify_settings', 'onionify_disable_oembed', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitizeBool'],
            'default'           => $this->defs['onionify_disable_oembed']['default'],
        ]);

        register_setting('onionify_settings', 'onionify_loopback_reroute', [
            'type'              => 'boolean',
            'default'           => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);

        register_setting('onionify_settings', 'onionify_disable_external_avatars', [
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);

        register_setting('onionify_settings', 'onionify_hardening_csp_mode', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeCspMode'],
            'default'           => $this->defs['onionify_hardening_csp_mode']['default'],
        ]);

        register_setting('onionify_settings', 'onionify_hardening_csp_custom', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeCspString'],
            'default'           => $this->defs['onionify_hardening_csp_custom']['default'],
        ]);

        // --- Section + fields ---
        add_settings_section(
            'onionify_main',
            esc_html__('Onionify Settings', 'onionify'),
            function () {
                echo '<p>' . esc_html__('Configure onion address and privacy options for this site. If a value is not set here, the Network Default applies (Multisite).', 'onionify') . '</p>';
            },
            'onionify_settings'
        );

        // .onion domain
        add_settings_field(
            'onionify_onion_domain',
            esc_html__('.onion domain', 'onionify'),
            function () {
                $site_val   = get_option('onionify_onion_domain', '');
                $net_val    = is_multisite() ? (string) get_site_option('onionify_default_onion_domain', '') : '';
                $placeholder = $net_val !== '' ? $net_val : 'exampleonionaddress.onion';

                echo '<input type="text" class="regular-text" name="onionify_onion_domain" placeholder="' . esc_attr($placeholder) . '" value="' . esc_attr((string) $site_val) . '">';

                if (is_multisite()) {
                    echo '<p class="description">' . sprintf(
                        /* translators: 1: network default value */
                        esc_html__('Leave empty to inherit Network Default: %s', 'onionify'),
                        '<code>' . esc_html($net_val !== '' ? $net_val : __('(not set)', 'onionify')) . '</code>'
                    ) . '</p>';
                } else {
                    echo '<p class="description">' . esc_html__('Enter onion host only, without protocol (e.g., mysiteexample.onion).', 'onionify') . '</p>';
                }
            },
            'onionify_settings',
            'onionify_main'
        );

        // Onion-Location
        add_settings_field(
            'onionify_send_onion_location',
            esc_html__('Send Onion-Location from clearnet', 'onionify'),
            function () {
                $site_val = (bool) get_option('onionify_send_onion_location', $this->defs['onionify_send_onion_location']['default']);
                $net_val  = is_multisite() ? (bool) get_site_option('onionify_default_send_onion_location', $this->defs['onionify_send_onion_location']['default']) : null;

                echo '<label><input type="checkbox" name="onionify_send_onion_location" value="1" ' . checked($site_val, true, false) . '>';
                echo ' ' . esc_html__('Advertise the onion mirror via the Onion-Location header when visitors are on clearnet.', 'onionify') . '</label>';

                if (is_multisite()) {
                    echo '<p class="description">' . sprintf(
                        esc_html__('Leave unchecked and empty to inherit Network Default: %s', 'onionify'),
                        '<code>' . esc_html($net_val ? 'on' : 'off') . '</code>'
                    ) . '</p>';
                }
            },
            'onionify_settings',
            'onionify_main'
        );

        // Hardening enable
        add_settings_field(
            'onionify_enable_hardening',
            esc_html__('Enable onion privacy/security', 'onionify'),
            function () {
                $site_val = (bool) get_option('onionify_enable_hardening', $this->defs['onionify_enable_hardening']['default']);
                $net_val  = is_multisite() ? (bool) get_site_option('onionify_default_enable_hardening', $this->defs['onionify_enable_hardening']['default']) : null;

                echo '<label><input type="checkbox" name="onionify_enable_hardening" value="1" ' . checked($site_val, true, false) . '>';
                echo ' ' . esc_html__('Apply stricter privacy/security only for .onion requests.', 'onionify') . '</label>';

                if (is_multisite()) {
                    echo '<p class="description">' . sprintf(
                        esc_html__('Leave unchecked and empty to inherit Network Default: %s', 'onionify'),
                        '<code>' . esc_html($net_val ? 'on' : 'off') . '</code>'
                    ) . '</p>';
                }
            },
            'onionify_settings',
            'onionify_main'
        );

        // Loopback/cron reroute
        add_settings_field(
            'onionify_loopback_reroute',
            esc_html__('Reroute internal HTTP when on onion', 'onionify'),
            function () {
                $checked = (bool) get_option('onionify_loopback_reroute', true);
                echo '<label><input type="checkbox" name="onionify_loopback_reroute" value="1" ' . checked($checked, true, false) . '> ';
                echo esc_html__('Prevents loopback/cron/REST failures by calling the clearnet host for internal endpoints (wp-cron, admin-ajax, REST). Visitors remain on .onion; this only affects server-internal calls.', 'onionify');
                echo '</label>';
            },
            'onionify_settings',
            'onionify_main'
        );

        // External avatars
        add_settings_field(
            'onionify_disable_external_avatars',
            esc_html__('Disable external avatars on onion', 'onionify'),
            function () {
                $checked = (bool) get_option('onionify_disable_external_avatars', false);
                echo '<label><input type="checkbox" name="onionify_disable_external_avatars" value="1" ' . checked($checked, true, false) . '> ';
                echo esc_html__('Avoid loading avatars from third-party hosts (e.g., gravatar.com) for onion visitors. Replaces avatar URLs with a local data URI.', 'onionify');
                echo '</label>';
            },
            'onionify_settings',
            'onionify_main'
        );

        // Disable oEmbed
        add_settings_field(
            'onionify_disable_oembed',
            esc_html__('Disable oEmbed/embeds on .onion', 'onionify'),
            function () {
                $site_val = (bool) get_option('onionify_disable_oembed', $this->defs['onionify_disable_oembed']['default']);
                $net_val  = is_multisite() ? (bool) get_site_option('onionify_default_disable_oembed', $this->defs['onionify_disable_oembed']['default']) : null;

                echo '<label><input type="checkbox" name="onionify_disable_oembed" value="1" ' . checked($site_val, true, false) . '>';
                echo ' ' . esc_html__('Block external embeds (YouTube/Twitter/etc.) for onion visitors.', 'onionify') . '</label>';

                if (is_multisite()) {
                    echo '<p class="description">' . sprintf(
                        esc_html__('Leave unchecked and empty to inherit Network Default: %s', 'onionify'),
                        '<code>' . esc_html($net_val ? 'on' : 'off') . '</code>'
                    ) . '</p>';
                }
            },
            'onionify_settings',
            'onionify_main'
        );

        // CSP mode
        add_settings_field(
            'onionify_hardening_csp_mode',
            esc_html__('CSP mode (onion only)', 'onionify'),
            function () {
                $site_val = (string) get_option('onionify_hardening_csp_mode', $this->defs['onionify_hardening_csp_mode']['default']);
                $net_val  = is_multisite() ? (string) get_site_option('onionify_default_hardening_csp_mode', $this->defs['onionify_hardening_csp_mode']['default']) : '';

                echo '<select name="onionify_hardening_csp_mode">';
                $opts = ['strict' => 'Strict', 'relaxed' => 'Relaxed', 'off' => 'Off', 'custom' => 'Custom'];
                foreach ($opts as $k => $label) {
                    echo '<option value="' . esc_attr($k) . '" ' . selected($site_val, $k, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select>';

                $inherit_text = is_multisite()
                    ? sprintf(esc_html__('(Leave unchanged to inherit Network Default: %s)', 'onionify'), $net_val)
                    : '';
                echo '<p class="description">' .
                    esc_html__('Strict = safest (no inline scripts). Relaxed = allow inline scripts. Off = do not send CSP. Custom = send exactly what you enter below.', 'onionify')
                    . ' ' . esc_html($inherit_text) . '</p>';

                // Short, plain-English tip
                echo '<p class="description"><em>' . esc_html__('Tip: Start with Strict. If your theme/plugins break (e.g., inline JS), try Relaxed. Use Custom only if you know CSP syntax.', 'onionify') . '</em></p>';
            },
            'onionify_settings',
            'onionify_main'
        );

        // Custom CSP
        add_settings_field(
            'onionify_hardening_csp_custom',
            esc_html__('Custom CSP (if mode = Custom)', 'onionify'),
            function () {
                $site_val = (string) get_option('onionify_hardening_csp_custom', '');
                $net_val  = is_multisite() ? (string) get_site_option('onionify_default_hardening_csp_custom', '') : '';

                $placeholder = $net_val !== '' ? $net_val : "default-src 'self';\nscript-src 'self';\nstyle-src 'self' 'unsafe-inline';\nimg-src 'self' data:;";

                echo '<textarea name="onionify_hardening_csp_custom" class="large-text code" rows="5" placeholder="' . esc_attr($placeholder) . '">' . esc_textarea($site_val) . '</textarea>';

                echo '<p class="description">' . esc_html__('Enter a full CSP policy exactly as it should be sent. One directive per line or separated by semicolons.', 'onionify') . '</p>';

                // Friendly, copy-paste examples (kept concise here; extended versions in help tab)
                echo '<details><summary>' . esc_html__('Examples (click to expand)', 'onionify') . '</summary>';
                echo '<pre class="code" style="padding:10px; background:#f6f7f7; border:1px solid #ccd0d4; overflow:auto; white-space:pre;">';
                echo esc_html(
"1) Minimal clean WP (no external CDNs):
default-src 'self';
script-src 'self';
style-src 'self' 'unsafe-inline';
img-src 'self' data:;
font-src 'self' data:;
connect-src 'self';
frame-src 'self';
frame-ancestors 'self';
base-uri 'self';
form-action 'self';

2) Relaxed (inline JS allowed):
default-src 'self';
script-src 'self' 'unsafe-inline';
style-src 'self' 'unsafe-inline';
img-src 'self' data:;
font-src 'self' data:;
connect-src 'self';
frame-src 'self';
frame-ancestors 'self';
base-uri 'self';
form-action 'self';"
                );
                echo '</pre>';
                if (is_multisite()) {
                    echo '<p class="description">' . sprintf(
                        esc_html__('Leave empty to inherit Network Default (if set). Current Network Default: %s', 'onionify'),
                        '<code>' . ($net_val !== '' ? esc_html($net_val) : esc_html__('(not set)', 'onionify')) . '</code>'
                    ) . '</p>';
                }
            },
            'onionify_settings',
            'onionify_main'
        );
    }

    /**
     * Single-site page.
     */
    public function addMenu(): void
    {
        add_options_page(
            esc_html__('Onionify Settings', 'onionify'),
            esc_html__('.onion / Onionify', 'onionify'),
            'manage_options',
            'onionify_settings',
            [$this, 'renderSettingsPage']
        );
    }

    public function renderSettingsPage(): void
    {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Onionify Settings', 'onionify'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('onionify_settings');
                do_settings_sections('onionify_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /* -----------------------------------------------------------------
     * Multisite: Network menu with two pages:
     *  - Mapping page (existing): slug = onionify_network
     *  - Defaults page (NEW):     slug = onionify_network_defaults
     * ----------------------------------------------------------------- */

    public function addNetworkMenu(): void
    {
        add_menu_page(
            esc_html__('.onion / Onionify', 'onionify'),
            esc_html__('.onion / Onionify', 'onionify'),
            'manage_network_options',
            'onionify_network',
            [$this, 'renderNetworkPage'],
            'dashicons-shield'
        );

        add_submenu_page(
            'onionify_network',
            esc_html__('Network Defaults', 'onionify'),
            esc_html__('Network Defaults', 'onionify'),
            'manage_network_options',
            'onionify_network_defaults',
            [$this, 'renderNetworkDefaultsPage']
        );
    }

    /**
     * Existing mapping UI (per-blog .onion host).
     */
    public function renderNetworkPage(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to manage network options.', 'onionify'));
        }

        $sites = get_sites(['number' => 2000]);
        $map   = (array) get_site_option('onionify_onion_map', []);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('.onion / Onionify (Network)', 'onionify'); ?></h1>
            <form method="post" action="edit.php?action=onionify_save_network">
                <?php wp_nonce_field('onionify_network_save', 'onionify_network_nonce'); ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Site', 'onionify'); ?></th>
                            <th><?php esc_html_e('Clearnet URL', 'onionify'); ?></th>
                            <th><?php esc_html_e('.onion Host', 'onionify'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sites as $site):
                        $blog_id  = (int) $site->blog_id;
                        $details  = get_blog_details($blog_id);
                        $home_url = isset($details->home) ? (string) $details->home : '';
                        $onion_val = isset($map[$blog_id]) ? (string) $map[$blog_id] : '';
                    ?>
                        <tr>
                            <td><?php echo esc_html($details->blogname ?? "Blog #{$blog_id}"); ?> (ID: <?php echo (int) $blog_id; ?>)</td>
                            <td><code><?php echo esc_url($home_url); ?></code></td>
                            <td>
                                <input type="text" class="regular-text"
                                   name="onionify_onion_map[<?php echo esc_attr((string) $blog_id); ?>]"
                                   placeholder="exampleonionaddress.onion"
                                   value="<?php echo esc_attr($onion_val); ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button(esc_html__('Save Mapping', 'onionify')); ?>
            </form>
        </div>
        <?php
    }

    public function saveNetworkSettings(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to manage network options.', 'onionify'));
        }
        check_admin_referer('onionify_network_save', 'onionify_network_nonce');

        // Sanitize POST payload: wp_unslash before sanitization per WP coding standards.
        $input_raw = isset($_POST['onionify_onion_map']) ? $_POST['onionify_onion_map'] : [];
        $input     = is_array($input_raw) ? wp_unslash($input_raw) : [];
        $clean     = [];

        foreach ($input as $blog_id => $host) {
            $blog_id = (int) $blog_id;
            // Ensure string before sanitize.
            $host    = $this->sanitizeHost((string) $host);
            if ($blog_id > 0 && $host !== '') {
                $clean[$blog_id] = $host;
            }
        }

        update_site_option('onionify_onion_map', $clean);
        wp_safe_redirect(network_admin_url('admin.php?page=onionify_network&updated=1'));
        exit;
    }

    /**
     * NEW: Network Defaults page.
     */
    public function renderNetworkDefaultsPage(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to manage network options.', 'onionify'));
        }

        // Effective defaults (site_option-level)
        $vals = [
            'onionify_default_onion_domain'          => (string) get_site_option('onionify_default_onion_domain', ''),
            'onionify_default_send_onion_location'   => (bool)   get_site_option('onionify_default_send_onion_location', $this->defs['onionify_send_onion_location']['default']),
            'onionify_default_enable_hardening'      => (bool)   get_site_option('onionify_default_enable_hardening', $this->defs['onionify_enable_hardening']['default']),
            'onionify_default_disable_oembed'        => (bool)   get_site_option('onionify_default_disable_oembed', $this->defs['onionify_disable_oembed']['default']),
            'onionify_default_hardening_csp_mode'    => (string) get_site_option('onionify_default_hardening_csp_mode', $this->defs['onionify_hardening_csp_mode']['default']),
            'onionify_default_hardening_csp_custom'  => (string) get_site_option('onionify_default_hardening_csp_custom', $this->defs['onionify_hardening_csp_custom']['default']),
        ];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('.onion / Onionify – Network Defaults', 'onionify'); ?></h1>
            <form method="post" action="edit.php?action=onionify_save_defaults">
                <?php wp_nonce_field('onionify_defaults_save', 'onionify_defaults_nonce'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="onionify_default_onion_domain"><?php esc_html_e('Default .onion domain', 'onionify'); ?></label></th>
                        <td>
                            <input type="text" class="regular-text" id="onionify_default_onion_domain" name="onionify_default_onion_domain" placeholder="exampleonionaddress.onion" value="<?php echo esc_attr($vals['onionify_default_onion_domain']); ?>">
                            <p class="description"><?php esc_html_e('This host will be used by sites that did not set their own .onion domain.', 'onionify'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Onion-Location header (clearnet)', 'onionify'); ?></th>
                        <td>
                            <label><input type="checkbox" name="onionify_default_send_onion_location" value="1" <?php checked($vals['onionify_default_send_onion_location']); ?>>
                                <?php esc_html_e('Advertise the onion mirror from clearnet by default.', 'onionify'); ?></label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Enable onion privacy/security (default)', 'onionify'); ?></th>
                        <td>
                            <label><input type="checkbox" name="onionify_default_enable_hardening" value="1" <?php checked($vals['onionify_default_enable_hardening']); ?>>
                                <?php esc_html_e('Apply stricter privacy/security for .onion requests by default.', 'onionify'); ?></label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Disable oEmbed on .onion (default)', 'onionify'); ?></th>
                        <td>
                            <label><input type="checkbox" name="onionify_default_disable_oembed" value="1" <?php checked($vals['onionify_default_disable_oembed']); ?>>
                                <?php esc_html_e('Block external embeds for onion visitors by default.', 'onionify'); ?></label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="onionify_default_hardening_csp_mode"><?php esc_html_e('CSP mode (default)', 'onionify'); ?></label></th>
                        <td>
                            <select id="onionify_default_hardening_csp_mode" name="onionify_default_hardening_csp_mode">
                                <?php
                                foreach (['strict' => 'Strict', 'relaxed' => 'Relaxed', 'off' => 'Off', 'custom' => 'Custom'] as $k => $label) {
                                    echo '<option value="' . esc_attr($k) . '" ' . selected($vals['onionify_default_hardening_csp_mode'], $k, false) . '>' . esc_html($label) . '</option>';
                                }
                                ?>
                            </select>
                            <p class="description"><?php esc_html_e('This is the default CSP mode for all sites; individual sites may override it.', 'onionify'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="onionify_default_hardening_csp_custom"><?php esc_html_e('Custom CSP (default if mode=Custom)', 'onionify'); ?></label></th>
                        <td>
                            <textarea class="large-text code" rows="5" id="onionify_default_hardening_csp_custom" name="onionify_default_hardening_csp_custom" placeholder="default-src 'self';&#10;script-src 'self';&#10;style-src 'self' 'unsafe-inline';&#10;img-src 'self' data:;"><?php echo esc_textarea($vals['onionify_default_hardening_csp_custom']); ?></textarea>
                            <p class="description"><?php esc_html_e('Provide a full CSP policy used as the default for sites selecting Custom mode and not overriding locally.', 'onionify'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(esc_html__('Save Defaults', 'onionify')); ?>
            </form>
        </div>
        <?php
    }

    public function saveNetworkDefaults(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to manage network options.', 'onionify'));
        }
        check_admin_referer('onionify_defaults_save', 'onionify_defaults_nonce');

        // Sanitize POST payloads using wp_unslash() before custom sanitizers.
        $domain_raw   = $_POST['onionify_default_onion_domain']         ?? '';
        $send_raw     = $_POST['onionify_default_send_onion_location']  ?? '0';
        $hard_raw     = $_POST['onionify_default_enable_hardening']     ?? '0';
        $oembed_raw   = $_POST['onionify_default_disable_oembed']       ?? '0';
        $csp_mode_raw = $_POST['onionify_default_hardening_csp_mode']   ?? 'strict';
        $csp_txt_raw  = $_POST['onionify_default_hardening_csp_custom'] ?? '';

        $domain   = $this->sanitizeHost( (string) wp_unslash( $domain_raw ) );
        $send     = $this->sanitizeBool( wp_unslash( $send_raw ) );
        $hard     = $this->sanitizeBool( wp_unslash( $hard_raw ) );
        $oembed   = $this->sanitizeBool( wp_unslash( $oembed_raw ) );
        $csp_mode = $this->sanitizeCspMode( (string) wp_unslash( $csp_mode_raw ) );
        $csp_txt  = $this->sanitizeCspString( (string) wp_unslash( $csp_txt_raw ) );

        // Save network defaults.
        update_site_option('onionify_default_onion_domain',         $domain);
        update_site_option('onionify_default_send_onion_location',  $send);
        update_site_option('onionify_default_enable_hardening',     $hard);
        update_site_option('onionify_default_disable_oembed',       $oembed);
        update_site_option('onionify_default_hardening_csp_mode',   $csp_mode);
        update_site_option('onionify_default_hardening_csp_custom', $csp_txt);

        wp_safe_redirect(network_admin_url('admin.php?page=onionify_network_defaults&updated=1'));
        exit;
    }

    /* ------------------- Sanitizers ------------------- */

    public function sanitizeHost($host): string
    {
        // Defensive: strip tags and normalize.
        $host = sanitize_text_field( (string) $host );
        $host = strtolower( trim( $host ) );

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

    public function sanitizeBool($val): bool
    {
        // Accepts '1', 1, true, 'on' as true; everything else false.
        if ($val === true || $val === 1 || $val === '1' || $val === 'on') {
            return true;
        }
        return false;
    }

    public function sanitizeCspMode($val): string
    {
        $val = sanitize_text_field( (string) $val );
        $allowed = ['strict', 'relaxed', 'off', 'custom'];
        return in_array($val, $allowed, true) ? $val : 'strict';
    }

    public function sanitizeCspString($val): string
    {
        // Keep as text; trim trailing spaces. Do not try to parse CSP (admin may paste advanced policies).
        $val = (string) $val;
        $val = wp_kses_no_null( $val );
        $val = trim( $val );
        // Normalize newlines (CRLF -> LF) to avoid header formatting issues when rendered.
        $val = str_replace(["\r\n", "\r"], "\n", $val);
        return $val;
    }

    /* ----------------- Help tabs (CSP guide) ----------------- */

    /**
     * Add contextual help tab for the site settings page.
     */
    public function addSettingsHelpTab(): void
    {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        $title = esc_html__('CSP Help', 'onionify');
        $content  = '<p><strong>' . esc_html__('What is CSP?', 'onionify') . '</strong> '
                  . esc_html__('Content-Security-Policy controls which resources your site is allowed to load. It helps avoid unnecessary third-party calls in onion mode.', 'onionify')
                  . '</p>';
        $content .= '<p><strong>' . esc_html__('Modes:', 'onionify') . '</strong> '
                  . esc_html__('Strict (safest), Relaxed (allows inline JS), Off (no CSP header), Custom (send exactly what you enter).', 'onionify')
                  . '</p>';
        $content .= '<p><strong>' . esc_html__('Quick examples:', 'onionify') . '</strong></p>'
                  . '<pre>' . esc_html(
"default-src 'self';
script-src 'self';
style-src 'self' 'unsafe-inline';
img-src 'self' data:;
font-src 'self' data:;
connect-src 'self';
frame-src 'self';
frame-ancestors 'self';
base-uri 'self';
form-action 'self';"
                  ) . '</pre>';
        $content .= '<p><em>' . esc_html__('Tips: If admin bar or theme scripts break, switch to Relaxed or add only what you need. Avoid external CDNs in onion mode whenever possible.', 'onionify') . '</em></p>';

        $screen->add_help_tab([
            'id'      => 'onionify_csp_help',
            'title'   => $title,
            'content' => wp_kses_post($content),
        ]);
    }

    /**
     * Add contextual help tab for network pages (mapping/defaults).
     */
    public function addNetworkHelpTab(): void
    {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        $title = esc_html__('Network Defaults & Mapping', 'onionify');
        $content  = '<p><strong>' . esc_html__('Network Defaults', 'onionify') . '</strong> '
                  . esc_html__('apply to all sites that did not set their own values. A site may override any default in its own Settings → .onion / Onionify page.', 'onionify')
                  . '</p>';
        $content .= '<p><strong>' . esc_html__('Mapping', 'onionify') . '</strong> '
                  . esc_html__('lets you assign a specific .onion host per site. If a site has no mapping, it may still use the global Default .onion domain.', 'onionify')
                  . '</p>';

        $screen->add_help_tab([
            'id'      => 'onionify_network_help',
            'title'   => $title,
            'content' => wp_kses_post($content),
        ]);
    }
}
