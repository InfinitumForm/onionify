# 🧅 Onionify – Official WordPress Plugin for Tor .onion Integration

This is the **official GitHub repository** for the [Onionify WordPress plugin](https://wordpress.org/plugins/onionify/).

Onionify provides **clean, privacy-focused, and WordPress.org-compliant support** for serving WordPress sites via Tor hidden services (`.onion`).  
It safely rewrites runtime URLs for `.onion` requests, prevents onion→clearnet leaks, and includes optional privacy-enhancing headers such as `Onion-Location`, `CSP`, `COEP`, and `X-Frame-Options`.  

Additional features include oEmbed blocking, avatar suppression, and security-focused hardening designed specifically for WordPress environments running on or mirrored through Tor.

---

## ⚠ Important Warning

> **BEWARE:**  
> If you want your WordPress site to exist **only on the darknet** to preserve the anonymity of your server or hosting provider - **this plugin is NOT for you.**  
>  
> This plugin **does not anonymize** your hosting, DNS, or IP infrastructure.  
> It simply ensures WordPress behaves correctly when *visitors* use `.onion` addresses.

For true server anonymity, follow the [Tor Project’s official documentation](https://community.torproject.org/onion-services/) and security best practices for hidden services.

---

## ✨ Features

- Detects `.onion` requests and rewrites WordPress URLs at runtime  
  (no database changes)
- Multisite-ready: per-site onion mapping + network defaults
- Optional `Onion-Location` header for clearnet visitors
- Privacy hardening (CSP, COEP, X-Frame-Options, no oEmbed, no avatars)
- Optional verification against the [Tor Project exit list](https://check.torproject.org/exit-addresses)
- Prevents canonical/login redirect loops in `.onion` mode
- Reroutes internal WP-Cron and REST loopbacks safely to clearnet
- WP-CLI commands for quick mapping and configuration
- Fully PHP 7.4–8.3 compatible and PSR-4 autoloaded
- No core modifications - all WordPress.org-safe hooks

---

## ⚙️ Installation

1. Upload `onionify` to `/wp-content/plugins/`.
2. Activate from **Plugins → Installed Plugins**.
3. Open **Settings → Tor / .onion** and enter your `.onion` domain (e.g. `abcd1234xyz.onion`).
4. Enable optional features:
   - Onion-Location header
   - Onion hardening (CSP/COEP)
   - Disable oEmbed/avatars
   - Reroute loopback/cron requests

### Multisite Mode
- Use **Network Admin → Tor / .onion** to map each site to its onion host.
- Network Defaults allow global fallbacks.

---

## 🧠 Settings Overview

| Option | Description |
|--------|--------------|
| **.onion domain** | Your `.onion` hostname (no protocol). |
| **Send Onion-Location** | Adds the `Onion-Location` header to clearnet pages. |
| **Enable hardening** | Activates security headers and privacy controls. |
| **Disable oEmbed** | Stops embedding YouTube/Twitter/etc. for onion visitors. |
| **Disable external avatars** | Prevents loading avatars from gravatar.com and similar. |
| **CSP mode** | `Strict`, `Relaxed`, `Off`, or `Custom`. |
| **Reroute internal HTTP** | Fixes WP-Cron/REST/loopback when running under `.onion`. |

---

## 🧩 WP-CLI Commands

```bash
# List current mapping
wp tor-onion list

# Map a site (Multisite)
wp tor-onion map <blog_id|0> example.onion

# Update hardening mode quickly
wp tor-onion set --hardening=on --oembed=off --csp=strict
```

---

## 🔐 Content Security Policy (CSP) Examples

**Strict mode (recommended):**
```text
default-src 'self';
script-src 'self';
style-src 'self' 'unsafe-inline';
img-src 'self' data:;
font-src 'self' data:;
connect-src 'self';
frame-src 'self';
frame-ancestors 'self';
```

**Relaxed mode (allows inline scripts):**
```text
default-src 'self';
script-src 'self' 'unsafe-inline';
style-src 'self' 'unsafe-inline';
img-src 'self' data:;
```

**Custom mode:**  
Plugin will send exactly what you define in “Custom CSP (if mode = custom)”.

---

## 🧱 Developer Filters & Constants

```php
add_filter('tor_onion_is_tor_request', function ($is_tor, $server) {
    // Extend detection logic
    return $is_tor;
}, 10, 2);

add_filter('tor_onion_verify_exit_list', '__return_true'); // enable Tor exit verification
```

- `TOS_VERIFY_TOR_EXIT` – Define in `wp-config.php` to always verify IPs.
- `tor_onion_support_*` – Option prefix used for plugin settings.

---

## 🧹 Uninstall

When deleted via the admin, plugin removes:
- All per-site options (`tos_onion_domain`, `tos_enable_hardening`, etc.)
- All network options (`tos_onion_map`, `tos_default_*`)

Deactivate if you want to keep configuration for later use.

---

## 🕵️ Privacy and Security Notice

- This plugin **does not hide** your IP or hosting provider.
- It **does not** make your site Tor-only - it simply handles `.onion` visitors correctly.
- External HTTP requests (for Tor exit-list verification) are **opt-in** and cached for 24 h.
- Some hosts block Tor connections entirely; this plugin cannot override such network-level restrictions.

---

## 📄 License

GPLv2 or later  
© 2025 [INFINITUM FORM](https://infinitumform.com)  

---

### 🌐 Related References

- [Tor proxies in WordPress (make.wordpress.org)](https://make.wordpress.org/support/2014/02/tor-proxies-in-wordpress/)
- [NoScript & Tor Browser issues](https://wordpress.org/support/topic/noscript-theme-tor-browser-safest-settings-no-javascript/)
- [Tor Browser posting issues on WordPress.com](https://wordpress.com/forums/topic/impossible-to-post-using-tor/)

---

## 🧑‍💻 Author

**Ivijan-Stefan Stipić**  
Founder & Lead Developer  
[INFINITUM FORM®](https://infinitumform.com)  
📧 infinitumform@gmail.com  
🌍 https://infinitumform.com

Specialized in secure WordPress architecture, plugin engineering, and performance optimization with 20+ years of full-stack development experience.
