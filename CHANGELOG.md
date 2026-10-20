# Changelog

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
