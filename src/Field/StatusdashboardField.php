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
use Cybersalt\Plugin\System\SgCache\SiteToolsClient;

class StatusdashboardField extends FormField
{
    protected $type = 'Statusdashboard';

    protected function getInput(): string
    {
        $token   = Session::getFormToken();
        $testUrl = Uri::base() . 'index.php?option=com_ajax&plugin=sgcache&group=system&format=raw&' . $token . '=1&action=test';
        // Defense-in-depth: encode the URL via json_encode() for safe JS-string-context
        // interpolation (matches the v2.1.0 tmpl/viewer.php hardening pattern).
        $testUrlJs = json_encode($testUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $jsFlags   = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
        $ajaxBase  = Uri::base() . 'index.php?option=com_ajax&plugin=sgcache&group=system&format=raw&' . $token . '=1&action=';
        $selfTestUrlJs  = json_encode($ajaxBase . 'selftest', $jsFlags);
        $decisionsUrlJs = json_encode($ajaxBase . 'decisions', $jsFlags);
        $stringsJs      = json_encode([
            'running'  => html_entity_decode(Text::_('PLG_SYSTEM_SGCACHE_STATUS_SELFTEST_RUNNING'), ENT_QUOTES, 'UTF-8'),
            'home'     => Text::_('PLG_SYSTEM_SGCACHE_STATUS_SELFTEST_HOME'),
            'article'  => Text::_('PLG_SYSTEM_SGCACHE_STATUS_SELFTEST_ARTICLE'),
            'details'  => Text::_('PLG_SYSTEM_SGCACHE_STATUS_SELFTEST_DETAILS'),
            'none'     => Text::_('PLG_SYSTEM_SGCACHE_STATUS_RECENT_NONE'),
            'recent'   => Text::_('PLG_SYSTEM_SGCACHE_STATUS_RECENT_VALUE'),
            'failed'   => Text::_('PLG_SYSTEM_SGCACHE_PURGE_FAILED'),
        ], $jsFlags);
        $selfTestBtn = Text::_('PLG_SYSTEM_SGCACHE_STATUS_SELFTEST_BTN');
        $labelRecent = Text::_('PLG_SYSTEM_SGCACHE_STATUS_LABEL_RECENT');
        // Cache-bust the logo on plugin updates so browsers fetch the new SVG
        $logoVer = '2.3.0';
        $logoUrl = Uri::root(true) . '/media/plg_system_sgcache/images/logo.svg?v=' . $logoVer;

        $isSiteGround = SiteToolsClient::isSiteGround();
        $sgStatusText = $isSiteGround
            ? Text::_('PLG_SYSTEM_SGCACHE_STATUS_SG_DETECTED')
            : Text::_('PLG_SYSTEM_SGCACHE_STATUS_SG_NOT_DETECTED');
        $sgStatusClass = $isSiteGround ? 'ok' : 'warn';

        $title    = Text::_('PLG_SYSTEM_SGCACHE_STATUS_TITLE');
        $subtitle = Text::_('PLG_SYSTEM_SGCACHE_STATUS_SUBTITLE');
        $testBtn  = Text::_('PLG_SYSTEM_SGCACHE_STATUS_TEST_BTN');

        $labelSG       = Text::_('PLG_SYSTEM_SGCACHE_STATUS_LABEL_SG');
        $labelSocket   = Text::_('PLG_SYSTEM_SGCACHE_STATUS_LABEL_SOCKET');
        $labelCookie   = Text::_('PLG_SYSTEM_SGCACHE_STATUS_LABEL_COOKIE');
        $labelLastTest = Text::_('PLG_SYSTEM_SGCACHE_STATUS_LABEL_LAST_TEST');
        $checking      = Text::_('PLG_SYSTEM_SGCACHE_STATUS_CHECKING');
        $never         = Text::_('PLG_SYSTEM_SGCACHE_STATUS_NEVER');

        return <<<HTML
        <style>
            /* Scoped theme variables (light defaults, switch on any dark-mode trigger) */
            .sgstatus-grid {
                --sg-text: #212529;
                --sg-text-muted: #6c757d;
                --sg-bg: #ffffff;
                --sg-bg-subtle: #f8f9fa;
                --sg-bg-elevated: #ffffff;
                --sg-border: #dee2e6;
            }
            [data-bs-theme="dark"] .sgstatus-grid,
            [data-color-scheme="dark"] .sgstatus-grid,
            .theme-dark .sgstatus-grid {
                --sg-text: rgba(255, 255, 255, 0.92);
                --sg-text-muted: rgba(255, 255, 255, 0.6);
                --sg-bg: rgba(255, 255, 255, 0.02);
                --sg-bg-subtle: rgba(255, 255, 255, 0.04);
                --sg-bg-elevated: rgba(255, 255, 255, 0.06);
                --sg-border: rgba(255, 255, 255, 0.12);
            }
            .sgstatus-card {
                background: linear-gradient(135deg, #1e40af 0%, #0f1e4d 100%);
                color: #fff;
                border-radius: 10px;
                padding: 18px 20px;
                margin-bottom: 16px;
                box-shadow: 0 2px 12px rgba(15, 30, 77, 0.15);
                display: flex;
                gap: 18px;
                align-items: center;
                flex-wrap: wrap;
            }
            .sgstatus-card-logo {
                flex: 0 0 auto;
                width: 64px; height: 64px;
                background: rgba(255, 255, 255, 0.96);
                border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
                padding: 10px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18);
            }
            .sgstatus-card-logo img { display: block; width: 100%; height: 100%; }
            .sgstatus-card-title { flex: 1 1 auto; min-width: 200px; }
            .sgstatus-card-title h3 { margin: 0 0 2px; font-size: 17px; font-weight: 700; color: #fff; }
            .sgstatus-card-title p  { margin: 0; font-size: 12px; color: rgba(255,255,255,0.75); }
            .sgstatus-card-actions { flex: 0 0 auto; }
            .sgstatus-card-actions .btn { background: #fbbf24; color: #0f1e4d; border: 0; font-weight: 600; }
            .sgstatus-card-actions .btn:hover { background: #f59e0b; color: #0f1e4d; }

            .sgstatus-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 10px;
                margin: 0 0 16px;
            }
            .sgstatus-tile {
                background: var(--sg-bg-subtle);
                border: 1px solid var(--sg-border);
                border-radius: 8px;
                padding: 12px 14px;
                font-size: 13px;
                color: var(--sg-text);
            }
            .sgstatus-tile-label {
                color: var(--sg-text-muted);
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 4px;
            }
            .sgstatus-tile-value { font-weight: 600; font-size: 14px; line-height: 1.3; }
            .sgstatus-tile.ok    .sgstatus-tile-value { color: var(--bs-success, #198754); }
            .sgstatus-tile.warn  .sgstatus-tile-value { color: var(--bs-warning, #ffc107); }
            .sgstatus-tile.fail  .sgstatus-tile-value { color: var(--bs-danger,  #dc3545); }
            .sgstatus-tile-dot {
                display: inline-block;
                width: 8px; height: 8px;
                border-radius: 50%;
                margin-right: 6px;
                vertical-align: middle;
            }
            .sgstatus-tile.ok   .sgstatus-tile-dot { background: var(--bs-success, #198754); }
            .sgstatus-tile.warn .sgstatus-tile-dot { background: var(--bs-warning, #ffc107); }
            .sgstatus-tile.fail .sgstatus-tile-dot { background: var(--bs-danger,  #dc3545); }
            .sgstatus-card-actions { display: flex; gap: 8px; flex-wrap: wrap; }
            #sgstatus-selftest:empty, #sgstatus-warning:empty { display: none; }
            #sgstatus-selftest .sgstatus-result { margin-bottom: 8px; }
            #sgstatus-selftest .sgstatus-result-url { font-size: 12px; word-break: break-all; opacity: .8; }
            #sgstatus-selftest details { margin-top: 4px; font-size: 12px; }
            #sgstatus-selftest pre { margin: 4px 0 0; white-space: pre-wrap; font-size: 11px; }
        </style>

        <div class="sgstatus-card">
            <div class="sgstatus-card-logo">
                <img src="{$logoUrl}" alt="Cybersalt SiteGround Cache">
            </div>
            <div class="sgstatus-card-title">
                <h3>{$title}</h3>
                <p>{$subtitle}</p>
            </div>
            <div class="sgstatus-card-actions">
                <button type="button" class="btn btn-sm" onclick="sgstatusRunTest()">{$testBtn}</button>
                <button type="button" class="btn btn-sm" id="sgstatus-selftest-btn" onclick="sgstatusSelfTest()">{$selfTestBtn}</button>
            </div>
        </div>

        <div id="sgstatus-warning"></div>
        <div id="sgstatus-selftest"></div>

        <div class="sgstatus-grid" id="sgstatus-grid">
            <div class="sgstatus-tile {$sgStatusClass}">
                <div class="sgstatus-tile-label">{$labelSG}</div>
                <div class="sgstatus-tile-value"><span class="sgstatus-tile-dot"></span>{$sgStatusText}</div>
            </div>
            <div class="sgstatus-tile" id="sgstatus-tile-socket">
                <div class="sgstatus-tile-label">{$labelSocket}</div>
                <div class="sgstatus-tile-value"><span class="sgstatus-tile-dot"></span>{$checking}</div>
            </div>
            <div class="sgstatus-tile" id="sgstatus-tile-cookie">
                <div class="sgstatus-tile-label">{$labelCookie}</div>
                <div class="sgstatus-tile-value"><span class="sgstatus-tile-dot"></span>{$checking}</div>
            </div>
            <div class="sgstatus-tile" id="sgstatus-tile-recent">
                <div class="sgstatus-tile-label">{$labelRecent}</div>
                <div class="sgstatus-tile-value"><span class="sgstatus-tile-dot"></span>{$checking}</div>
            </div>
            <div class="sgstatus-tile" id="sgstatus-tile-lasttest">
                <div class="sgstatus-tile-label">{$labelLastTest}</div>
                <div class="sgstatus-tile-value">{$never}</div>
            </div>
        </div>

        <script>
        (function() {
            var TEST_URL = {$testUrlJs};
            var SELFTEST_URL = {$selfTestUrlJs};
            var DECISIONS_URL = {$decisionsUrlJs};
            var S = {$stringsJs};

            function el(tag, cls, text) {
                var e = document.createElement(tag);
                if (cls) e.className = cls;
                if (text !== undefined) e.textContent = text;
                return e;
            }

            function showDecisions(d) {
                var tile = document.getElementById('sgstatus-tile-recent');
                var warn = document.getElementById('sgstatus-warning');
                if (!tile || !d) return;
                var v = tile.querySelector('.sgstatus-tile-value');
                tile.classList.remove('ok', 'warn', 'fail');
                v.textContent = '';
                v.appendChild(el('span', 'sgstatus-tile-dot'));
                if (!d.total) {
                    v.appendChild(document.createTextNode(S.none));
                } else {
                    tile.classList.add(d.warning ? 'warn' : 'ok');
                    v.appendChild(document.createTextNode(S.recent.replace('%1\$d', d.cached).replace('%2\$d', d.total)));
                }
                warn.textContent = '';
                if (d.warning) warn.appendChild(el('div', 'alert alert-warning', d.warning));
            }

            window.sgstatusSelfTest = function() {
                var box = document.getElementById('sgstatus-selftest');
                var btn = document.getElementById('sgstatus-selftest-btn');
                box.textContent = '';
                box.appendChild(el('div', 'alert alert-info', S.running));
                btn.disabled = true;
                fetch(SELFTEST_URL).then(function(r) { return r.json(); }).then(function(d) {
                    btn.disabled = false;
                    box.textContent = '';
                    if (!d.success) { box.appendChild(el('div', 'alert alert-warning', d.message || S.failed)); return; }
                    (d.rows || []).forEach(function(row) {
                        var cls = row.status === 'ok' ? 'alert-success' : (row.status === 'fail' ? 'alert-danger' : 'alert-warning');
                        var a = el('div', 'alert sgstatus-result ' + cls);
                        var head = el('div');
                        head.appendChild(el('strong', '', (row.kind === 'home' ? S.home : S.article) + ': '));
                        head.appendChild(document.createTextNode(row.message));
                        a.appendChild(head);
                        a.appendChild(el('div', 'sgstatus-result-url', row.url));
                        var det = el('details');
                        det.appendChild(el('summary', '', S.details));
                        det.appendChild(el('pre', '', JSON.stringify({ first: row.first, second: row.second }, null, 2)));
                        a.appendChild(det);
                        box.appendChild(a);
                    });
                    showDecisions(d.decisions);
                }).catch(function() {
                    btn.disabled = false;
                    box.textContent = '';
                    box.appendChild(el('div', 'alert alert-danger', S.failed));
                });
            };

            function loadDecisions() {
                fetch(DECISIONS_URL).then(function(r) { return r.json(); }).then(function(d) { showDecisions(d.decisions); }).catch(function() {});
            }

            function setTile(id, cls, text) {
                var el = document.getElementById(id);
                if (!el) return;
                el.classList.remove('ok', 'warn', 'fail');
                if (cls) el.classList.add(cls);
                var v = el.querySelector('.sgstatus-tile-value');
                if (v) v.innerHTML = '<span class="sgstatus-tile-dot"></span>' + text;
            }

            function setLastTest(text) {
                var el = document.getElementById('sgstatus-tile-lasttest');
                if (!el) return;
                var v = el.querySelector('.sgstatus-tile-value');
                if (v) v.textContent = text;
            }

            window.sgstatusRunTest = function() {
                setTile('sgstatus-tile-socket', '', 'Testing…');
                fetch(TEST_URL).then(function(r) { return r.json(); }).then(function(d) {
                    if (d.siteground && d.daemon) {
                        setTile('sgstatus-tile-socket', 'ok', 'Reachable');
                    } else if (d.siteground) {
                        setTile('sgstatus-tile-socket', 'fail', 'Not reachable');
                    } else {
                        setTile('sgstatus-tile-socket', 'warn', 'Not on SiteGround');
                    }
                    setLastTest(new Date().toLocaleString());
                }).catch(function() {
                    setTile('sgstatus-tile-socket', 'fail', 'Test failed');
                    setLastTest(new Date().toLocaleString());
                });
            };

            function readSuppressSetting() {
                var input = document.querySelector('input[name="jform[params][suppress_session_cookie]"]:checked');
                if (input) {
                    if (input.value === '1') setTile('sgstatus-tile-cookie', 'ok', 'Stripping');
                    else                    setTile('sgstatus-tile-cookie', 'warn', 'Not stripping');
                } else {
                    setTile('sgstatus-tile-cookie', 'ok', 'Stripping (default)');
                }
            }

            function init() {
                readSuppressSetting();
                sgstatusRunTest();
                loadDecisions();
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
        </script>
HTML;
    }

    protected function getLabel(): string
    {
        return '';
    }
}
