# Cybersalt SiteGround Cache

A Joomla 5/6 system plugin that makes SiteGround's server-side cache work properly on Joomla sites. It purges SiteGround's dynamic cache when your content changes, and it removes the things Joomla sends that stop SiteGround from caching pages in the first place.

**Documentation:** <https://docs.cybersalt.com/extensions/siteground-cache> · **Download:** <https://www.cybersalt.com/extensions/siteground-cache>

## Why Joomla sites need this

SiteGround hosting includes a reverse-proxy cache that serves stored copies of your pages without running Joomla. Out of the box it hardly ever caches a Joomla page, for two reasons:

- **Joomla starts a session for every visitor**, so every response carries a `Set-Cookie` header. SiteGround skips any response with a cookie (`X-Proxy-Cache-Info: SKIP_CACHE_SET_COOKIE`).
- **Joomla marks pages `Cache-Control: no-store, no-cache`** with an `Expires` date in the past. SiteGround won't store a page that says not to cache it.

The SiteGround dashboard then reports that fewer than 60% of visits are served from cache. On a production SiteGround site, version 2.2.1 took the ordinary pages from none cached to all of them cached.

## Features

- **Pages that can be cached actually get cached.** On anonymous GET requests the plugin removes the session cookie and Joomla's no-cache headers, then tells SiteGround the page is cacheable (`X-Cache-Enabled: True`). It only says so when no cookie is left on the response.
- **Nothing of one visitor's is ever cached.** A page is only stored when it was rendered for a visitor without a session, so carts, refilled forms and messages never reach the shared copy; pages showing the visitor's IP address aren't cached either.
- **Only real pages are cached.** Feeds, JSON and raw output, error pages and URLs with query parameters (other than marketing tracking ones such as `utm_*` and `fbclid`) are never marked cacheable, so a feed can't end up stored as your home page.
- **Pages with forms are cached too, and the forms keep working.** A Joomla form carries a security token tied to the visitor's session. When every form on a page is one the plugin recognises (Convert Forms, RSForm! Pro, the Joomla login module, or one you list by a marker), the page is cached with the token taken out, and each visitor's browser fetches its own token the first time they use the form. Pages with any other form are left uncached and keep their session.
- **Automatic purging.** Saving, deleting or publishing content purges the whole SiteGround cache once, so modules that list articles on every page stay fresh. So do template saves, extension installs and updates, saving this plugin's settings and switching plugins on or off.
- **Purge button in the admin header** on every page, with an optional dropdown of your own paths. It clears SiteGround's cache; Joomla's own Clear Cache doesn't.
- **Exclusions** by URL (with `*` wildcards, `$` for an exact path and `?` for the query string), component, menu item and user agent. `com_users`, `com_finder`, `com_contact` and `com_privacy` are excluded by default.
- **Status dashboard** that shows whether the site is on SiteGround, whether the cache daemon can be reached, and how many recent pages were cacheable, with a warning when most are bypassed for one fixable reason.
- **Test my home page.** One click fetches the home page and the newest article as a visitor and says whether SiteGround caches them, and why not if it doesn't.
- **Home page watchdog.** An optional scheduled task checks the home page every 15 minutes, and purges the cache and emails you if it ever isn't an HTML page.
- **File check.** The settings page shows whether every installed file matches the released version, so a failed update is visible.
- **Log viewer and debug panel.** Problems are logged as warnings (for example `set_cookie_not_stripped`), and verbose mode records why each page was or wasn't cached.
- **Admin interface in English**, with translations into 14 more languages (Dutch, German, Spanish, French, Italian, Brazilian Portuguese, Russian, Polish, Japanese, Simplified Chinese, Turkish, Greek, Czech, Swedish). Every string is translated (machine translations; corrections welcome).

### What triggers a purge

| Event | What gets purged |
|-------|-----------------|
| Article, category, module or menu item saved, deleted or (un)published | Full cache flush, once per save |
| Template style or template file saved | Full cache flush |
| Extension installed/updated/removed | Full cache flush |
| A plugin switched on or off in the Plugins list | Full cache flush |
| This plugin's settings saved, or this plugin installed or updated | Full cache flush |
| Home page watchdog finds a non-HTML home page | Full cache flush |

A content save purges everything on purpose: modules such as Latest News show articles on every page, so purging only the article's own URL would leave them stale.

## Requirements

- Joomla 5.0 or later (native on Joomla 5 and 6)
- PHP 8.1 or later
- **SiteGround hosting.** The plugin talks to SiteGround's caching daemon over a UNIX socket that only exists on SiteGround servers. It installs anywhere, but elsewhere it shows a notice and does nothing.

## Installation

1. Download the latest zip from [cybersalt.com](https://www.cybersalt.com/extensions/siteground-cache) or the [GitHub releases](https://github.com/cybersalt/cs-siteground-cache-for-joomla/releases) page.
2. In the Joomla admin go to **System → Install → Extensions** and upload the zip.
3. Go to **System → Manage → Plugins**, search for "SiteGround Cache" and enable **System - Cybersalt SiteGround Cache**.

Updates arrive through Joomla's normal updater (**System → Update → Extensions**).

## Configuration

| Setting | Tab | Default | What it does |
|---------|-----|---------|-------------|
| Enable Dynamic Cache | Cache | Yes | Marks cacheable pages for SiteGround's reverse proxy |
| Auto-Purge on Content Change | Cache | Yes | Purges related pages when content changes |
| Purge Threshold | Cache | 10 | Above this many URLs, do one full flush instead |
| Excluded URLs | Cache | (empty) | URL paths that should never be cached, one per line. `*` matches any text, a trailing `$` means that path only, `?` matches the query string too |
| Excluded Components | Cache | `com_users`, `com_finder`, `com_contact`, `com_privacy` | Components whose pages are never cached |
| Excluded Menu Items | Cache | (none) | Menu items whose pages are never cached |
| Cacheable Query Parameters | Cache | (empty) | Query parameters that may be cached, such as `start` for pagination. `format`, `tmpl` and `task` never are |
| Excluded User Agents | Cache | (empty) | Pages rendered for user agents containing this text are never stored |
| Cache Pages with Forms | Cache | Yes | Cache pages whose forms are all of the ticked types (Convert Forms, RSForm! Pro, login module) or match a marker, with the token fetched per visitor. Needs JavaScript |
| Show Toolbar Button | Toolbar | Yes | Purge button in the admin header |
| Toolbar Button Mode | Toolbar | Simple - Purge All | Single button, or a dropdown with your own paths |
| Enable Logging / Verbose Logging | Logs | No / No | Writes cache activity to `sgcache.log.php` in Joomla's log folder (protected from browser download, like Joomla's own logs) |
| Vary by User Agent | Advanced | No | Separate cached copies for mobile and desktop |
| Cache for Logged-in Users | Advanced | No | Only for sites that show every user the same content |
| Suppress Session Cookie on Cacheable Pages | Advanced | Yes | Removes Joomla's session cookie from cacheable guest pages. Leave this on |

The home page watchdog lives in **System → Scheduled Tasks** (*SiteGround Cache: home page watchdog*). The installer adds it unpublished; publish it to switch it on. Its options are an email address for reports and extra paths to check.

## Tests

`bash tests/run-harness.sh` runs the real plugin class through Joomla's response order (render, gzip, `onBeforeRespond`, respond) under PHP's built-in server for 58 checks: feeds with and without tracking parameters, JSON and raw output, `tmpl=component`, 404s, every form case, gzip, exclusions, cookies, visitor sessions, the probe header and log-file protection. It needs PHP 8.1+ and curl, and isn't part of the package.

## Attribution and license

This plugin is licensed under the **GNU General Public License version 2 or later**. See [LICENSE](LICENSE) for the licence text and [NOTICE](NOTICE) for the copyright and attribution notice.

The SiteGround server communication protocol (the UNIX socket API for cache purging) was learned by studying SiteGround's [Speed Optimizer](https://wordpress.org/plugins/sg-cachepress/) plugin for WordPress (formerly SG CachePress), which SiteGround publishes under the **GNU General Public License v3**. The WordPress plugin source was reviewed through the [WordpressPluginDirectory/sg-cachepress](https://github.com/WordpressPluginDirectory/sg-cachepress) mirror on GitHub.

No WordPress code was copied into this plugin. The Joomla implementation was written from scratch with the Joomla extension API, informed by how SiteGround's caching daemon accepts purge requests. The GPL guarantees the freedom to study how a program works.

**SiteGround** and **Speed Optimizer** are trademarks of SiteGround. This plugin is not affiliated with, endorsed by, or sponsored by SiteGround.

## Support

- **Help and bug reports:** <https://www.cybersalt.com/services/support-request-form> or [GitHub issues](https://github.com/cybersalt/cs-siteground-cache-for-joomla/issues)
- **Changelog:** [CHANGELOG.md](CHANGELOG.md)

## Author

- **Author:** Cybersalt Consulting Ltd.
- **Website:** <https://www.cybersalt.com>
- **Email:** support@cybersalt.com

Copyright (C) 2026 Cybersalt Consulting Ltd.
