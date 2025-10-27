# 🔒 Security Policy

## Supported Versions

The following versions of **Onionify** are actively supported with security updates and fixes:

| Version | Supported          |
|----------|--------------------|
| 1.0.x    | ✅ Active support   |
| < 1.0.0  | ❌ No longer supported |

If you are running an older version, please update to the latest release from  
[WordPress.org → Onionify](https://wordpress.org/plugins/onionify/)  
or from this repository’s [Releases](https://github.com/InfinitumForm/onionify/releases).

---

## Reporting a Vulnerability

We take security very seriously.

If you discover a **vulnerability, security flaw, or potential exploit**, please report it **privately** using one of the following channels:

- 📧 Email: **infinitumform+Onionify@gmail.com** (subject: `Onionify Security Report`)
- 🔐 Optional PGP Key: *(available upon request)*  
- 🧅 Alternatively, contact securely via the `.onion` service listed in plugin documentation if you prefer Tor-based communication.

**Do not open a public GitHub issue** for security vulnerabilities.

When reporting, please include:
- A clear description of the issue and potential impact  
- Steps to reproduce the problem  
- Any relevant environment details (WordPress version, PHP version, hosting setup)  
- Proof of concept (if applicable)

We aim to acknowledge all valid reports within **48 hours** and provide an estimated fix or mitigation timeline within **5 business days**.

---

## Responsible Disclosure

All security researchers and contributors are expected to follow **responsible disclosure practices**:
- Give reasonable time for patching before public disclosure  
- Avoid exploiting vulnerabilities beyond proof-of-concept testing  
- Respect user privacy and data integrity at all times  

Reports that follow responsible disclosure will be credited (if desired) in the plugin’s changelog or WordPress.org release notes.

---

## Security Design Philosophy

Onionify is designed with a **non-invasive, privacy-first** approach:
- No database or core file modifications  
- Follows all WordPress.org coding and security standards  
- Uses filters and actions only  
- External connections are **opt-in only** (e.g., Tor exit verification)  
- No user-tracking, telemetry, or analytics are included

All changes are reviewed for:
- Sanitization and escaping
- CSRF and nonce protection
- Capability checks
- Safe use of WordPress APIs
- Avoidance of unsafe PHP functions

---

## Additional Recommendations

If you deploy WordPress via Tor:
- Always run behind an isolated, hardened web server (e.g., Nginx or Apache on a non-public IP)  
- Use HTTPS even when serving `.onion` (for modern Tor Browser compatibility)  
- Keep Tor and WordPress core updated  
- Disable unnecessary plugins and external requests  
- Review your CSP, COEP, and CORS headers regularly  

---

**Maintainer:**  
🧑‍💻 Ivijan-Stefan Stipić  
[INFINITUM FORM®](https://infinitumform.com)  
📧 infinitumform+Onionify@gmail.com  
🔗 https://github.com/InfinitumForm/onionify
