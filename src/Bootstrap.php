<?php

namespace Onionify;

if (!defined('ABSPATH')) {
    exit;
}

use Onionify\Admin\Settings;
use Onionify\Domain\Detector;
use Onionify\Domain\Mapping;
use Onionify\Http\Headers;
use Onionify\Http\Loopback;
use Onionify\Url\Rewrite;
use Onionify\Security\Hardening;

/**
 * Bootstrap coordinates plugin initialization.
 */
final class Bootstrap
{
    private Settings $settings;
    private Detector $detector;
    private Mapping $mapping;
    private Rewrite $rewrite;
    private Headers $headers;
    private Hardening $hardening;
	private Loopback $loopback;

    public function __construct()
    {
        $this->settings = new Settings();
        $this->detector = new Detector();
        $this->mapping  = new Mapping($this->detector);
        $this->rewrite  = new Rewrite($this->detector, $this->mapping);
        $this->headers  = new Headers($this->detector, $this->mapping);
        $this->hardening = new Hardening($this->detector, $this->mapping);
		$this->loopback = new Loopback($this->detector, $this->mapping);
    }

    public function init(): void
    {
        $this->settings->register();

        // URL and option rewrites on onion requests.
        add_filter('pre_option_home', [$this->rewrite, 'filterHomeOption']);
        add_filter('pre_option_siteurl', [$this->rewrite, 'filterSiteUrlOption']);
        add_filter('home_url', [$this->rewrite, 'filterUrlToOnion'], 20, 4);
        add_filter('site_url', [$this->rewrite, 'filterUrlToOnion'], 20, 4);
        add_filter('content_url', [$this->rewrite, 'filterSimpleUrlToOnion'], 20, 2);
        add_filter('plugins_url', [$this->rewrite, 'filterSimpleUrlToOnion'], 20, 2);
        add_filter('stylesheet_directory_uri', [$this->rewrite, 'filterSimpleUrlToOnion'], 20, 1);
        add_filter('template_directory_uri', [$this->rewrite, 'filterSimpleUrlToOnion'], 20, 1);
        add_filter('redirect_canonical', [$this->rewrite, 'avoidCanonicalBounce'], 20, 2);
        add_filter('wp_is_using_https', [$this->rewrite, 'forceHttpInsideTor'], 20, 1);

        // Headers (Onion-Location + security).
        add_action('template_redirect', [$this->headers, 'sendHeaders'], 0);

        // Hardening hooks.
        $this->hardening->register();
		
		// Loopback rerouter (safe internal HTTP handling)
		$this->loopback->register();

        // WP-CLI commands (only if WP_CLI present).
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('tor-onion', \Onionify\Cli\Commands::class);
        }
    }
}
