<?php

namespace Onionify\Security;

if (!defined('ABSPATH')) {
    exit;
}

use Onionify\Domain\Detector;
use Onionify\Domain\Mapping;

/**
 * Hardening applies onion-specific privacy/security measures.
 * It only engages when the current request is on .onion and the admin enabled options.
 */
final class Hardening
{
    private Detector $detector;
    private Mapping $mapping;

    public function __construct(Detector $detector, Mapping $mapping)
    {
        $this->detector = $detector;
        $this->mapping  = $mapping;
    }

    /**
     * Register conditional hardening hooks.
     * Call on plugins_loaded from Bootstrap (after Settings exist).
     */
    public function register(): void
    {
        add_action('init', function () {
            if (!$this->detector->isOnionRequest()) {
                return;
            }
            if (!get_option('onionify_enable_hardening', false)) {
                return;
            }

            // Optionally disable oEmbed & external embeds.
            if (get_option('onionify_disable_oembed', true)) {
                $this->disableEmbeds();
            }

            // Optional: reduce resource hints that can leak to clearnet CDNs.
            $this->tightenResourceHints();

            // Optional: disable emojis (external calls to s.w.org).
            $this->disableEmojis();
			
			if (get_option('onionify_disable_external_avatars', false)) {
				add_filter('get_avatar_url', function ($url, $id_or_email, $args) {
					// Replace any external avatar with local 1x1 transparent pixel.
					// Avoids calls to gravatar.com or other third-party hosts.
					return 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
				}, 10, 3);
			}
        }, 1);
    }

    /**
     * Disable oEmbed endpoints, discovery links, and auto-embeds (YouTube/Twitter/etc.).
     */
    private function disableEmbeds(): void
    {
        // Turn off oEmbed discovery and REST route.
        remove_action('rest_api_init', 'wp_oembed_register_route');
        remove_filter('oembed_dataparse', 'wp_filter_oembed_result', 10);

        // Turn off discovery links and scripts.
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'wp_oembed_add_host_js');

        // Disable auto-embed.
        remove_filter('the_content', [$GLOBALS['wp_embed'], 'autoembed'], 8);
        remove_action('embed_head', 'enqueue_embed_scripts');
        add_filter('embed_oembed_discover', '__return_false');

        // Block oEmbed result fetch.
        add_filter('oembed_fetch_url', '__return_false');
    }

    /**
     * Remove dns-prefetch and preconnect hints that may target clearnet CDNs.
     */
    private function tightenResourceHints(): void
    {
        add_filter('wp_resource_hints', function ($hints, $relation_type) {
            // Drop all preconnect/dns-prefetch in onion mode for stricter privacy.
            if (in_array($relation_type, ['dns-prefetch', 'preconnect'], true)) {
                return [];
            }
            return $hints;
        }, 10, 2);
    }

    /**
     * Disable emoji scripts/styles that call external hosts.
     */
    private function disableEmojis(): void
    {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        add_filter('tiny_mce_plugins', function ($plugins) {
            if (is_array($plugins)) {
                return array_diff($plugins, ['wpemoji']);
            }
            return [];
        });
    }
}
