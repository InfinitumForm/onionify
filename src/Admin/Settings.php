<?php

namespace TorOnionSupport\Admin;

use TorOnionSupport\Domain\Detector;

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
            'tos_onion_domain' => [
                'type'    => 'string',
                'default' => '',
                'site'    => 'tos_onion_domain',
                'network' => 'tos_default_onion_domain',
            ],
            'tos_send_onion_location' => [
                'type'    => 'boolean',
                'default' => true,
                'site'    => 'tos_send_onion_location',
                'network' => 'tos_default_send_onion_location',
            ],
            'tos_enable_hardening' => [
                'type'    => 'boolean',
                'default' => false,
                'site'    => 'tos_enable_hardening',
                'network' => 'tos_default_enable_hardening',
            ],
            'tos_disable_oembed' => [
                'type'    => 'boolean',
                'default' => true,
                'site'    => 'tos_disable_oembed',
                'network' => 'tos_default_disable_oembed',
            ],
            'tos_hardening_csp_mode' => [
                'type'    => 'string',
                'default' => 'strict', // strict|relaxed|off|custom
                'site'    => 'tos_hardening_csp_mode',
                'network' => 'tos_default_hardening_csp_mode',
            ],
            'tos_hardening_csp_custom' => [
                'type'    => 'string',
                'default' => '',
                'site'    => 'tos_hardening_csp_custom',
                'network' => 'tos_default_hardening_csp_custom',
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
            add_action('network_admin_edit_tos_save_network', [$this, 'saveNetworkSettings']);
            add_action('network_admin_edit_tos_save_defaults', [$this, 'saveNetworkDefaults']);
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
        add_action('load-settings_page_tos_settings', [$this, 'addSettingsHelpTab']);
        add_action('load-admin_page_tos_network', [$this, 'addNetworkHelpTab']);
        add_action('load-admin_page_tos_network_defaults', [$this, 'addNetworkHelpTab']);
    }

    /**
     * Register single-site settings and fields (with per-site overrides).
     */
    public function registerSettings(): void
    {
        // --- Register options with sanitizers ---
        register_setting('tos_settings', 'tos_onion_domain', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeHost'],
            'default'           => $this->defs['tos_onion_domain']['default'],
        ]);

        register_setting('tos_settings', 'tos_send_onion_location', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitizeBool'],
            'default'           => $this->defs['tos_send_onion_location']['default'],
        ]);

        register_setting('tos_settings', 'tos_enable_hardening', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitizeBool'],
            'default'           => $this->defs['tos_enable_hardening']['default'],
        ]);

        register_setting('tos_settings', 'tos_disable_oembed', [
            'type'              => 'boolean',
            'sanitize_callback' => [$this, 'sanitizeBool'],
            'default'           => $this->defs['tos_disable_oembed']['default'],
        ]);
		
		register_setting('tos_settings', 'tos_loopback_reroute', [
			'type' => 'boolean',
			'default' => true,
		]);

		register_setting('tos_settings', 'tos_disable_external_avatars', [
			'type' => 'boolean',
			'default' => false,
		]);

        register_setting('tos_settings', 'tos_hardening_csp_mode', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeCspMode'],
            'default'           => $this->defs['tos_hardening_csp_mode']['default'],
        ]);

        register_setting('tos_settings', 'tos_hardening_csp_custom', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeCspString'],
            'default'           => $this->defs['tos_hardening_csp_custom']['default'],
        ]);

        // --- Section + fields ---
        add_settings_section(
            'tos_main',
            esc_html__('Tor Onion Support', 'tor-onion-support'),
            function () {
                echo '<p>' . esc_html__('Configure .onion alias and privacy hardening for this site. If a value is not set here, the Network Default applies (Multisite).', 'tor-onion-support') . '</p>';
            },
            'tos_settings'
        );

        // .onion domain
        add_settings_field(
            'tos_onion_domain',
            esc_html__('.onion domain', 'tor-onion-support'),
            function () {
                $site_val = get_option('tos_onion_domain', '');
                $net_val  = is_multisite() ? (string) get_site_option('tos_default_onion_domain', '') : '';
                $placeholder = $net_val !== '' ? $net_val : 'exampleonionaddress.onion';
                echo '<input type="text" class="regular-text" name="tos_onion_domain" placeholder="' . esc_attr($placeholder) . '" value="' . esc_attr((string) $site_val) . '">';
                if (is_multisite()) {
                    echo '<p class="description">' . sprintf(
                        /* translators: 1: network default value */
                        esc_html__('Leave empty to inherit Network Default: %s', 'tor-onion-support'),
                        '<code>' . esc_html($net_val ?: __('(not set)', 'tor-onion-support')) . '</code>'
                    ) . '</p>';
                } else {
                    echo '<p class="description">' . esc_html__('Enter onion host only, without protocol (e.g., mysiteexample.onion).', 'tor-onion-support') . '</p>';
                }
            },
            'tos_settings',
            'tos_main'
        );

        // Onion-Location
        add_settings_field(
            'tos_send_onion_location',
            esc_html__('Send Onion-Location from clearnet', 'tor-onion-support'),
            function () {
                $site_val = (bool) get_option('tos_send_onion_location', $this->defs['tos_send_onion_location']['default']);
                $net_val  = is_multisite() ? (bool) get_site_option('tos_default_send_onion_location', $this->defs['tos_send_onion_location']['default']) : null;

                echo '<label><input type="checkbox" name="tos_send_onion_location" value="1" ' . checked($site_val, true, false) . '>';
                echo ' ' . esc_html__('Advertise the onion mirror via Onion-Location header when visitors are on clearnet.', 'tor-onion-support') . '</label>';

                if (is_multisite()) {
                    echo '<p class="description">' . sprintf(
                        esc_html__('Leave unchecked and empty to inherit Network Default: %s', 'tor-onion-support'),
                        '<code>' . esc_html($net_val ? 'on' : 'off') . '</code>'
                    ) . '</p>';
                }
            },
            'tos_settings',
            'tos_main'
        );

        // Hardening enable
        add_settings_field(
            'tos_enable_hardening',
            esc_html__('Enable onion hardening', 'tor-onion-support'),
            function () {
                $site_val = (bool) get_option('tos_enable_hardening', $this->defs['tos_enable_hardening']['default']);
                $net_val  = is_multisite() ? (bool) get_site_option('tos_default_enable_hardening', $this->defs['tos_enable_hardening']['default']) : null;

                echo '<label><input type="checkbox" name="tos_enable_hardening" value="1" ' . checked($site_val, true, false) . '>';
                echo ' ' . esc_html__('Apply stricter privacy/security only for .onion requests.', 'tor-onion-support') . '</label>';

                if (is_multisite()) {
                    echo '<p class="description">' . sprintf(
                        esc_html__('Leave unchecked and empty to inherit Network Default: %s', 'tor-onion-support'),
                        '<code>' . esc_html($net_val ? 'on' : 'off') . '</code>'
                    ) . '</p>';
                }
            },
            'tos_settings',
            'tos_main'
        );
		
		// Loopback/cron reroute
		add_settings_field(
			'tos_loopback_reroute',
			esc_html__('Reroute internal HTTP when on onion', 'tor-onion-support'),
			function () {
				$checked = (bool) get_option('tos_loopback_reroute', true);
				echo '<label><input type="checkbox" name="tos_loopback_reroute" value="1" ' . checked($checked, true, false) . '> ';
				echo esc_html__('Prevents loopback/cron/REST failures by calling the clearnet host for internal endpoints (wp-cron, admin-ajax, REST). Visitors remain on .onion; this only affects server-internal calls.', 'tor-onion-support');
				echo '</label>';
			},
			'tos_settings',
			'tos_main'
		);

		// External avatars
		add_settings_field(
			'tos_disable_external_avatars',
			esc_html__('Disable external avatars on onion', 'tor-onion-support'),
			function () {
				$checked = (bool) get_option('tos_disable_external_avatars', false);
				echo '<label><input type="checkbox" name="tos_disable_external_avatars" value="1" ' . checked($checked, true, false) . '> ';
				echo esc_html__('Avoid loading avatars from third-party hosts (e.g., gravatar.com) for onion visitors. Replaces avatar URLs with a local data URI.', 'tor-onion-support');
				echo '</label>';
			},
			'tos_settings',
			'tos_main'
		);


        // Disable oEmbed
        add_settings_field(
            'tos_disable_oembed',
            esc_html__('Disable oEmbed/embeds on .onion', 'tor-onion-support'),
            function () {
                $site_val = (bool) get_option('tos_disable_oembed', $this->defs['tos_disable_oembed']['default']);
                $net_val  = is_multisite() ? (bool) get_site_option('tos_default_disable_oembed', $this->defs['tos_disable_oembed']['default']) : null;

                echo '<label><input type="checkbox" name="tos_disable_oembed" value="1" ' . checked($site_val, true, false) . '>';
                echo ' ' . esc_html__('Block external embeds (YouTube/Twitter/etc.) for onion visitors.', 'tor-onion-support') . '</label>';

                if (is_multisite()) {
                    echo '<p class="description">' . sprintf(
                        esc_html__('Leave unchecked and empty to inherit Network Default: %s', 'tor-onion-support'),
                        '<code>' . esc_html($net_val ? 'on' : 'off') . '</code>'
                    ) . '</p>';
                }
            },
            'tos_settings',
            'tos_main'
        );

        // CSP mode
        add_settings_field(
            'tos_hardening_csp_mode',
            esc_html__('CSP mode (onion only)', 'tor-onion-support'),
            function () {
                $site_val = (string) get_option('tos_hardening_csp_mode', $this->defs['tos_hardening_csp_mode']['default']);
                $net_val  = is_multisite() ? (string) get_site_option('tos_default_hardening_csp_mode', $this->defs['tos_hardening_csp_mode']['default']) : '';

                echo '<select name="tos_hardening_csp_mode">';
                $opts = ['strict' => 'Strict', 'relaxed' => 'Relaxed', 'off' => 'Off', 'custom' => 'Custom'];
                foreach ($opts as $k => $label) {
                    echo '<option value="' . esc_attr($k) . '" ' . selected($site_val, $k, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select>';

                $inherit_text = is_multisite()
					? sprintf( esc_html__('(Leave unchanged to inherit Network Default: %s)', 'tor-onion-support'), $net_val )
					: '';
				echo '<p class="description">' .
					esc_html__('Strict = safest (no inline scripts). Relaxed = allow inline scripts. Off = do not send CSP. Custom = send exactly what you enter below.', 'tor-onion-support')
					. ' ' . esc_html($inherit_text) . '</p>';

                // Short, plain-English tip
                echo '<p class="description"><em>' . esc_html__('Tip: Start with Strict. If your theme/plugins break (e.g., inline JS), try Relaxed. Use Custom only if you know CSP syntax.', 'tor-onion-support') . '</em></p>';
            },
            'tos_settings',
            'tos_main'
        );

        // Custom CSP
        add_settings_field(
            'tos_hardening_csp_custom',
            esc_html__('Custom CSP (if mode = Custom)', 'tor-onion-support'),
            function () {
                $site_val = (string) get_option('tos_hardening_csp_custom', '');
                $net_val  = is_multisite() ? (string) get_site_option('tos_default_hardening_csp_custom', '') : '';

                $placeholder = $net_val !== '' ? $net_val : "default-src 'self';\nscript-src 'self';\nstyle-src 'self' 'unsafe-inline';\nimg-src 'self' data:;";

                echo '<textarea name="tos_hardening_csp_custom" class="large-text code" rows="5" placeholder="' . esc_attr($placeholder) . '">' . esc_textarea($site_val) . '</textarea>';

                echo '<p class="description">' . esc_html__('Enter a full CSP policy exactly as it should be sent. One directive per line or separated by semicolons.', 'tor-onion-support') . '</p>';

                // Friendly, copy-paste examples (kept concise here; extended versions in help tab)
                echo '<details><summary>' . esc_html__('Examples (click to expand)', 'tor-onion-support') . '</summary>';
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
                        esc_html__('Leave empty to inherit Network Default (if set). Current Network Default: %s', 'tor-onion-support'),
                        '<code>' . ($net_val !== '' ? esc_html($net_val) : esc_html__('(not set)', 'tor-onion-support')) . '</code>'
                    ) . '</p>';
                }
            },
            'tos_settings',
            'tos_main'
        );
    }

    /**
     * Single-site page.
     */
    public function addMenu(): void
    {
        add_options_page(
            esc_html__('Tor Onion Support', 'tor-onion-support'),
            esc_html__('Tor / .onion', 'tor-onion-support'),
            'manage_options',
            'tos_settings',
            [$this, 'renderSettingsPage']
        );
    }

    public function renderSettingsPage(): void
    {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Tor Onion Support', 'tor-onion-support'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('tos_settings');
                do_settings_sections('tos_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /* -----------------------------------------------------------------
     * Multisite: Network menu with two pages:
     *  - Mapping page (existing): slug = tos_network
     *  - Defaults page (NEW):     slug = tos_network_defaults
     * ----------------------------------------------------------------- */

    public function addNetworkMenu(): void
    {
        add_menu_page(
            esc_html__('Tor / .onion', 'tor-onion-support'),
            esc_html__('Tor / .onion', 'tor-onion-support'),
            'manage_network_options',
            'tos_network',
            [$this, 'renderNetworkPage'],
            'dashicons-shield'
        );

        add_submenu_page(
            'tos_network',
            esc_html__('Network Defaults', 'tor-onion-support'),
            esc_html__('Network Defaults', 'tor-onion-support'),
            'manage_network_options',
            'tos_network_defaults',
            [$this, 'renderNetworkDefaultsPage']
        );
    }

    /**
     * Existing mapping UI (per-blog .onion host).
     */
    public function renderNetworkPage(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to manage network options.', 'tor-onion-support'));
        }

        $sites = get_sites(['number' => 2000]);
        $map   = (array) get_site_option('tos_onion_map', []);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Tor Onion Support (Network)', 'tor-onion-support'); ?></h1>
            <form method="post" action="edit.php?action=tos_save_network">
                <?php wp_nonce_field('tos_network_save', 'tos_network_nonce'); ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Site', 'tor-onion-support'); ?></th>
                            <th><?php esc_html_e('Clearnet URL', 'tor-onion-support'); ?></th>
                            <th><?php esc_html_e('.onion Host', 'tor-onion-support'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sites as $site):
                        $blog_id   = (int) $site->blog_id;
                        $details   = get_blog_details($blog_id);
                        $home_url  = $details->home ?? '';
						$onion_val = $map[$blog_id] ?? '';
                    ?>
                        <tr>
                            <td><?php echo esc_html($details->blogname ?? "Blog #{$blog_id}"); ?> (ID: <?php echo (int) $blog_id; ?>)</td>
                            <td><code><?php echo esc_html( esc_url( $home_url ) ); ?></code></td>
                            <td>
                                <input type="text" class="regular-text"
								   name="tos_onion_map[<?php echo (int) esc_attr( $blog_id ); ?>]"
								   placeholder="exampleonionaddress.onion"
								   value="<?php echo esc_attr( $onion_val ); ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button(esc_html__('Save Mapping', 'tor-onion-support')); ?>
            </form>
        </div>
        <?php
    }

    public function saveNetworkSettings(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to manage network options.', 'tor-onion-support'));
        }
        check_admin_referer('tos_network_save', 'tos_network_nonce');

        $input = $_POST['tos_onion_map'] ?? [];
        $clean = [];

        foreach ((array) $input as $blog_id => $host) {
            $blog_id = (int) $blog_id;
            $host    = $this->sanitizeHost($host);
            if ($blog_id > 0 && $host) {
                $clean[$blog_id] = $host;
            }
        }

        update_site_option('tos_onion_map', $clean);
        wp_safe_redirect(network_admin_url('admin.php?page=tos_network&updated=1'));
        exit;
    }

    /**
     * NEW: Network Defaults page.
     */
    public function renderNetworkDefaultsPage(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to manage network options.', 'tor-onion-support'));
        }

        // Effective defaults (site_option-level)
        $vals = [
            'tos_default_onion_domain'          => (string) get_site_option('tos_default_onion_domain', ''),
            'tos_default_send_onion_location'   => (bool)   get_site_option('tos_default_send_onion_location', $this->defs['tos_send_onion_location']['default']),
            'tos_default_enable_hardening'      => (bool)   get_site_option('tos_default_enable_hardening', $this->defs['tos_enable_hardening']['default']),
            'tos_default_disable_oembed'        => (bool)   get_site_option('tos_default_disable_oembed', $this->defs['tos_disable_oembed']['default']),
            'tos_default_hardening_csp_mode'    => (string) get_site_option('tos_default_hardening_csp_mode', $this->defs['tos_hardening_csp_mode']['default']),
            'tos_default_hardening_csp_custom'  => (string) get_site_option('tos_default_hardening_csp_custom', $this->defs['tos_hardening_csp_custom']['default']),
        ];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Tor / .onion – Network Defaults', 'tor-onion-support'); ?></h1>
            <form method="post" action="edit.php?action=tos_save_defaults">
                <?php wp_nonce_field('tos_defaults_save', 'tos_defaults_nonce'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="tos_default_onion_domain"><?php esc_html_e('Default .onion domain', 'tor-onion-support'); ?></label></th>
                        <td>
                            <input type="text" class="regular-text" id="tos_default_onion_domain" name="tos_default_onion_domain" placeholder="exampleonionaddress.onion" value="<?php echo esc_attr($vals['tos_default_onion_domain']); ?>">
                            <p class="description"><?php esc_html_e('This host will be used by sites that did not set their own .onion domain.', 'tor-onion-support'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Onion-Location header (clearnet)', 'tor-onion-support'); ?></th>
                        <td>
                            <label><input type="checkbox" name="tos_default_send_onion_location" value="1" <?php checked($vals['tos_default_send_onion_location']); ?>>
                                <?php esc_html_e('Advertise the onion mirror from clearnet by default.', 'tor-onion-support'); ?></label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Enable onion hardening (default)', 'tor-onion-support'); ?></th>
                        <td>
                            <label><input type="checkbox" name="tos_default_enable_hardening" value="1" <?php checked($vals['tos_default_enable_hardening']); ?>>
                                <?php esc_html_e('Apply stricter privacy/security for .onion requests by default.', 'tor-onion-support'); ?></label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Disable oEmbed on .onion (default)', 'tor-onion-support'); ?></th>
                        <td>
                            <label><input type="checkbox" name="tos_default_disable_oembed" value="1" <?php checked($vals['tos_default_disable_oembed']); ?>>
                                <?php esc_html_e('Block external embeds for onion visitors by default.', 'tor-onion-support'); ?></label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="tos_default_hardening_csp_mode"><?php esc_html_e('CSP mode (default)', 'tor-onion-support'); ?></label></th>
                        <td>
                            <select id="tos_default_hardening_csp_mode" name="tos_default_hardening_csp_mode">
                                <?php
                                foreach (['strict' => 'Strict', 'relaxed' => 'Relaxed', 'off' => 'Off', 'custom' => 'Custom'] as $k => $label) {
                                    echo '<option value="' . esc_attr($k) . '" ' . selected($vals['tos_default_hardening_csp_mode'], $k, false) . '>' . esc_html($label) . '</option>';
                                }
                                ?>
                            </select>
                            <p class="description"><?php esc_html_e('This is the default CSP mode for all sites; individual sites may override it.', 'tor-onion-support'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="tos_default_hardening_csp_custom"><?php esc_html_e('Custom CSP (default if mode=Custom)', 'tor-onion-support'); ?></label></th>
                        <td>
                            <textarea class="large-text code" rows="5" id="tos_default_hardening_csp_custom" name="tos_default_hardening_csp_custom" placeholder="default-src 'self';&#10;script-src 'self';&#10;style-src 'self' 'unsafe-inline';&#10;img-src 'self' data:;"><?php echo esc_textarea($vals['tos_default_hardening_csp_custom']); ?></textarea>
                            <p class="description"><?php esc_html_e('Provide a full CSP policy used as the default for sites selecting Custom mode and not overriding locally.', 'tor-onion-support'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(esc_html__('Save Defaults', 'tor-onion-support')); ?>
            </form>
        </div>
        <?php
    }

    public function saveNetworkDefaults(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to manage network options.', 'tor-onion-support'));
        }
        check_admin_referer('tos_defaults_save', 'tos_defaults_nonce');

        // Sanitize and save network defaults.
        update_site_option('tos_default_onion_domain',         $this->sanitizeHost($_POST['tos_default_onion_domain'] ?? ''));
        update_site_option('tos_default_send_onion_location',  $this->sanitizeBool($_POST['tos_default_send_onion_location'] ?? '0'));
        update_site_option('tos_default_enable_hardening',     $this->sanitizeBool($_POST['tos_default_enable_hardening'] ?? '0'));
        update_site_option('tos_default_disable_oembed',       $this->sanitizeBool($_POST['tos_default_disable_oembed'] ?? '0'));
        update_site_option('tos_default_hardening_csp_mode',   $this->sanitizeCspMode($_POST['tos_default_hardening_csp_mode'] ?? 'strict'));
        update_site_option('tos_default_hardening_csp_custom', $this->sanitizeCspString($_POST['tos_default_hardening_csp_custom'] ?? ''));

        wp_safe_redirect(network_admin_url('admin.php?page=tos_network_defaults&updated=1'));
        exit;
    }

    /* ------------------- Sanitizers ------------------- */

    public function sanitizeHost($host): string
    {
        $host = strtolower(trim((string) $host));
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
        $val = (string) $val;
        $allowed = ['strict', 'relaxed', 'off', 'custom'];
        return in_array($val, $allowed, true) ? $val : 'strict';
    }

    public function sanitizeCspString($val): string
    {
        // Keep as text; trim trailing spaces. Do not try to parse CSP (admin may paste advanced policies).
        $val = trim((string) $val);
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
		$title = esc_html__( 'CSP Help', 'tor-onion-support' );
		$content  = '<p><strong>' . esc_html__( 'What is CSP?', 'tor-onion-support' ) . '</strong> '
				  . esc_html__( 'Content-Security-Policy controls which resources your site is allowed to load. It prevents accidental leaks to clearnet/CDNs in onion mode.', 'tor-onion-support' )
				  . '</p>';
		$content .= '<p><strong>' . esc_html__( 'Modes:', 'tor-onion-support' ) . '</strong> '
				  . esc_html__( 'Strict (safest), Relaxed (allows inline JS), Off (no CSP header), Custom (send exactly what you enter).', 'tor-onion-support' )
				  . '</p>';
		$content .= '<p><strong>' . esc_html__( 'Quick examples:', 'tor-onion-support' ) . "</strong></p>"
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
		$content .= '<p><em>' . esc_html__( 'Tips: If admin bar or theme scripts break, switch to Relaxed or add only what you need. Avoid external CDNs in onion mode whenever possible.', 'tor-onion-support' ) . '</em></p>';

		$screen->add_help_tab([
			'id'      => 'tos_csp_help',
			'title'   => $title,
			'content' => wp_kses_post( $content ),
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
        $title = esc_html__( 'Network Defaults & Mapping', 'tor-onion-support' );
		$content  = '<p><strong>' . esc_html__( 'Network Defaults', 'tor-onion-support' ) . '</strong> '
				  . esc_html__( 'apply to all sites that did not set their own values. A site may override any default in its own Settings → Tor / .onion page.', 'tor-onion-support' )
				  . '</p>';
		$content .= '<p><strong>' . esc_html__( 'Mapping', 'tor-onion-support' ) . '</strong> '
				  . esc_html__( 'lets you assign a specific .onion host per site. If a site has no mapping, it may still use the global Default .onion domain.', 'tor-onion-support' )
				  . '</p>';

		$screen->add_help_tab([
			'id'      => 'tos_network_help',
			'title'   => $title,
			'content' => wp_kses_post( $content ),
		]);
    }
}
