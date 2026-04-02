# Changelog

## 🚀 Version 2.0.0 (April 2026)

### 📦 New Features
- **Admin toolbar purge button**: "Purge SG Cache" button in the Joomla admin header bar, matching native Atum styling. Simple mode (purge all) or dropdown mode with custom path shortcuts
- **Inline log viewer dashboard**: Full logging dashboard embedded directly in plugin settings with stats row, filters, expandable JSON entries, and action buttons
- **Standalone log viewer**: Dark-themed full-page viewer accessible via "Open Full Viewer" button
- **Debug panel**: Collapsible footer panel on admin pages showing real-time cache operations for the current request
- **Verbose logging toggle**: Switch between production mode (bypasses/purges/errors only) and verbose mode (every frontend page visit)
- **Component exclusion selector**: Searchable multi-select dropdown listing all installed components, using Joomla's native `list-fancy-select` layout
- **Non-SiteGround safeguards**: Warning banner on admin pages when not hosted on SiteGround, all cache operations gracefully disabled
- **Joomla update server**: Automatic update notifications via Joomla's built-in updater

### 🔧 Improvements
- **Logging system**: JSON lines format with request ID correlation, auto-rotation, thread-safe writes
- **Socket-level logging**: Full visibility into SiteGround daemon communication (connect, send, response)
- **Smart purge queue**: Shutdown function fallback ensures cache is always purged even on admin save+redirect
- **Language loading**: Explicit `loadLanguage()` call ensures toolbar and notice strings work on all admin pages
- **Logger suppression**: Own AJAX requests (viewer/stats) don't pollute the log, but purge actions are logged
- **Float precision fix**: Elapsed milliseconds logged as clean integers, not floating point noise

### 🐛 Bug Fixes
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
