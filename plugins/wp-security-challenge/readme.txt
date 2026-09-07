=== WordPress Security Challenge ===
Contributors: modemedia
Tags: security, captcha, browser challenge, bot protection, rate limit
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.2.1

Risk-based browser verification for guest visitors with a conditional internal CAPTCHA.

== Features ==
* Guest-only frontend challenge by default.
* Logged-in users bypass the browser challenge.
* wp-admin, AJAX, Cron, REST, XML-RPC, WooCommerce wc-ajax, system POST requests and common internal endpoints are bypassed.
* Signed HttpOnly verification cookie.
* Browser and automation signals with configurable risk threshold.
* Lightweight per-IP rate signal.
* Internal 5-character CAPTCHA with server-side transient hash verification.
* Fully responsive RTL challenge screen.
* Local CSS, JavaScript, SVG assets and local motion runtime; no CDN or third-party request on the challenge screen.
* Editable frontend texts and colors from WordPress admin.
* Trusted IP allowlist.

== Important ==
This plugin is a browser challenge layer, not a replacement for a server/WAF firewall. Keep regular WordPress, hosting and backup security practices in place.

== Installation ==
1. Upload and activate the plugin.
2. Open Security Site / امنیت سایت in WordPress admin.
3. Review the default threshold and texts.
4. Test in an incognito/private browser while logged out.

== 1.2.0 ==
* Live conversion of Persian/Arabic CAPTCHA digits to English while typing and pasting.
* Added permanent challenge mode choice: with CAPTCHA or browser-check only.
* Added 1-10 sensitivity control where a lower number is more sensitive and reacts earlier.
* Hardened no-cache headers/constants for challenge and security AJAX responses without disabling cache on destination pages.

== 1.2.1 ==
* Fixed horizontal scrolling on mobile challenge screens.
* Hardened responsive widths, safe-area padding and clipping for decorative/animated elements.
* Added 360px, 390px, 480px and 768px responsive safeguards without affecting destination pages.
