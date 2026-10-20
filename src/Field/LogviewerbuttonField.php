<?php

/**
 * @package     Cybersalt.Plugin.System.SgCache
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Cybersalt\Plugin\System\SgCache\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

class LogviewerbuttonField extends FormField
{
    protected $type = 'Logviewerbutton';

    protected function getInput(): string
    {
        $token = Session::getFormToken();
        $baseUrl = Uri::base() . 'index.php?option=com_ajax&plugin=sgcache&group=system&format=raw&' . $token . '=1';

        $viewerUrl   = $baseUrl . '&action=viewer';
        $downloadUrl = $baseUrl . '&action=download';
        $clearUrl    = $baseUrl . '&action=clear';
        $testUrl     = $baseUrl . '&action=test';
        $statsUrl    = $baseUrl . '&action=stats';
        $viewUrl     = $baseUrl . '&action=view';

        // HTML-context strings: escaped via esc().
        $t = [
            'view'          => $this->esc(Text::_('PLG_SYSTEM_SGCACHE_LOG_VIEW')),
            'viewFull'      => $this->esc(Text::_('PLG_SYSTEM_SGCACHE_LOG_VIEW_FULL')),
            'download'      => $this->esc(Text::_('PLG_SYSTEM_SGCACHE_LOG_DOWNLOAD')),
            'clear'         => $this->esc(Text::_('PLG_SYSTEM_SGCACHE_LOG_CLEAR')),
            'test'          => $this->esc(Text::_('PLG_SYSTEM_SGCACHE_LOG_TEST')),
            'refresh'       => $this->esc(Text::_('PLG_SYSTEM_SGCACHE_LOG_REFRESH')),
        ];

        // JS-context strings + URLs: encoded with json_encode() to defend against any
        // character that could break out of a JS literal — even though all values are
        // author-controlled (Uri::base() + .ini lang strings), this matches the v2.1.0
        // hardening pattern in tmpl/viewer.php.
        $jsFlags   = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
        $viewerUrlJs   = json_encode($viewerUrl,   $jsFlags);
        $viewUrlJs     = json_encode($viewUrl,     $jsFlags);
        $statsUrlJs    = json_encode($statsUrl,    $jsFlags);
        $testUrlJs     = json_encode($testUrl,     $jsFlags);
        $clearUrlJs    = json_encode($clearUrl,    $jsFlags);
        $noEntriesJs   = json_encode(Text::_('PLG_SYSTEM_SGCACHE_LOG_NO_ENTRIES'),     $jsFlags);
        $loadingJs     = json_encode(Text::_('PLG_SYSTEM_SGCACHE_LOG_LOADING'),        $jsFlags);
        $clearConfirmJs= json_encode(Text::_('PLG_SYSTEM_SGCACHE_LOG_CLEAR_CONFIRM'),  $jsFlags);
        $showMoreJs    = json_encode(Text::_('PLG_SYSTEM_SGCACHE_LOG_SHOW_MORE'),      $jsFlags);
        $showLessJs    = json_encode(Text::_('PLG_SYSTEM_SGCACHE_LOG_SHOW_LESS'),      $jsFlags);

        return <<<HTML

        <style>
            /* Scoped theme variables — set local defaults for light mode, then override on
               any dark-mode trigger Atum / Joomla / the OS might use. This is more reliable
               than --bs-tertiary-bg etc. which Joomla 5.4 Atum doesn't actually switch. */
            .sglog-dashboard {
                --sg-text: #212529;
                --sg-text-muted: #6c757d;
                --sg-bg: #ffffff;
                --sg-bg-subtle: #f8f9fa;
                --sg-bg-elevated: #ffffff;
                --sg-border: #dee2e6;

                border: 1px solid var(--sg-border);
                border-radius: 8px;
                overflow: hidden;
                font-size: 13px;
                max-width: 100%;
                background: var(--sg-bg);
                color: var(--sg-text);
            }
            [data-bs-theme="dark"] .sglog-dashboard,
            [data-color-scheme="dark"] .sglog-dashboard,
            .theme-dark .sglog-dashboard {
                --sg-text: rgba(255, 255, 255, 0.92);
                --sg-text-muted: rgba(255, 255, 255, 0.6);
                --sg-bg: rgba(255, 255, 255, 0.02);
                --sg-bg-subtle: rgba(255, 255, 255, 0.04);
                --sg-bg-elevated: rgba(255, 255, 255, 0.06);
                --sg-border: rgba(255, 255, 255, 0.12);
            }
            /* Stats cards row */
            .sglog-stats {
                display: flex;
                flex-wrap: wrap;
                gap: 0;
                background: var(--sg-bg-subtle);
                border-bottom: 1px solid var(--sg-border);
            }
            .sglog-stat {
                flex: 1 1 120px;
                padding: 12px 16px;
                text-align: center;
                border-right: 1px solid var(--sg-border);
            }
            .sglog-stat:last-child { border-right: none; }
            .sglog-stat-value {
                font-size: 22px;
                font-weight: 700;
                line-height: 1.2;
                color: var(--sg-text);
            }
            .sglog-stat-label {
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: var(--sg-text-muted);
                margin-top: 2px;
            }
            .sglog-stat-value.text-success { color: var(--bs-success, #198754) !important; }
            .sglog-stat-value.text-warning { color: var(--bs-warning, #ffc107) !important; }
            .sglog-stat-value.text-danger  { color: var(--bs-danger,  #dc3545) !important; }
            .sglog-stat-value.text-info    { color: var(--bs-info,    #0dcaf0) !important; }
            /* SG status indicator */
            .sglog-sg-status {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-size: 13px;
                font-weight: 600;
            }
            .sglog-sg-dot {
                width: 10px;
                height: 10px;
                border-radius: 50%;
                display: inline-block;
            }
            .sglog-sg-dot.active   { background: var(--bs-success, #198754); }
            .sglog-sg-dot.inactive { background: var(--bs-warning, #ffc107); }
            /* Button bar */
            .sglog-toolbar {
                display: flex;
                gap: 8px;
                padding: 10px 16px;
                background: var(--sg-bg-subtle);
                border-bottom: 1px solid var(--sg-border);
                flex-wrap: wrap;
                align-items: center;
            }
            .sglog-toolbar .btn { font-size: 12px; padding: 4px 12px; }
            .sglog-toolbar-spacer { flex: 1; }
            /* Filter row */
            .sglog-filters {
                display: flex;
                gap: 8px;
                padding: 8px 16px;
                background: var(--sg-bg-subtle);
                border-bottom: 1px solid var(--sg-border);
                flex-wrap: wrap;
                align-items: center;
                font-size: 12px;
            }
            .sglog-filters label {
                margin: 0;
                font-weight: 600;
                color: var(--sg-text);
            }
            .sglog-filters select, .sglog-filters input {
                padding: 3px 8px;
                border: 1px solid var(--sg-border);
                border-radius: 4px;
                font-size: 12px;
                background: var(--sg-bg-elevated);
                color: var(--sg-text);
            }
            /* Log entries */
            .sglog-entries { max-height: 500px; overflow-y: auto; }
            .sglog-entry {
                padding: 8px 16px;
                border-bottom: 1px solid var(--sg-border);
                cursor: pointer;
                transition: background 0.1s;
            }
            .sglog-entry:hover { background: var(--sg-bg-subtle); }
            .sglog-entry-header {
                display: flex;
                align-items: center;
                gap: 8px;
                flex-wrap: wrap;
            }
            .sglog-time {
                font-size: 11px;
                color: var(--sg-text-muted);
                font-family: monospace;
                white-space: nowrap;
            }
            .sglog-rid {
                font-size: 10px;
                font-family: monospace;
                padding: 1px 5px;
                border-radius: 3px;
                background: var(--sg-bg-elevated);
                color: var(--sg-text);
            }
            .sglog-badge {
                font-size: 10px;
                font-weight: 700;
                padding: 1px 6px;
                border-radius: 3px;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }
            /* Badge text colors — DARK variants by default (work on light backgrounds).
               Dark mode overrides below switch to BRIGHT variants so they read against
               Atum's dark navy chrome. */
            .sglog-badge-info    { background: rgba(13, 202, 240, 0.15); color: #055160; }
            .sglog-badge-error   { background: rgba(220, 53, 69, 0.15);  color: #58151c; }
            .sglog-badge-warning { background: rgba(255, 193, 7, 0.15);  color: #664d03; }
            .sglog-badge-debug   { background: rgba(108, 117, 125, 0.15); color: #495057; }
            .sglog-badge-event   { background: rgba(13, 110, 253, 0.12);  color: #052c65; }
            [data-bs-theme="dark"] .sglog-badge-info,
            [data-color-scheme="dark"] .sglog-badge-info,
            .theme-dark .sglog-badge-info    { color: #5dd5e8; }
            [data-bs-theme="dark"] .sglog-badge-error,
            [data-color-scheme="dark"] .sglog-badge-error,
            .theme-dark .sglog-badge-error   { color: #ff8a99; }
            [data-bs-theme="dark"] .sglog-badge-warning,
            [data-color-scheme="dark"] .sglog-badge-warning,
            .theme-dark .sglog-badge-warning { color: #ffd54f; }
            [data-bs-theme="dark"] .sglog-badge-debug,
            [data-color-scheme="dark"] .sglog-badge-debug,
            .theme-dark .sglog-badge-debug   { color: #adb5bd; }
            [data-bs-theme="dark"] .sglog-badge-event,
            [data-color-scheme="dark"] .sglog-badge-event,
            .theme-dark .sglog-badge-event   { color: #6c9ef0; }
            .sglog-elapsed {
                font-size: 11px;
                color: var(--sg-text-muted);
            }
            .sglog-entry-details {
                display: none;
                margin-top: 8px;
                padding: 8px 12px;
                background: var(--sg-bg-subtle);
                border-radius: 4px;
                font-family: monospace;
                font-size: 12px;
                white-space: pre-wrap;
                word-break: break-all;
                max-height: 200px;
                overflow-y: auto;
                color: var(--sg-text);
            }
            .sglog-entry-details.open { display: block; }
            /* JSON highlighting — use BS text-emphasis vars so they auto-switch in dark mode */
            .sglog-json-key    { color: var(--bs-primary-text-emphasis, #0550ae); font-weight: 600; }
            .sglog-json-string { color: var(--bs-success-text-emphasis, #0a3069); }
            .sglog-json-number { color: var(--bs-primary-text-emphasis, #0550ae); }
            .sglog-json-bool   { color: var(--bs-danger-text-emphasis,  #cf222e); }
            /* Status messages */
            .sglog-status {
                padding: 30px 16px;
                text-align: center;
                color: var(--sg-text-muted);
            }
            /* Pagination / show more */
            .sglog-footer {
                padding: 8px 16px;
                text-align: center;
                border-top: 1px solid var(--sg-border);
                background: var(--sg-bg-subtle);
                font-size: 12px;
            }
            .sglog-footer .btn { font-size: 12px; padding: 3px 14px; }
            /* Alert for test/clear results — also theme-adaptive */
            .sglog-alert {
                margin: 8px 16px;
                padding: 8px 12px;
                border-radius: 4px;
                font-size: 12px;
                display: none;
            }
            .sglog-alert.alert-success { background: var(--bs-success-bg-subtle, rgba(40,167,69,0.12)); color: var(--bs-success-text-emphasis, #0a3622); border: 1px solid var(--bs-success-border-subtle, rgba(40,167,69,0.2)); }
            .sglog-alert.alert-danger  { background: var(--bs-danger-bg-subtle,  rgba(220,53,69,0.12)); color: var(--bs-danger-text-emphasis,  #58151c); border: 1px solid var(--bs-danger-border-subtle,  rgba(220,53,69,0.2)); }
            .sglog-alert.alert-info    { background: var(--bs-info-bg-subtle,    rgba(23,162,184,0.12)); color: var(--bs-info-text-emphasis,   #055160); border: 1px solid var(--bs-info-border-subtle,    rgba(23,162,184,0.2)); }
        </style>

        <div class="sglog-dashboard" id="sglog-dashboard">

            <!-- Stats row -->
            <div class="sglog-stats" id="sglog-stats">
                <div class="sglog-stat">
                    <div class="sglog-stat-value" id="sglog-sg-status">...</div>
                    <div class="sglog-stat-label">SiteGround</div>
                </div>
                <div class="sglog-stat">
                    <div class="sglog-stat-value text-info" id="sglog-entries-count">-</div>
                    <div class="sglog-stat-label">Log Entries</div>
                </div>
                <div class="sglog-stat">
                    <div class="sglog-stat-value text-info" id="sglog-requests-count">-</div>
                    <div class="sglog-stat-label">Requests</div>
                </div>
                <div class="sglog-stat">
                    <div class="sglog-stat-value text-success" id="sglog-purge-count">-</div>
                    <div class="sglog-stat-label">Purges</div>
                </div>
                <div class="sglog-stat">
                    <div class="sglog-stat-value text-warning" id="sglog-warnings-count">-</div>
                    <div class="sglog-stat-label">Warnings</div>
                </div>
                <div class="sglog-stat">
                    <div class="sglog-stat-value text-danger" id="sglog-errors-count">-</div>
                    <div class="sglog-stat-label">Errors</div>
                </div>
                <div class="sglog-stat">
                    <div class="sglog-stat-value" id="sglog-file-size">-</div>
                    <div class="sglog-stat-label">Log Size</div>
                </div>
            </div>

            <!-- Button bar -->
            <div class="sglog-toolbar">
                <button type="button" class="btn btn-sm btn-primary" onclick="sglogRefresh()">
                    <span class="icon-loop" aria-hidden="true"></span> {$t['refresh']}
                </button>
                <button type="button" class="btn btn-sm btn-secondary" id="sglog-open-full-viewer-btn">
                    <span class="icon-out-2" aria-hidden="true"></span> {$t['viewFull']}
                </button>
                <a href="{$downloadUrl}" class="btn btn-sm btn-success">
                    <span class="icon-download" aria-hidden="true"></span> {$t['download']}
                </a>
                <button type="button" class="btn btn-sm btn-info" onclick="sglogTest()">
                    <span class="icon-wrench" aria-hidden="true"></span> {$t['test']}
                </button>
                <div class="sglog-toolbar-spacer"></div>
                <button type="button" class="btn btn-sm btn-danger" onclick="sglogClear()">
                    <span class="icon-trash" aria-hidden="true"></span> {$t['clear']}
                </button>
            </div>

            <!-- Alert area for action results -->
            <div class="sglog-alert" id="sglog-alert"></div>

            <!-- Filters -->
            <div class="sglog-filters">
                <label>Level:</label>
                <select id="sglog-filter-level" onchange="sglogRefresh()">
                    <option value="">All</option>
                    <option value="info">Info</option>
                    <option value="warning">Warning</option>
                    <option value="error">Error</option>
                    <option value="debug">Debug</option>
                </select>
                <label>Event:</label>
                <select id="sglog-filter-event" onchange="sglogRefresh()">
                    <option value="">All</option>
                </select>
                <label>Request:</label>
                <input type="text" id="sglog-filter-rid" placeholder="ID" size="10">
            </div>

            <!-- Log entries -->
            <div class="sglog-entries" id="sglog-entries">
                <div class="sglog-status">{$t['loading']}</div>
            </div>

            <!-- Footer with show more / count -->
            <div class="sglog-footer" id="sglog-footer" style="display:none;">
                <span id="sglog-showing"></span>
                <button type="button" class="btn btn-sm btn-secondary" id="sglog-more-btn" onclick="sglogLoadMore()" style="margin-left:10px;">{$t['showMore']}</button>
            </div>
        </div>

        <script>
        (function() {
            var BASE       = {$viewUrlJs};
            var STATS_URL  = {$statsUrlJs};
            var TEST_URL   = {$testUrlJs};
            var CLEAR_URL  = {$clearUrlJs};
            var VIEWER_URL = {$viewerUrlJs};
            var entries = [];
            var totalEntries = 0;
            var currentLimit = 25;
            var T = {
                noEntries:    {$noEntriesJs},
                loading:      {$loadingJs},
                clearConfirm: {$clearConfirmJs},
                showMore:     {$showMoreJs},
                showLess:     {$showLessJs}
            };

            // Open Full Viewer button — bound via JS instead of inline onclick so URL never
            // flows through HTML attribute decoding into the JS context.
            var openBtn = document.getElementById('sglog-open-full-viewer-btn');
            if (openBtn) {
                openBtn.addEventListener('click', function () { window.open(VIEWER_URL, '_blank', 'noopener,noreferrer'); });
            }

            window.sglogRefresh = function() {
                currentLimit = 25;
                loadStats();
                loadEntries();
            };

            window.sglogLoadMore = function() {
                currentLimit += 25;
                loadEntries();
            };

            window.sglogTest = function() {
                showAlert('info', 'Testing...');
                fetch(TEST_URL).then(function(r) { return r.json(); }).then(function(data) {
                    if (data.success) {
                        showAlert('success', 'Logging test PASSED. Write: ' + data.bytes_written + ' bytes. SiteGround: ' + (data.siteground ? 'Detected' : 'Not detected'));
                        sglogRefresh();
                    } else {
                        showAlert('danger', 'Test FAILED: ' + (data.errors || []).join(', '));
                    }
                }).catch(function(e) { showAlert('danger', 'Error: ' + e.message); });
            };

            window.sglogClear = function() {
                if (!confirm(T.clearConfirm)) return;
                fetch(CLEAR_URL).then(function(r) { return r.json(); }).then(function(data) {
                    if (data.success) {
                        showAlert('success', 'Log cleared (' + data.method + ').');
                        sglogRefresh();
                    } else {
                        showAlert('danger', 'Clear failed.');
                    }
                }).catch(function(e) { showAlert('danger', 'Error: ' + e.message); });
            };

            window.sglogToggle = function(idx) {
                var el = document.getElementById('sglog-detail-' + idx);
                if (el) el.classList.toggle('open');
            };

            function loadStats() {
                fetch(STATS_URL).then(function(r) { return r.json(); }).then(function(data) {
                    if (!data.success) return;
                    var s = data.stats;
                    document.getElementById('sglog-entries-count').textContent = s.entry_count;
                    document.getElementById('sglog-requests-count').textContent = s.request_count;
                    document.getElementById('sglog-purge-count').textContent = s.purge_count;
                    document.getElementById('sglog-warnings-count').textContent = s.warnings;
                    document.getElementById('sglog-errors-count').textContent = s.errors;
                    document.getElementById('sglog-file-size').textContent = s.file_size_human;

                    // Populate event filter dropdown
                    var evtSelect = document.getElementById('sglog-filter-event');
                    var currentVal = evtSelect.value;
                    var opts = '<option value="">All</option>';
                    if (s.events) {
                        Object.keys(s.events).sort().forEach(function(e) {
                            var sel = (e === currentVal) ? ' selected' : '';
                            opts += '<option value="' + esc(e) + '"' + sel + '>' + esc(e) + ' (' + s.events[e] + ')</option>';
                        });
                    }
                    evtSelect.innerHTML = opts;
                });

                // SiteGround detection (from test endpoint)
                fetch(TEST_URL).then(function(r) { return r.json(); }).then(function(data) {
                    var el = document.getElementById('sglog-sg-status');
                    if (data.siteground) {
                        el.innerHTML = '<span class="sglog-sg-status"><span class="sglog-sg-dot active"></span> Active</span>';
                    } else {
                        el.innerHTML = '<span class="sglog-sg-status"><span class="sglog-sg-dot inactive"></span> Not detected</span>';
                    }
                });
            }

            function loadEntries() {
                var url = BASE + '&lines=' + currentLimit + '&offset=0';
                var level = document.getElementById('sglog-filter-level').value;
                var event = document.getElementById('sglog-filter-event').value;
                var rid = document.getElementById('sglog-filter-rid').value.trim();
                if (level) url += '&level=' + encodeURIComponent(level);
                if (event) url += '&event=' + encodeURIComponent(event);
                if (rid) url += '&request_id=' + encodeURIComponent(rid);

                var container = document.getElementById('sglog-entries');
                container.innerHTML = '<div class="sglog-status">' + T.loading + '</div>';

                fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                    if (!data.success) {
                        container.innerHTML = '<div class="sglog-status">Error loading log.</div>';
                        return;
                    }
                    entries = data.entries || [];
                    totalEntries = data.total || 0;
                    renderEntries();
                }).catch(function(e) {
                    container.innerHTML = '<div class="sglog-status">Error: ' + esc(e.message) + '</div>';
                });
            }

            function renderEntries() {
                var container = document.getElementById('sglog-entries');
                var footer = document.getElementById('sglog-footer');

                if (!entries.length) {
                    container.innerHTML = '<div class="sglog-status">' + T.noEntries + '</div>';
                    footer.style.display = 'none';
                    return;
                }

                var html = '';
                entries.forEach(function(entry, idx) {
                    var level = entry.level || 'info';
                    var badgeCls = 'sglog-badge-' + level;
                    var event = esc(entry.event || '');
                    var time = esc(entry.timestamp || '');
                    var rid = esc(entry.request_id || '');
                    var elapsed = entry.elapsed_ms || 0;
                    var data = entry.data || {};
                    var dataJson = JSON.stringify(data, null, 2);

                    html += '<div class="sglog-entry" onclick="sglogToggle(' + idx + ')">'
                        + '<div class="sglog-entry-header">'
                        + '<span class="sglog-time">' + time + '</span>'
                        + '<span class="sglog-rid">' + rid + '</span>'
                        + '<span class="sglog-badge ' + badgeCls + '">' + level + '</span>'
                        + '<span class="sglog-badge sglog-badge-event">' + event + '</span>'
                        + '<span class="sglog-elapsed">' + elapsed + 'ms</span>'
                        + '</div>'
                        + '<div class="sglog-entry-details" id="sglog-detail-' + idx + '">'
                        + syntaxHighlight(dataJson)
                        + '</div></div>';
                });
                container.innerHTML = html;

                // Footer
                document.getElementById('sglog-showing').textContent = 'Showing ' + entries.length + ' of ' + totalEntries + ' entries';
                document.getElementById('sglog-more-btn').style.display = (entries.length < totalEntries) ? 'inline-block' : 'none';
                footer.style.display = 'block';
            }

            function showAlert(type, msg) {
                var el = document.getElementById('sglog-alert');
                el.className = 'sglog-alert alert-' + type;
                el.textContent = msg;
                el.style.display = 'block';
                setTimeout(function() { el.style.display = 'none'; }, 6000);
            }

            function syntaxHighlight(json) {
                json = esc(json);
                return json.replace(/("(\\\\u[\\da-fA-F]{4}|\\\\[^u]|[^\\\\"])*"(\\s*:)?|\\b(true|false|null)\\b|-?\\d+(?:\\.\\d*)?(?:[eE][+\\-]?\\d+)?)/g, function(match) {
                    var cls = 'sglog-json-number';
                    if (/^"/.test(match)) {
                        cls = /:$/.test(match) ? 'sglog-json-key' : 'sglog-json-string';
                    } else if (/true|false/.test(match)) {
                        cls = 'sglog-json-bool';
                    }
                    return '<span class="' + cls + '">' + match + '</span>';
                });
            }

            function esc(str) {
                var d = document.createElement('div');
                d.textContent = str;
                return d.innerHTML;
            }

            // Detect dark theme by sampling the page's actual background luminance.
            // Belt-and-suspenders fallback when neither prefers-color-scheme nor
            // [data-bs-theme="dark"] matches (e.g. Atum using a non-standard signal).
            // Init on load — no custom dark detection needed; CSS handles theming via
            // Bootstrap 5.3 --bs-* variables + Atum's [data-bs-theme="dark"] toggle.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', sglogRefresh);
            } else {
                sglogRefresh();
            }

            // Enter key on request ID filter
            document.getElementById('sglog-filter-rid').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); sglogRefresh(); }
            });
        })();
        </script>
        HTML;
    }

    protected function getLabel(): string
    {
        return '';
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
