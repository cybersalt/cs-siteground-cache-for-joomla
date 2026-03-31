<?php

/**
 * @package     Cybersalt.Plugin.System.SgCache
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Cybersalt\Plugin\System\SgCache\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Router\Route;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Cybersalt\Plugin\System\SgCache\Logger;
use Cybersalt\Plugin\System\SgCache\SiteToolsClient;

class SgCache extends CMSPlugin implements SubscriberInterface
{
    /**
     * URLs queued for purging during this request.
     *
     * @var string[]
     */
    private array $purgeQueue = [];

    /**
     * Whether a full cache flush has already been triggered this request.
     */
    private bool $fullFlushTriggered = false;

    /**
     * Whether the logger has been initialized.
     */
    private bool $loggerReady = false;

    public static function getSubscribedEvents(): array
    {
        return [
            // Early init — set up logger
            'onAfterInitialise'       => 'onAfterInitialise',

            // Content events — selective purge
            'onContentAfterSave'      => 'onContentAfterSave',
            'onContentAfterDelete'    => 'onContentAfterDelete',
            'onContentChangeState'    => 'onContentChangeState',

            // Category events
            'onCategoryChangeState'   => 'onCategoryChangeState',
            'onCategoryAfterSave'     => 'onCategoryAfterSave',
            'onCategoryAfterDelete'   => 'onCategoryAfterDelete',

            // System events — full purge
            'onExtensionAfterInstall'   => 'purgeEverything',
            'onExtensionAfterUpdate'    => 'purgeEverything',
            'onExtensionAfterUninstall' => 'purgeEverything',

            // Admin toolbar button + cache headers + template editor detection
            'onAfterRender'           => 'onAfterRender',

            // Flush the queue at shutdown
            'onAfterRespond'          => 'onAfterRespond',

            // AJAX handler
            'onAjaxSgcache'           => 'onAjaxSgcache',
        ];
    }

    // ------------------------------------------------------------------
    // Initialization
    // ------------------------------------------------------------------

    public function onAfterInitialise(): void
    {
        $this->initLogger();
    }

    private function initLogger(): void
    {
        if ($this->loggerReady) {
            return;
        }

        $enableLogging = $this->params->get('enable_logging', 0);
        $logFile = $this->params->get('log_file', 'sgcache.log');
        $maxSize = (int) $this->params->get('max_log_size', 5);

        Logger::init((bool) $enableLogging, $logFile, $maxSize);
        $this->loggerReady = true;

        Logger::debug('plugin_init', [
            'siteground'  => SiteToolsClient::isSiteGround(),
            'autoflush'   => (bool) $this->params->get('enable_autoflush', 1),
            'cache_headers' => (bool) $this->params->get('enable_dynamic_cache', 1),
            'debug_panel' => (bool) $this->params->get('enable_debug', 0),
        ]);
    }

    // ------------------------------------------------------------------
    // Content events — queue related URLs for selective purge
    // ------------------------------------------------------------------

    public function onContentAfterSave(Event $event): void
    {
        if (!$this->params->get('enable_autoflush', 1)) {
            return;
        }

        [$context, $article] = array_values($event->getArguments());

        // Detect template file saves via com_templates
        if ($context === 'com_templates.style' || $context === 'com_templates.template') {
            Logger::info('template_save_detected', ['context' => $context]);
            $this->fullFlushTriggered = true;
            return;
        }

        Logger::info('content_save', [
            'context' => $context,
            'id'      => $article->id ?? null,
            'title'   => $article->title ?? null,
        ]);

        $this->queueContentUrls($context, $article);
    }

    public function onContentAfterDelete(Event $event): void
    {
        if (!$this->params->get('enable_autoflush', 1)) {
            return;
        }

        [$context, $article] = array_values($event->getArguments());

        Logger::info('content_delete', [
            'context' => $context,
            'id'      => $article->id ?? null,
            'title'   => $article->title ?? null,
        ]);

        $this->queueContentUrls($context, $article);
    }

    public function onContentChangeState(Event $event): void
    {
        if (!$this->params->get('enable_autoflush', 1)) {
            return;
        }

        [$context, $pks] = array_values($event->getArguments());

        Logger::info('content_state_change', [
            'context' => $context,
            'ids'     => $pks,
        ]);

        $this->addToQueue(Uri::root(true) . '/');

        if ($context === 'com_content.article' && !empty($pks)) {
            foreach ($pks as $pk) {
                $url = Route::link('site', 'index.php?option=com_content&view=article&id=' . (int) $pk);
                if ($url) {
                    $this->addToQueue($url);
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Category events
    // ------------------------------------------------------------------

    public function onCategoryChangeState(Event $event): void
    {
        if (!$this->params->get('enable_autoflush', 1)) {
            return;
        }

        Logger::info('category_state_change');
        $this->addToQueue(Uri::root(true) . '/');
    }

    public function onCategoryAfterSave(Event $event): void
    {
        if (!$this->params->get('enable_autoflush', 1)) {
            return;
        }

        Logger::info('category_save');
        $this->addToQueue(Uri::root(true) . '/');
    }

    public function onCategoryAfterDelete(Event $event): void
    {
        if (!$this->params->get('enable_autoflush', 1)) {
            return;
        }

        Logger::info('category_delete');
        $this->addToQueue(Uri::root(true) . '/');
    }

    // ------------------------------------------------------------------
    // Extension events — full purge
    // ------------------------------------------------------------------

    public function purgeEverything(Event $event): void
    {
        if (!$this->params->get('enable_autoflush', 1)) {
            return;
        }

        Logger::info('full_flush_triggered', ['trigger' => $event->getName()]);
        $this->fullFlushTriggered = true;
    }

    // ------------------------------------------------------------------
    // onAfterRender: cache headers (frontend) + toolbar button (admin)
    // + template editor detection + debug panel
    // ------------------------------------------------------------------

    public function onAfterRender(): void
    {
        $this->initLogger();

        $app = $this->getApplication();

        if ($app->isClient('administrator')) {
            $this->injectAdminToolbarButton();
            $this->detectTemplateEditorSave();
            $this->injectDebugPanel();
            return;
        }

        if (!$app->isClient('site')) {
            return;
        }

        // -- Frontend: set cache headers --

        if (!$this->params->get('enable_dynamic_cache', 1)) {
            return;
        }

        $user = $app->getIdentity();
        if ($user && !$user->guest && !$this->params->get('cache_logged_in', 0)) {
            Logger::debug('header_set', ['X-Cache-Enabled' => 'False', 'reason' => 'logged_in_user']);
            $app->setHeader('X-Cache-Enabled', 'False', true);
            return;
        }

        $currentPath = Uri::getInstance()->getPath();
        if ($this->isUrlExcluded($currentPath)) {
            Logger::debug('header_set', ['X-Cache-Enabled' => 'False', 'reason' => 'url_excluded', 'path' => $currentPath]);
            $app->setHeader('X-Cache-Enabled', 'False', true);
            return;
        }

        $option = $app->input->get('option', '');
        if ($this->isComponentExcluded($option)) {
            Logger::debug('header_set', ['X-Cache-Enabled' => 'False', 'reason' => 'component_excluded', 'component' => $option]);
            $app->setHeader('X-Cache-Enabled', 'False', true);
            return;
        }

        Logger::debug('header_set', ['X-Cache-Enabled' => 'True']);
        $app->setHeader('X-Cache-Enabled', 'True', true);

        if ($this->params->get('vary_user_agent', 0)) {
            $app->setHeader('Vary', 'User-Agent', true);
        }
    }

    // ------------------------------------------------------------------
    // Admin toolbar button injection
    // ------------------------------------------------------------------

    private function injectAdminToolbarButton(): void
    {
        if (!$this->params->get('show_toolbar_button', 1)) {
            return;
        }

        $app = $this->getApplication();
        $user = $app->getIdentity();

        if (!$user || !$user->authorise('core.manage')) {
            return;
        }

        $body = $app->getBody();

        if (str_contains($body, 'sgcache-toolbar-btn')) {
            return;
        }

        $token = Session::getFormToken();
        $ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=sgcache&group=system&format=raw&' . $token . '=1';

        $buttonMode = $this->params->get('toolbar_button_mode', 'all');
        $buttonLabel = Text::_('PLG_SYSTEM_SGCACHE_TOOLBAR_PURGE');

        if ($buttonMode === 'all') {
            $buttonHtml = $this->buildSimpleToolbarButton($ajaxUrl, $buttonLabel);
        } else {
            $buttonHtml = $this->buildDropdownToolbarButton($ajaxUrl, $buttonLabel);
        }

        $injection = $this->buildToolbarAssets($ajaxUrl) . $buttonHtml;

        $body = str_replace('</body>', $injection . '</body>', $body);
        $app->setBody($body);
    }

    private function buildSimpleToolbarButton(string $ajaxUrl, string $label): string
    {
        return <<<HTML
        <div id="sgcache-toolbar-wrapper">
            <button type="button" id="sgcache-toolbar-btn" class="sgcache-btn" onclick="sgcacheToolbarPurge('all')" title="{$this->esc($label)}">
                <span class="sgcache-icon">&#x1f5d1;</span>
                <span class="sgcache-label">{$this->esc($label)}</span>
            </button>
        </div>
        HTML;
    }

    private function buildDropdownToolbarButton(string $ajaxUrl, string $label): string
    {
        $purgeAllLabel = Text::_('PLG_SYSTEM_SGCACHE_TOOLBAR_PURGE_ALL');

        $pathItems = '';
        $customPaths = $this->getToolbarPurgePaths();
        foreach ($customPaths as $path) {
            $pathEsc = $this->esc($path['label']);
            $pathVal = $this->esc($path['path']);
            $pathItems .= '<a class="sgcache-dropdown-item" href="#" onclick="sgcacheToolbarPurge(\'' . $pathVal . '\'); return false;">' . $pathEsc . '</a>';
        }

        return <<<HTML
        <div id="sgcache-toolbar-wrapper">
            <div class="sgcache-btn-group">
                <button type="button" id="sgcache-toolbar-btn" class="sgcache-btn" onclick="sgcacheToolbarPurge('all')" title="{$this->esc($purgeAllLabel)}">
                    <span class="sgcache-icon">&#x1f5d1;</span>
                    <span class="sgcache-label">{$this->esc($label)}</span>
                </button>
                <button type="button" class="sgcache-btn sgcache-dropdown-toggle" onclick="sgcacheToggleDropdown()" title="More options">
                    <span class="sgcache-caret">&#9662;</span>
                </button>
                <div id="sgcache-dropdown-menu" class="sgcache-dropdown-menu">
                    <a class="sgcache-dropdown-item" href="#" onclick="sgcacheToolbarPurge('all'); return false;">{$this->esc($purgeAllLabel)}</a>
                    <div class="sgcache-dropdown-divider"></div>
                    {$pathItems}
                </div>
            </div>
        </div>
        HTML;
    }

    private function buildToolbarAssets(string $ajaxUrl): string
    {
        $successMsg = $this->esc(Text::_('PLG_SYSTEM_SGCACHE_PURGE_SUCCESS'));
        $failMsg = $this->esc(Text::_('PLG_SYSTEM_SGCACHE_PURGE_FAILED'));
        $purgingMsg = $this->esc(Text::_('PLG_SYSTEM_SGCACHE_TOOLBAR_PURGING'));

        return <<<HTML
        <style>
            #sgcache-toolbar-wrapper {
                position: fixed;
                top: 4px;
                right: 80px;
                z-index: 10000;
            }
            .sgcache-btn-group {
                position: relative;
                display: inline-flex;
            }
            .sgcache-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 5px 12px;
                border: 1px solid rgba(255,255,255,0.3);
                border-radius: 4px;
                background: rgba(0,0,0,0.2);
                color: #fff;
                font-size: 13px;
                cursor: pointer;
                transition: background 0.2s;
                line-height: 1.4;
                font-family: inherit;
            }
            .sgcache-btn:hover { background: rgba(0,0,0,0.4); }
            .sgcache-btn.sgcache-success { background: rgba(40,167,69,0.6); }
            .sgcache-btn.sgcache-error { background: rgba(220,53,69,0.6); }
            .sgcache-btn.sgcache-busy { opacity: 0.7; cursor: wait; }
            .sgcache-btn-group .sgcache-btn:first-child { border-radius: 4px 0 0 4px; }
            .sgcache-btn-group .sgcache-dropdown-toggle { border-radius: 0 4px 4px 0; border-left: 1px solid rgba(255,255,255,0.2); padding: 5px 8px; }
            .sgcache-dropdown-menu { display: none; position: absolute; top: 100%; right: 0; margin-top: 4px; min-width: 200px; background: #2d3436; border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); overflow: hidden; }
            .sgcache-dropdown-menu.sgcache-open { display: block; }
            .sgcache-dropdown-item { display: block; padding: 8px 14px; color: #dfe6e9; text-decoration: none; font-size: 13px; transition: background 0.15s; }
            .sgcache-dropdown-item:hover { background: rgba(255,255,255,0.1); color: #fff; text-decoration: none; }
            .sgcache-dropdown-divider { height: 1px; background: rgba(255,255,255,0.1); margin: 2px 0; }
        </style>
        <script>
        var sgcacheAjaxUrl = '{$ajaxUrl}';
        function sgcacheToolbarPurge(pathOrAll) {
            var btn = document.getElementById('sgcache-toolbar-btn');
            var label = btn.querySelector('.sgcache-label');
            var origText = label.textContent;
            btn.classList.add('sgcache-busy');
            btn.classList.remove('sgcache-success', 'sgcache-error');
            label.textContent = '{$purgingMsg}';
            sgcacheCloseDropdown();
            var url = sgcacheAjaxUrl + '&action=purge';
            if (pathOrAll && pathOrAll !== 'all') { url += '&purge_path=' + encodeURIComponent(pathOrAll); }
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                btn.classList.remove('sgcache-busy');
                if (data.success) { btn.classList.add('sgcache-success'); label.textContent = '{$successMsg}'; }
                else { btn.classList.add('sgcache-error'); label.textContent = data.message || '{$failMsg}'; }
                setTimeout(function() { btn.classList.remove('sgcache-success', 'sgcache-error'); label.textContent = origText; }, 3000);
            }).catch(function(e) {
                btn.classList.remove('sgcache-busy');
                btn.classList.add('sgcache-error');
                label.textContent = '{$failMsg}';
                setTimeout(function() { btn.classList.remove('sgcache-error'); label.textContent = origText; }, 3000);
            });
        }
        function sgcacheToggleDropdown() { var m = document.getElementById('sgcache-dropdown-menu'); if (m) m.classList.toggle('sgcache-open'); }
        function sgcacheCloseDropdown() { var m = document.getElementById('sgcache-dropdown-menu'); if (m) m.classList.remove('sgcache-open'); }
        document.addEventListener('click', function(e) { if (!e.target.closest('.sgcache-btn-group')) sgcacheCloseDropdown(); });
        </script>
        HTML;
    }

    // ------------------------------------------------------------------
    // Template editor save detection
    // ------------------------------------------------------------------

    private function detectTemplateEditorSave(): void
    {
        if (!$this->params->get('enable_autoflush', 1)) {
            return;
        }

        $app = $this->getApplication();
        $option = $app->input->get('option', '');
        $task = $app->input->get('task', '');

        if ($option === 'com_templates' && (str_contains($task, 'save') || str_contains($task, 'apply'))) {
            if ($app->input->getMethod() === 'POST') {
                Logger::info('template_editor_save', ['task' => $task]);
                $this->fullFlushTriggered = true;
            }
        }
    }

    // ------------------------------------------------------------------
    // Debug panel — shows cache operations in admin footer
    // ------------------------------------------------------------------

    private function injectDebugPanel(): void
    {
        if (!$this->params->get('enable_debug', 0)) {
            return;
        }

        $app = $this->getApplication();
        $user = $app->getIdentity();

        if (!$user || !$user->authorise('core.manage')) {
            return;
        }

        $entries = Logger::getRequestEntries();
        $requestId = Logger::getRequestId();
        $isSiteGround = SiteToolsClient::isSiteGround();
        $queueCount = \count($this->purgeQueue);

        $body = $app->getBody();

        // Build debug panel HTML
        $panelHtml = $this->buildDebugPanel($entries, $requestId, $isSiteGround, $queueCount);

        $body = str_replace('</body>', $panelHtml . '</body>', $body);
        $app->setBody($body);
    }

    private function buildDebugPanel(array $entries, string $requestId, bool $isSiteGround, int $queueCount): string
    {
        $sgStatus = $isSiteGround
            ? '<span style="color:#28a745;">Detected</span>'
            : '<span style="color:#ffc107;">Not detected</span>';

        $entryCount = \count($entries);
        $toggleLabel = Text::_('PLG_SYSTEM_SGCACHE_DEBUG_PANEL_TITLE');

        // Build entry rows
        $rows = '';
        foreach ($entries as $entry) {
            $level = $entry['level'] ?? 'info';
            $levelColor = match ($level) {
                'error'   => '#dc3545',
                'warning' => '#ffc107',
                'debug'   => '#6c757d',
                default   => '#17a2b8',
            };
            $event = $this->esc($entry['event'] ?? '');
            $time = $entry['elapsed_ms'] ?? 0;
            $data = !empty($entry['data']) ? $this->esc(json_encode($entry['data'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) : '';

            $rows .= <<<HTML
            <tr>
                <td style="color:{$levelColor};font-weight:600;text-transform:uppercase;">{$level}</td>
                <td>{$event}</td>
                <td style="text-align:right;">{$time}ms</td>
                <td><pre style="margin:0;white-space:pre-wrap;font-size:11px;max-height:120px;overflow:auto;">{$data}</pre></td>
            </tr>
            HTML;
        }

        return <<<HTML
        <style>
            #sgcache-debug-panel {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                z-index: 10001;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', monospace;
                font-size: 12px;
            }
            #sgcache-debug-toggle {
                position: absolute;
                bottom: 100%;
                right: 20px;
                padding: 4px 14px;
                background: #1e1e1e;
                color: #17a2b8;
                border: 1px solid #333;
                border-bottom: none;
                border-radius: 6px 6px 0 0;
                cursor: pointer;
                font-size: 12px;
                font-family: inherit;
            }
            #sgcache-debug-toggle:hover { background: #2d2d2d; }
            #sgcache-debug-body {
                background: #1e1e1e;
                color: #e0e0e0;
                border-top: 2px solid #17a2b8;
                max-height: 300px;
                overflow: auto;
                display: none;
            }
            #sgcache-debug-body.sgcache-debug-open { display: block; }
            #sgcache-debug-bar {
                display: flex;
                gap: 20px;
                padding: 6px 14px;
                background: #252525;
                border-bottom: 1px solid #333;
                flex-wrap: wrap;
            }
            #sgcache-debug-bar span { white-space: nowrap; }
            #sgcache-debug-table {
                width: 100%;
                border-collapse: collapse;
            }
            #sgcache-debug-table th {
                text-align: left;
                padding: 4px 10px;
                background: #252525;
                color: #999;
                font-weight: 600;
                border-bottom: 1px solid #333;
                position: sticky;
                top: 0;
            }
            #sgcache-debug-table td {
                padding: 4px 10px;
                border-bottom: 1px solid #2a2a2a;
                vertical-align: top;
            }
        </style>
        <div id="sgcache-debug-panel">
            <button id="sgcache-debug-toggle" onclick="document.getElementById('sgcache-debug-body').classList.toggle('sgcache-debug-open')">
                {$this->esc($toggleLabel)} ({$entryCount})
            </button>
            <div id="sgcache-debug-body">
                <div id="sgcache-debug-bar">
                    <span>Request: <strong>{$requestId}</strong></span>
                    <span>SiteGround: {$sgStatus}</span>
                    <span>Queue: <strong>{$queueCount}</strong> URLs</span>
                    <span>Full flush: <strong>{$this->esc($this->fullFlushTriggered ? 'Yes' : 'No')}</strong></span>
                    <span>Log entries: <strong>{$entryCount}</strong></span>
                </div>
                <table id="sgcache-debug-table">
                    <thead><tr><th>Level</th><th>Event</th><th>Time</th><th>Data</th></tr></thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>
        </div>
        HTML;
    }

    // ------------------------------------------------------------------
    // Process the purge queue at end of request
    // ------------------------------------------------------------------

    public function onAfterRespond(): void
    {
        if (!SiteToolsClient::isSiteGround()) {
            return;
        }

        $hostname = $this->getSiteHostname();

        if ($this->fullFlushTriggered) {
            Logger::info('queue_process', ['action' => 'full_flush', 'reason' => 'full_flush_triggered']);
            SiteToolsClient::flushDynamicCache($hostname, '/(.*)');
            return;
        }

        if (empty($this->purgeQueue)) {
            return;
        }

        $threshold = (int) $this->params->get('purge_threshold', 10);
        $urls = array_unique($this->purgeQueue);

        Logger::info('queue_process', [
            'url_count' => \count($urls),
            'threshold' => $threshold,
            'urls'      => $urls,
        ]);

        if (\count($urls) >= $threshold) {
            Logger::info('queue_threshold_exceeded', ['count' => \count($urls), 'threshold' => $threshold]);
            SiteToolsClient::flushDynamicCache($hostname, '/(.*)');
        } else {
            foreach ($urls as $url) {
                $path = parse_url($url, PHP_URL_PATH) ?: '/';
                SiteToolsClient::flushDynamicCache($hostname, $path . '(.*)');
            }
        }
    }

    // ------------------------------------------------------------------
    // AJAX handler — purge + log viewer operations
    // ------------------------------------------------------------------

    public function onAjaxSgcache(Event $event): void
    {
        $this->initLogger();

        $app = $this->getApplication();

        if (!$app->isClient('administrator')) {
            $this->setAjaxResult($event, json_encode(['error' => Text::_('PLG_SYSTEM_SGCACHE_ACCESS_DENIED')]));
            return;
        }

        if (!Session::checkToken('get') && !Session::checkToken('post')) {
            $this->setAjaxResult($event, json_encode(['error' => Text::_('PLG_SYSTEM_SGCACHE_INVALID_TOKEN')]));
            return;
        }

        $action = $app->input->get('action', '');

        switch ($action) {
            case 'purge':
                $this->ajaxPurge($event);
                break;
            case 'view':
                $this->ajaxViewLog($event);
                break;
            case 'stats':
                $this->ajaxGetStats($event);
                break;
            case 'clear':
                $this->ajaxClearLog($event);
                break;
            case 'download':
                $this->ajaxDownloadLog();
                break;
            case 'viewer':
                $this->ajaxRenderViewer();
                break;
            case 'test':
                $this->ajaxTestLogging($event);
                break;
            default:
                $this->setAjaxResult($event, json_encode(['error' => Text::_('PLG_SYSTEM_SGCACHE_INVALID_ACTION')]));
        }
    }

    private function ajaxPurge(Event $event): void
    {
        if (!SiteToolsClient::isSiteGround()) {
            $this->setAjaxResult($event, json_encode([
                'success' => false,
                'message' => Text::_('PLG_SYSTEM_SGCACHE_NOT_SITEGROUND'),
            ]));
            return;
        }

        $app = $this->getApplication();
        $hostname = $this->getSiteHostname();
        $purgePath = $app->input->getString('purge_path', '');

        if (!empty($purgePath)) {
            $purgePath = '/' . ltrim($purgePath, '/');
            Logger::info('manual_purge_path', ['path' => $purgePath]);
            $result = SiteToolsClient::flushDynamicCache($hostname, $purgePath . '(.*)');
            $message = $result
                ? Text::sprintf('PLG_SYSTEM_SGCACHE_PURGE_PATH_SUCCESS', $purgePath)
                : Text::_('PLG_SYSTEM_SGCACHE_PURGE_FAILED');
        } else {
            Logger::info('manual_purge_all');
            $result = SiteToolsClient::flushDynamicCache($hostname, '/(.*)');
            $message = $result
                ? Text::_('PLG_SYSTEM_SGCACHE_PURGE_SUCCESS')
                : Text::_('PLG_SYSTEM_SGCACHE_PURGE_FAILED');
        }

        $this->setAjaxResult($event, json_encode([
            'success' => $result,
            'message' => $message,
        ]));
    }

    private function ajaxViewLog(Event $event): void
    {
        $app = $this->getApplication();
        $limit = $app->input->getInt('lines', 50);
        $offset = $app->input->getInt('offset', 0);
        $requestId = $app->input->getString('request_id', '');
        $level = $app->input->getString('level', '');
        $eventFilter = $app->input->getString('event', '');

        $result = Logger::readEntries($limit, $offset, $requestId, $level, $eventFilter);
        $result['success'] = true;

        $this->setAjaxResult($event, json_encode($result));
    }

    private function ajaxGetStats(Event $event): void
    {
        $stats = Logger::getStats();
        $this->setAjaxResult($event, json_encode(['success' => true, 'stats' => $stats]));
    }

    private function ajaxClearLog(Event $event): void
    {
        $result = Logger::clear();
        $this->setAjaxResult($event, json_encode($result));
    }

    private function ajaxDownloadLog(): void
    {
        $logFile = Logger::getLogFile();

        if (!is_file($logFile)) {
            header('HTTP/1.1 404 Not Found');
            echo 'Log file not found.';
            $this->getApplication()->close();
            return;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="sgcache_' . date('Y-m-d_His') . '.log"');
        header('Content-Length: ' . filesize($logFile));
        readfile($logFile);
        $this->getApplication()->close();
    }

    private function ajaxRenderViewer(): void
    {
        $token = Session::getFormToken();
        $ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=sgcache&group=system&format=raw&' . $token . '=1';
        $logFilePath = Logger::getLogFile();

        ob_start();
        include \dirname(__DIR__, 2) . '/tmpl/viewer.php';
        $html = ob_get_clean();

        echo $html;
        $this->getApplication()->close();
    }

    private function ajaxTestLogging(Event $event): void
    {
        $result = Logger::test();
        $this->setAjaxResult($event, json_encode($result));
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function queueContentUrls(string $context, $article): void
    {
        $this->addToQueue(Uri::root(true) . '/');

        if ($context === 'com_content.article' && isset($article->id)) {
            $url = Route::link('site', 'index.php?option=com_content&view=article&id=' . (int) $article->id);
            if ($url) {
                $this->addToQueue($url);
            }

            if (!empty($article->catid)) {
                $catUrl = Route::link('site', 'index.php?option=com_content&view=category&id=' . (int) $article->catid);
                if ($catUrl) {
                    $this->addToQueue($catUrl);
                }
            }
        }
    }

    private function addToQueue(string $url): void
    {
        Logger::debug('queue_add', ['url' => $url]);
        $this->purgeQueue[] = $url;
    }

    private function getSiteHostname(): string
    {
        $host = Uri::getInstance()->getHost() ?: parse_url(Uri::root(), PHP_URL_HOST) ?: '';
        return preg_replace('/^www\./', '', $host);
    }

    private function isUrlExcluded(string $path): bool
    {
        $excluded = $this->params->get('excluded_urls', '');
        if (empty($excluded)) {
            return false;
        }

        $lines = array_filter(array_map('trim', explode("\n", $excluded)));

        foreach ($lines as $pattern) {
            if (str_starts_with($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isComponentExcluded(string $option): bool
    {
        if (empty($option)) {
            return false;
        }

        $excluded = $this->params->get('excluded_components', '');
        if (empty($excluded)) {
            return false;
        }

        $lines = array_filter(array_map('trim', explode("\n", $excluded)));

        return \in_array($option, $lines, true);
    }

    /**
     * Parse the toolbar purge paths from plugin config.
     * Format: one per line, "label|path" e.g. "Blog Posts|/blog"
     */
    private function getToolbarPurgePaths(): array
    {
        $raw = $this->params->get('toolbar_purge_paths', '');
        if (empty($raw)) {
            return [];
        }

        $paths = [];
        $lines = array_filter(array_map('trim', explode("\n", $raw)));

        foreach ($lines as $line) {
            if (str_contains($line, '|')) {
                [$label, $path] = explode('|', $line, 2);
                $paths[] = ['label' => trim($label), 'path' => trim($path)];
            } else {
                $paths[] = ['label' => $line, 'path' => $line];
            }
        }

        return $paths;
    }

    private function setAjaxResult(Event $event, string $result): void
    {
        if (method_exists($event, 'addResult')) {
            $event->addResult($result);
        } else {
            $results = $event->getArgument('result', []);
            $results[] = $result;
            $event->setArgument('result', $results);
        }
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
