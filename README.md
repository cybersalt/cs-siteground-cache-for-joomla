# SiteGround Cache Plugin for Joomla

A Joomla 5/6 system plugin that integrates with SiteGround's server-side caching system. Automatically purges SiteGround's dynamic cache when content changes and sets appropriate HTTP cache headers.

## How It Works

SiteGround hosting includes a reverse proxy cache that stores copies of your pages for fast delivery. This plugin communicates with SiteGround's caching daemon to automatically purge cached pages when you make content changes in Joomla, so visitors always see up-to-date content.

### Features

- **Automatic cache purging** — When you save, delete, or change the state of articles, categories, or menu items, the relevant cached pages are automatically purged
- **Smart purge queue** — Collects URLs during a request and purges them efficiently at the end. If too many URLs need purging, performs a single full cache flush instead
- **HTTP cache headers** — Sets `X-Cache-Enabled` headers to tell SiteGround's reverse proxy which pages to cache and which to skip
- **URL and component exclusions** — Configure specific URLs or components that should never be cached
- **Manual purge button** — One-click full cache purge from the plugin settings
- **Logged-in user handling** — Optionally disable caching for logged-in users (default: disabled)
- **User-Agent variation** — Optionally serve different cached versions for mobile and desktop

### What Triggers a Cache Purge

| Event | What Gets Purged |
|-------|-----------------|
| Article saved | Article URL, category page, home page |
| Article deleted | Article URL, category page, home page |
| Article published/unpublished | Article URL, home page |
| Category changed | Home page |
| Extension installed/updated/removed | Full cache flush |

## Requirements

- Joomla 5.0 or later
- PHP 8.1 or later
- **SiteGround hosting** — This plugin communicates with SiteGround's caching daemon via a UNIX socket that only exists on SiteGround servers. It will install on any server but cache operations will only work on SiteGround.

## Installation

1. Download the latest release ZIP from the [Releases](https://github.com/cybersalt/cs-siteground-cache-plugin-for-joomla/releases) page
2. In Joomla admin, go to **System > Install > Extensions**
3. Upload and install the ZIP file
4. Go to **System > Plugins** and search for "SiteGround Cache"
5. Enable the plugin and configure settings as needed

## Configuration

| Setting | Default | Description |
|---------|---------|-------------|
| Enable Dynamic Cache | Yes | Sets X-Cache-Enabled headers for SiteGround's reverse proxy |
| Auto-Purge on Content Change | Yes | Automatically purge cache when content is modified |
| Purge Threshold | 10 | If more than this many URLs need purging, do a full flush instead |
| Excluded URLs | (empty) | URL paths that should never be cached (one per line) |
| Excluded Components | (empty) | Component names to exclude from caching (one per line) |
| Vary by User Agent | No | Serve different cached versions for mobile vs desktop |
| Cache for Logged-in Users | No | Whether to cache pages for logged-in users |

## Attribution and License

This plugin is licensed under the **GNU General Public License version 2 or later**.

The SiteGround server communication protocol (UNIX socket API for cache purging) was learned by studying SiteGround's [Speed Optimizer](https://wordpress.org/plugins/sg-cachepress/) plugin for WordPress (formerly SG CachePress), which is published under the **GNU General Public License v3** by SiteGround. The WordPress plugin source was reviewed via the [WordpressPluginDirectory/sg-cachepress](https://github.com/WordpressPluginDirectory/sg-cachepress) mirror on GitHub.

No WordPress code was copied into this plugin. The Joomla implementation was written from scratch using the Joomla extension API, informed by an understanding of how SiteGround's caching daemon accepts purge requests. This is permitted under the terms of the GPL, which guarantees the freedom to study how a program works.

**SiteGround** and **Speed Optimizer** are trademarks of SiteGround. This plugin is not affiliated with, endorsed by, or sponsored by SiteGround.

## Author

- **Author:** Cybersalt
- **Website:** https://cybersalt.com
- **Email:** support@cybersalt.com

Copyright (C) 2026 Cybersalt Consulting Ltd. All rights reserved.
