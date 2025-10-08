=== Tor Onion Support ===
Contributors: ivijanstefan
Tags: tor, onion, privacy, multisite, security, csp, wp-cli
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tor Onion Support provides clean, conservative, and WordPress.org-compliant support for serving WordPress sites through Tor hidden services (.onion).

== Description ==

Tor Onion Support provides clean, conservative, and WordPress.org-compliant support for serving WordPress sites through Tor hidden services (.onion). 
It rewrites runtime URLs when requests arrive via .onion, avoids canonical redirects that would leak visitors from onion -> clearnet, optionally advertises an Onion-Location header, and supplies privacy-focused "hardening" (CSP, COEP, resource-hint tightening, oEmbed disable) specifically for onion visitors.

The plugin is designed to be safe for distribution on WordPress.org:
- No core hacks.
- Uses filters/actions only.
- Multisite-aware (per-site mapping + network defaults).
- Optional WP-CLI tools for admins.
- Localization-ready (textdomain: tor-onion-support).

=== ⚠ IMPORTANT WARNING ===

⚠ BEWARE: If you are trying to make your WordPress site available **only** on the darknet to preserve the anonymity of your server and hosting provider, **then this plugin is NOT for you!**

This plugin does **not** hide your server’s hosting provider, network infrastructure, or administrative metadata. It helps WordPress behave correctly when *visitors* connect via .onion addresses (URL rewriting, headers, CSP and optional checks), but it does **not** anonymize, obfuscate, or otherwise protect your server infrastructure from being discovered. If your goal is to hide or anonymize server hosting information, consult Tor Project documentation and threat-modeling specialists first.

== Features ==

* Detects .onion requests and rewrites generated WordPress URLs to the configured onion host at runtime.
* Does not modify database `home`/`siteurl` values — rewrites are runtime-only.
* Multisite support: per-site .onion mapping (Network Admin) plus Network Defaults.
* Sends Onion-Location header from clearnet (optional) so browsers / Tor Browser can suggest the onion mirror.
* Optional privacy hardening when serving .onion (CSP, COEP, X-Frame-Options, disable oEmbed, remove resource hints).
* Optional verification against Tor Project's official exit-addresses list (opt-in).
* WP-CLI commands to list/map onion hosts and toggle settings.
* Filter hooks for extensibility (including `tor_onion_is_tor_request` and `tor_onion_verify_exit_list`).
* Sanity-checked, defensive code compatible with PHP 7.4 — 8.x.

== Installation ==

1. Upload the `tor-onion-support` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. (Single-site) Go to Settings → Tor / .onion and enter your `.onion` host (host only, e.g. `abcd1234xyz.onion`) and optional hardening settings.
4. (Multisite) Network Admin → Tor / .onion → use the **Mapping** page to map each site to its onion host, and optionally set **Network Defaults** on the Defaults submenu.
5. (Optional) To verify IPs against the official Tor exit list enable verification:
   * Add `define('TOS_VERIFY_TOR_EXIT', true);` to `wp-config.php`, or
   * Add `add_filter('tor_onion_verify_exit_list', '__return_true');` in a mu-plugin or theme functions file.
   Note: verification is **opt-in** and cached for 24 hours. If your environment disables external HTTP calls, keep verification disabled.

== Quick usage (WP-CLI) ==

* `wp tor-onion list` — show mapping table (multisite) or single-site status.
* `wp tor-onion map <blog_id|0> <example.onion>` — map blog_id (or 0 for single-site) to an onion host.
* `wp tor-onion set --hardening=on|off --oembed=on|off --csp=strict|relaxed|off` — quick toggles.

== Settings explained (concise + clear) ==

* **.onion domain** — Host only, no protocol. Example: `abcd1234xyz.onion`. Leave empty to use Network Default (multisite).
* **Send Onion-Location from clearnet** — When enabled, the plugin adds an `Onion-Location: http://<your-onion><path>` header to requests on the clearnet site. This is useful to advertise your onion mirror to Tor Browser or other clients.
* **Enable onion hardening** — When enabled, headers and filters designed to reduce external resource loading (and privacy leakage) are applied to requests *only* when served via .onion.
* **Disable oEmbed/embeds on .onion** — Blocks automatic fetching of oEmbed content (YouTube, Twitter, etc.) and discovery links to avoid loading third-party resources for onion visitors.
* **CSP mode** — `Strict`, `Relaxed`, `Off`, `Custom`.
  * **Strict** — safest. No inline scripts. Best for privacy; may break themes/plugins that rely on inline JS.
  * **Relaxed** — allows inline scripts/styles (`'unsafe-inline'`), useful for older themes.
  * **Off** — plugin does not send a CSP header.
  * **Custom** — plugin will send **exactly** the policy you place into the Custom CSP text box. Only use if you understand CSP syntax.

== Custom CSP — clear guidance ==

* The **Custom CSP** field is used **only** when `CSP mode` is set to **Custom**.
* Enter the policy exactly as you want it sent. Examples below — copy/paste if needed:

1) Minimal secure WordPress (no external CDN):
```
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
```

2) Relaxed (allows inline JS):
```
default-src 'self';
script-src 'self' 'unsafe-inline';
style-src 'self' 'unsafe-inline';
img-src 'self' data:;
font-src 'self' data:;
connect-src 'self';
frame-src 'self';
```

3) If you must use an external CDN — add only the exact host(s):
```
img-src 'self' https://cdn.example.com data:;
font-src 'self' https://cdn.example.com data:;
```

*Tips & cautions:*
- Start with **Strict**; if things break (admin bar, theme JS), switch to **Relaxed**.
- Use **Custom** only if you know CSP; incorrect CSP can break admin, media, or login.
- Avoid including public CDNs in onion mode where possible — best privacy practice is to host assets locally.

== Multisite behavior ==

* Per-site mapping: Network Admin → Tor / .onion allows mapping each blog_id → onion host.
* Network Defaults: Network Admin → Tor / .onion → Network Defaults lets you set default values (default onion host, default CSP mode, default hardening toggles) that sites inherit unless they override locally.
* Precedence: Per-site explicit setting → Network Default → Plugin internal default.

== Filters & constants (developer) ==

* `apply_filters('tor_onion_is_tor_request', bool $is_tor, array $server)`  
  Allows other plugins/themes to override detection. `$server` is a copy of `$_SERVER`.
* `apply_filters('tor_onion_verify_exit_list', bool $default)`  
  Controls whether the plugin will verify IPs against the Tor exit list. Disabled by default.
* `define('TOS_VERIFY_TOR_EXIT', true);` — alternative to enable exit-list verification in `wp-config.php`.
* `tor_onion_support_*` option names used by the plugin: see Settings page. The plugin cleans up these options on uninstall.

== Uninstall / cleanup ==

When the plugin is deleted via WordPress admin, it removes:
* Per-site options: `tos_onion_domain`, `tos_send_onion_location`, `tos_enable_hardening`, `tos_disable_oembed`, `tos_hardening_csp_mode`, `tos_hardening_csp_custom`
* Network options (multisite): `tos_onion_map`, `tos_default_*` variants

If you do not want automatic cleanup, do not use the admin "Delete" action; deactivate only.

== Privacy, security, and limitations (be explicit) ==

* This plugin **only** changes WordPress behavior (URL outputs, certain headers, CSP, resource hint control) depending on how the visitor connects to the site (clearnet vs .onion).  
* It does **not** anonymize or hide server metadata: IP address of the hosting provider, DNS records for clearnet domains, or other infra-level information remain unchanged. If you need to hide server infrastructure, you must design your hosting and network setup for that purpose — this plugin is **not** a substitute for that.
* Enabling the Tor exit-list verification triggers external HTTP requests to `check.torproject.org` (only if you opt-in). If your environment blocks external HTTP requests, enable verification via `WP-CLI` or `wp-config.php` only after ensuring allowed hosts are set appropriately.
* The plugin tries to be conservative and privacy-preserving by default: critical external calls are opt-in and hardening defaults aim to reduce third-party resource loads for onion visitors.

== Frequently Asked Questions ==

= Q: Will this make my site “only available on Tor”? =
A: No. The plugin does not change hosting, DNS, or server-level routing. It changes WordPress behavior when the incoming request appears to be via .onion or Tor. If you need a site that is exclusively reachable via Tor with no clearnet footprint, this plugin alone is insufficient.

= Q: I use a CDN like Cloudflare — will this work? =
A: The plugin inspects common headers (e.g., `CF-Connecting-IP`, `X-Forwarded-For`) to try to detect Tor-origin requests behind CDNs. It also allows other plugins to alter detection via the `tor_onion_is_tor_request` filter. If your CDN rewrites or strips headers, adjust your network configuration to forward real client IP headers to WordPress.

= Q: What happens if I enable Custom CSP but make a mistake? =
A: If the Custom CSP is invalid or too strict, parts of your site (including admin pages) may break. The plugin will send **exactly** the text you enter. Use Custom CSP only if you understand CSP rules. Start by testing on staging.

= Q: Does plugin change database `home` or `siteurl`? =
A: No. It returns rewritten URLs at runtime only. Database options remain unchanged.

= Q: How do I disable the Tor exit list check? =
A: It is disabled by default. Do nothing. To enable, add `define('TOS_VERIFY_TOR_EXIT', true);` to `wp-config.php` or use the filter `add_filter('tor_onion_verify_exit_list', '__return_true');`.

== Screenshots ==
1. Settings page (single-site): enter .onion host and hardening toggles.
2. Network mapping page (multisite): map blog IDs to onion hosts.
3. Network defaults page: set defaults used by all sites.

== Changelog ==

= 1.0.0 =
* Initial release: runtime URL rewrites, multisite mapping, Onion-Location support, optional hardening and WP-CLI utilities.

== Upgrade Notice ==

= 1.0.0 =
Initial public release. No upgrade actions required.

== Support ==

Use the WordPress.org support forum for the plugin. For commercial help or customizations contact https://infinitumform.com/.
