# Changelog

## 🚀 Version 2.3.0 (November 2026)

### 🔒 Security
- **Security hardening of cached pages**: cached copies are built so that nothing tied to an individual visitor is shared with other visitors, including form security tokens.
- **Security hardening of the plugin's log files**: they now use the same protection Joomla uses for its own logs, in Joomla's log folder. Updating moves existing entries across automatically; uninstalling removes them.

### 🐛 Bug Fixes
- **A feed could be cached and served as the home page**: the plugin marked any cookie-free response as cacheable, including RSS/Atom feeds, JSON and raw output, and error pages. When a crawler requested `/?fbclid=…&format=feed`, SiteGround stored the feed under the home page (it appears to drop the query string from its cache key when a tracking parameter is present), and visitors to `/` got raw feed XML until it expired. Only a normal HTML page with HTTP status 200 is marked cacheable now ([#3](https://github.com/cybersalt/cs-siteground-cache-for-joomla/issues/3)).
- **Query strings**: a page whose URL has query parameters is no longer cached, except for marketing tracking parameters (`utm_*`, `fbclid`, `gclid`, `msclkid` and similar), which don't change the page. See *Cacheable Query Parameters* below to allow others.
- **Form detection with Joomla's Gzip Page Compression on**: pages with forms weren't recognised when Joomla compressed the page itself, so those forms could fail. The plugin now checks the decompressed page.
- **J6: the header purge dropdown showed an empty pill**: Joomla 6's admin template draws no caret on a split button, so nothing showed it opens a menu. The plugin now draws its own ([#2](https://github.com/cybersalt/cs-siteground-cache-for-joomla/issues/2)).
- **The Cache Daemon tile said "Reachable" without checking**: it only tested that the log file could be written. It now opens a connection to SiteGround's cache socket and says *Not reachable* when that fails.
- **Every scheduled task run purged the whole cache**: Joomla's scheduler saves a log entry after each task, and that save went through the same event as an article save. Sites with the core Scheduled Tasks (session clean-up, update notification) had their whole SiteGround cache purged at every run. Saves in the scheduler, action logs, privacy requests, messages, mail templates, check-in, guided tours and the installer no longer purge anything (installs still purge through their own event).
- **Purges after admin saves could be lost**: full purges triggered by installs, template saves and list actions (which end in a redirect) now go through the same end-of-request fallback as content purges.

### 📦 New Features
- **Pages with forms can be cached**: on many Joomla sites every page has a form (a footer newsletter, a login module), and since 2.2.1 those pages were never cached. Now, when every form on a page is one the plugin recognises (Convert Forms, RSForm! Pro, the Joomla login module, or one you list by a marker), the page is cached with the security token taken out. The visitor's browser fetches its own token the first time they use the form, which also starts their session, so the form submits normally. A click or submit that comes before the token has arrived is held for a moment, then replayed. Any other form keeps the page uncached, as before. On by default; turn it off with *Cache Pages with Forms* on the Cache tab. Needs JavaScript in the visitor's browser.
- **Test my home page**: a button on the status panel fetches the home page and the newest article as an anonymous visitor, twice each, and says whether SiteGround caches them and, if not, why (for example "a form that needs the visitor's session", naming the form).
- **Recent Uncached Visits**: a status tile shows how many of the last 50 pages rendered for visitors were marked cacheable, with a warning when most were bypassed for one fixable reason. It stores only the time and the reason, never URLs or visitor data.
- **Home page watchdog**: a scheduled task (*System → Scheduled Tasks → SiteGround Cache: home page watchdog*, added unpublished) fetches the home page, and any other paths you list, every 15 minutes. It works from the web scheduler and from `cli/joomla.php scheduler:run` (the task stores the site's address for that). If one comes back as something other than an HTML page, it purges the SiteGround cache, checks again and emails a report.
- **Excluded Menu Items**: pick menu items whose pages are never cached.
- **Better Excluded URLs**: `*` matches any text (`/shop/*/checkout`), a trailing `$` matches that path only (`/about$`), and a `?` matches the query string too (`/search?q=`). Plain paths still match everything that starts with them.
- **Cacheable Query Parameters**: list parameters such as `start` or `limitstart` to let paginated pages be cached. `format`, `tmpl` and `task` never are, and neither is a URL that mixes a listed parameter with a tracking one.
- **Excluded User Agents**: pages rendered for user agents containing a listed text (for example a feed reader or an uptime monitor) are never stored in the cache.

### 🔧 Improvements
- **Purge everything when it matters**: saving the plugin's settings, switching any plugin on or off in the Plugins list (this one included), and installing or updating this plugin all purge the whole SiteGround cache, so nothing cached under old settings or an older version is served on.
- **One purge per content save**: every content save already purged the whole site (the home page pattern covers every page), and that's deliberate, because modules such as Latest News show articles on every page. The plugin now sends that purge once instead of once per URL.
- **The toolbar button says which cache it clears**: it's now *Purge SiteGround Cache*, with a tooltip and setting note saying that Joomla's own Clear Cache and optimisers such as JCH Optimize don't clear SiteGround's cache.
- **File check**: the Cache tab shows whether every installed file matches the released version, and names any that are missing, empty or changed after a failed update.

### 🌐 Languages
- **All 14 translations are complete again**: every setting and message added since 2.0 had been showing in English outside en-GB. Dutch, German, Spanish, French, Italian, Brazilian Portuguese, Russian, Polish, Japanese, Simplified Chinese, Turkish, Greek, Czech and Swedish now cover all 193 strings, including the toolbar button.

### 📝 Documentation
- Tests: `tests/run-harness.sh` runs the real plugin class through Joomla's response order for 58 checks (feeds with and without tracking parameters, JSON/raw, `tmpl=component`, 404s, every form case, gzip, exclusions, cookies, visitor sessions, log protection). Not part of the package.

## 🚀 Version 2.2.2 (October 2026)

### 🐛 Bug Fixes
- **Default excluded components were ignored on new installs**: a fresh install stores the default list (`com_users`, `com_finder`, `com_contact`, `com_privacy`) as one comma-separated value, but the plugin only split the list on line breaks, so none of the four was actually excluded. The list is now read correctly, and the Excluded Components field shows the four defaults as selected instead of looking empty.
- **PHP warnings on the Logs tab**: the inline log viewer referred to two text labels ("Loading..." and "Show More") that were never defined, which printed "Undefined array key" warnings on sites that display PHP warnings.

### 📝 Documentation
- **Getting Started tip updated**: pages with forms have been detected and left uncached automatically since 2.2.1, so the tip no longer tells you to add contact and login pages to Excluded URLs by hand.

## 🚀 Version 2.2.1 (October 2026)

### 🐛 Bug Fixes
- **Joomla's no-cache headers blocked SiteGround's cache**: even with the cookie removed, every page still went out with `Cache-Control: no-store, no-cache` and an `Expires` date in the past (Joomla's default for pages not marked cacheable, plus PHP's session cache limiter). SiteGround's Dynamic Cache won't store a response carrying `Cache-Control: no-cache`, so pages were never cached. Cacheable anonymous pages are now marked cacheable in Joomla and the session limiter's no-cache headers are removed. Joomla then sends a 15-minute `Expires` instead. Logged-in, excluded and cookie-carrying pages keep their no-cache headers.
- **Pages that still carried a cookie were marked cacheable**: 2.2.0 sent `X-Cache-Enabled: True` before it tried to strip `Set-Cookie`, and did the stripping in `onAfterRender`. If the suppression setting was off, or another extension set a cookie after render, the page went out claiming to be cacheable with a cookie attached, and SiteGround skipped it (`SKIP_CACHE_SET_COOKIE`). Cookie stripping now runs in `onBeforeRespond` at the lowest priority, after every other extension has finished, and `X-Cache-Enabled: True` is only sent when no `Set-Cookie` is left on the response. Otherwise it sends `False`.
- **Silent failure**: when the cookie can't be removed (headers already sent, or the cookie is in Joomla's own header list) the plugin now logs a `set_cookie_not_stripped` warning with the reason. An `onAfterRespond` self-check logs `cache_claimed_with_cookie` if a cookie still went out with a cacheable page. Verbose logging records why each page was or wasn't cached.
- **Pages with forms were cached**: any page carrying a Joomla form token (Convert Forms, contact forms, login modules) is now left uncached and keeps its session cookie, so the token still matches the visitor's session when they submit. Before, these pages relied on being excluded by hand.

## 🚀 Version 2.2.0 (June 2026)

### 🔧 Improvements
- **Set-Cookie suppression on cacheable anonymous responses**: Joomla starts a session for every guest, which adds a `Set-Cookie` to the response. SiteGround's edge skips caching any response with `Set-Cookie` (`X-Proxy-Cache-Info: SKIP_CACHE_SET_COOKIE`), so the dynamic cache was effectively a no-op for first-visit anonymous traffic — including search engine crawlers. The plugin now strips `Set-Cookie` headers on cacheable anonymous GET responses (in `onAfterRender`, after the `X-Cache-Enabled: True` decision), so SiteGround's edge can actually cache. URL and component exclusions still receive untouched sessions for forms / CSRF / login. New "Suppress Session Cookie on Cacheable Pages" toggle in the Advanced tab — defaults ON.
- **Dark-mode contrast fix in inline log viewer**: The stats row labels, timestamp and elapsed-ms text, request-ID badges, warning/debug badges, and success/danger/info alert messages all used `opacity` or hardcoded dark colors that became unreadable when Joomla's admin (Atum) was in dark mode. Added explicit dark-mode color overrides triggered by either `prefers-color-scheme: dark` or Joomla's Bootstrap-5.3 `[data-bs-theme="dark"]` toggle.

## 🚀 Version 2.1.0 (April 2026)

### 🔍 Security
- **Hostname validation**: SiteToolsClient now validates hostname format before sending to SiteGround daemon (alphanumeric, hyphens, dots only)
- **Path validation in SiteToolsClient**: Defense-in-depth validation ensures only safe characters reach the UNIX socket
- **Tightened purge path regex**: Removed `%` character from allowed purge path characters to prevent URL encoding attacks
- **JavaScript context escaping**: Viewer template AJAX URL now uses `json_encode()` instead of raw PHP echo in JavaScript string context

### 🔧 Improvements
- **Joomla 6 compatibility**: Update server target platform now includes Joomla 6 (`(5|6)\.[0-9]+`)

## 🚀 Version 2.0.0 (April 2026)

### 📦 New Features
- **Admin toolbar purge button**: "Purge SG Cache" button in the Joomla admin header bar, matching native Atum styling. Simple mode (purge all) or dropdown mode with custom path shortcuts
- **Inline log viewer dashboard**: Full logging dashboard embedded directly in plugin settings with stats row, filters, expandable JSON entries, and action buttons
- **Standalone log viewer**: Dark-themed full-page viewer accessible via "Open Full Viewer" button
- **Debug panel**: Collapsible footer panel on admin pages showing real-time cache operations for the current request
- **Verbose logging toggle**: Switch between production mode (bypasses/purges/errors only) and verbose mode (every frontend page visit)
- **Component exclusion selector**: Searchable multi-select dropdown listing all installed components, using Joomla's native `list-fancy-select` layout
- **Non-SiteGround safeguards**: Warning banner on admin pages when not hosted on SiteGround, all cache operations gracefully disabled
- **Joomla update server**: Automatic update notifications via Joomla's built-in updater with SHA256 checksum verification
- **Multilingual support**: 15 languages — English, Dutch, German, Spanish, French, Italian, Portuguese (BR), Russian, Polish, Japanese, Chinese (Simplified), Turkish, Greek, Czech, Swedish
- **Changelog tab**: View full version history directly in plugin settings

### 🔧 Improvements
- **Logging system**: JSON lines format with request ID correlation, auto-rotation, thread-safe writes
- **Socket-level logging**: Full visibility into SiteGround daemon communication (connect, send, response)
- **Smart purge queue**: Shutdown function fallback ensures cache is always purged even on admin save+redirect
- **Language loading**: `loadLanguage()` call ensures toolbar and notice strings work on all admin pages
- **Logger suppression**: Log viewer AJAX requests don't pollute the log, but purge actions are logged
- **Float precision fix**: Elapsed milliseconds logged as clean integers, not floating point noise

### 🔍 Security
- **AJAX permission check**: All AJAX endpoints verify `core.manage` authorization in addition to admin client check
- **Purge path validation**: Whitelist regex rejects invalid characters in manual purge paths
- **Self-uninstall safety**: Extension event handlers wrapped in try/catch to prevent errors during plugin removal

### 🐛 Bug Fixes
- **Fixed uninstall error**: Plugin is now disabled in `preflight()` before uninstall to prevent "Class not found" errors when events fire on a half-removed plugin
- **Fixed custom field types not loading**: Added `addfieldprefix` to manifest `<fields>` tag
- **Fixed language strings showing as raw keys**: Moved globally-needed strings to `.sys.ini` and used `loadLanguage()`
- **Fixed purge queue not flushing**: Added `register_shutdown_function()` fallback for admin POST+redirect
- **Fixed toolbar button overlapping**: Injected into Atum's `.header-items` container with native `header-item-content` structure
- **Fixed double icon on viewer button**: Changed from `<a target="_blank">` to button with `window.open()`
- **Fixed description language key in manifest**: Moved attribution HTML into language string value

## 🚀 Version 1.0.0 (March 2026)

### 📦 New Features
- **Dynamic cache purging**: Automatically purges SiteGround cache when articles, categories, or menu items are saved, deleted, or state-changed
- **Smart purge queue**: Collects URLs during request and purges efficiently; performs full flush when URL count exceeds configurable threshold
- **HTTP cache headers**: Sets `X-Cache-Enabled` and optional `Vary: User-Agent` headers for SiteGround's reverse proxy
- **URL and component exclusions**: Configure paths and components that should bypass caching
- **Manual purge button**: One-click full cache purge from plugin settings
- **Logged-in user handling**: Option to disable caching for authenticated users
- **Post-install detection**: Warns if SiteGround hosting is not detected during installation
