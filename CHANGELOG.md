# Changelog

## 🚀 Version 1.0.0 (March 2026)

### 📦 New Features
- **Dynamic cache purging**: Automatically purges SiteGround cache when articles, categories, or menu items are saved, deleted, or state-changed
- **Smart purge queue**: Collects URLs during request and purges efficiently; performs full flush when URL count exceeds configurable threshold
- **HTTP cache headers**: Sets `X-Cache-Enabled` and optional `Vary: User-Agent` headers for SiteGround's reverse proxy
- **URL and component exclusions**: Configure paths and components that should bypass caching
- **Manual purge button**: One-click full cache purge from plugin settings
- **Logged-in user handling**: Option to disable caching for authenticated users
- **Post-install detection**: Warns if SiteGround hosting is not detected during installation
