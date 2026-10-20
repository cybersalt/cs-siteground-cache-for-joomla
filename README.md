# Cybersalt SiteGround Cache

A Joomla 5/6 system plugin that makes SiteGround's server-side cache work properly on Joomla sites. It purges SiteGround's dynamic cache when your content changes, and it removes the things Joomla sends that stop SiteGround from caching pages in the first place.

**Documentation:** <https://docs.cybersalt.com/extensions/siteground-cache> · **Download:** <https://www.cybersalt.com/extensions/siteground-cache>

## Why Joomla sites need this

SiteGround hosting includes a reverse-proxy cache that serves stored copies of your pages without running Joomla. Out of the box it hardly ever caches a Joomla page, for two reasons:

- **Joomla starts a session for every visitor**, so every response carries a `Set-Cookie` header. SiteGround skips any response with a cookie (`X-Proxy-Cache-Info: SKIP_CACHE_SET_COOKIE`).
- **Joomla marks pages `Cache-Control: no-store, no-cache`** with an `Expires` date in the past. SiteGround won't store a page that says not to cache it.

The SiteGround dashboard then reports that fewer than 60% of visits are served from cache. On a production SiteGround site, version 2.2.1 took the ordinary pages from none cached to all of them cached, while the pages with forms stayed uncached as they should.

## Features

- **Pages that can be cached actually get cached.** On anonymous GET requests the plugin removes the session cookie and Joomla's no-cache headers, then tells SiteGround the page is cacheable (`X-Cache-Enabled: True`). It only says so when no cookie is left on the response.
- **Forms keep working.** Any page carrying a Joomla form token (contact forms, Convert Forms, login modules) is left uncached and keeps its session, so the form still submits.
- **Automatic purging.** Saving, deleting or publishing an article or category purges the affected pages. Saving a template, or installing, updating or removing an extension, flushes the whole cache.
- **Smart purge queue.** URLs are collected during a request and purged once at the end. Past a threshold the plugin does a single full flush instead.
- **Purge button in the admin header** on every page, with an optional dropdown of your own paths.
- **URL and component exclusions.** `com_users`, `com_finder`, `com_contact` and `com_privacy` are excluded by default.
- **Status dashboard** that shows whether the site is on SiteGround and whether the cache daemon can be reached.
- **Log viewer and debug panel.** Problems are logged as warnings (for example `set_cookie_not_stripped`), and verbose mode records why each page was or wasn't cached.
- **Admin interface in English**, with translations into 14 more languages (Dutch, German, Spanish, French, Italian, Brazilian Portuguese, Russian, Polish, Japanese, Simplified Chinese, Turkish, Greek, Czech, Swedish). Settings added since 2.0 show in English until their translations are done.

### What triggers a purge

| Event | What gets purged |
|-------|-----------------|
| Article saved | Article URL, category page, home page |
| Article deleted | Article URL, category page, home page |
| Article published/unpublished | Article URL, home page |
| Category changed | Home page |
| Template style or template file saved | Full cache flush |
| Extension installed/updated/removed | Full cache flush |

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
| Excluded URLs | Cache | (empty) | URL paths that should never be cached, one per line |
| Excluded Components | Cache | `com_users`, `com_finder`, `com_contact`, `com_privacy` | Components whose pages are never cached |
| Show Toolbar Button | Toolbar | Yes | Purge button in the admin header |
| Toolbar Button Mode | Toolbar | Simple - Purge All | Single button, or a dropdown with your own paths |
| Enable Logging / Verbose Logging | Logs | No / No | Writes cache activity to `logs/sgcache.log` |
| Vary by User Agent | Advanced | No | Separate cached copies for mobile and desktop |
| Cache for Logged-in Users | Advanced | No | Only for sites that show every user the same content |
| Suppress Session Cookie on Cacheable Pages | Advanced | Yes | Removes Joomla's session cookie from cacheable guest pages. Leave this on |

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
