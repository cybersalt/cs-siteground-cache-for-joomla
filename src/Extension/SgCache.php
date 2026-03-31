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
     *
     * @var bool
     */
    private bool $fullFlushTriggered = false;

    public static function getSubscribedEvents(): array
    {
        return [
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
            $this->fullFlushTriggered = true;
            return;
        }

        $this->queueContentUrls($context, $article);
    }

    public function onContentAfterDelete(Event $event): void
    {
        if (!$this->params->get('enable_autoflush', 1)) {
            return;
        }

        [$context, $article] = array_values($event->getArguments());

        $this->queueContentUrls($context, $article);
    }

    public function onContentChangeState(Event $event): void
    {
        if (!$this->params->get('enable_autoflush', 1)) {
            return;
        }

        [$context, $pks] = array_values($event->getArguments());

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

        $this->addToQueue(Uri::root(true) . '/');
    }

    public function onCategoryAfterSave(Event $event): void
    {
        if (!$this->params->get('enable_autoflush', 1)) {
            return;
        }

        $this->addToQueue(Uri::root(true) . '/');
    }

    public function onCategoryAfterDelete(Event $event): void
    {
        if (!$this->params->get('enable_autoflush', 1)) {
            return;
        }

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

        $this->fullFlushTriggered = true;
    }

    // ------------------------------------------------------------------
    // onAfterRender: cache headers (frontend) + toolbar button (admin)
    // + template editor save detection (admin)
    // ------------------------------------------------------------------

    public function onAfterRender(): void
    {
        $app = $this->getApplication();

        if ($app->isClient('administrator')) {
            $this->injectAdminToolbarButton();
            $this->detectTemplateEditorSave();
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
            $app->setHeader('X-Cache-Enabled', 'False', true);
            return;
        }

        $currentPath = Uri::getInstance()->getPath();
        if ($this->isUrlExcluded($currentPath)) {
            $app->setHeader('X-Cache-Enabled', 'False', true);
            return;
        }

        $option = $app->input->get('option', '');
        if ($this->isComponentExcluded($option)) {
            $app->setHeader('X-Cache-Enabled', 'False', true);
            return;
        }

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

        // Only show to users who can manage options (Super Users / admins)
        if (!$user || !$user->authorise('core.manage')) {
            return;
        }

        $body = $app->getBody();

        // Don't inject if already present (safety check)
        if (str_contains($body, 'sgcache-toolbar-btn')) {
            return;
        }

        $token = Session::getFormToken();
        $ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=sgcache&group=system&format=raw&' . $token . '=1';

        // Build the purge mode options for the dropdown
        $buttonMode = $this->params->get('toolbar_button_mode', 'all');
        $buttonLabel = Text::_('PLG_SYSTEM_SGCACHE_TOOLBAR_PURGE');

        if ($buttonMode === 'all') {
            // Simple button — purge everything
            $buttonHtml = $this->buildSimpleToolbarButton($ajaxUrl, $buttonLabel);
        } else {
            // Dropdown button — purge all or specific paths
            $buttonHtml = $this->buildDropdownToolbarButton($ajaxUrl, $buttonLabel);
        }

        // Build the CSS + JS + button HTML to inject
        $injection = $this->buildToolbarAssets($ajaxUrl) . $buttonHtml;

        // Inject just before </body>
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
        $purgePathsLabel = Text::_('PLG_SYSTEM_SGCACHE_TOOLBAR_PURGE_PATHS');

        // Build the custom path items from config
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
            .sgcache-btn:hover {
                background: rgba(0,0,0,0.4);
            }
            .sgcache-btn.sgcache-success {
                background: rgba(40,167,69,0.6);
            }
            .sgcache-btn.sgcache-error {
                background: rgba(220,53,69,0.6);
            }
            .sgcache-btn.sgcache-busy {
                opacity: 0.7;
                cursor: wait;
            }
            .sgcache-btn-group .sgcache-btn:first-child {
                border-radius: 4px 0 0 4px;
            }
            .sgcache-btn-group .sgcache-dropdown-toggle {
                border-radius: 0 4px 4px 0;
                border-left: 1px solid rgba(255,255,255,0.2);
                padding: 5px 8px;
            }
            .sgcache-icon {
                font-size: 14px;
            }
            .sgcache-caret {
                font-size: 10px;
            }
            .sgcache-dropdown-menu {
                display: none;
                position: absolute;
                top: 100%;
                right: 0;
                margin-top: 4px;
                min-width: 200px;
                background: #2d3436;
                border: 1px solid rgba(255,255,255,0.15);
                border-radius: 6px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                overflow: hidden;
            }
            .sgcache-dropdown-menu.sgcache-open {
                display: block;
            }
            .sgcache-dropdown-item {
                display: block;
                padding: 8px 14px;
                color: #dfe6e9;
                text-decoration: none;
                font-size: 13px;
                transition: background 0.15s;
            }
            .sgcache-dropdown-item:hover {
                background: rgba(255,255,255,0.1);
                color: #fff;
                text-decoration: none;
            }
            .sgcache-dropdown-divider {
                height: 1px;
                background: rgba(255,255,255,0.1);
                margin: 2px 0;
            }
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
            if (pathOrAll && pathOrAll !== 'all') {
                url += '&purge_path=' + encodeURIComponent(pathOrAll);
            }

            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.classList.remove('sgcache-busy');
                    if (data.success) {
                        btn.classList.add('sgcache-success');
                        label.textContent = '{$successMsg}';
                    } else {
                        btn.classList.add('sgcache-error');
                        label.textContent = data.message || '{$failMsg}';
                    }
                    setTimeout(function() {
                        btn.classList.remove('sgcache-success', 'sgcache-error');
                        label.textContent = origText;
                    }, 3000);
                })
                .catch(function(e) {
                    btn.classList.remove('sgcache-busy');
                    btn.classList.add('sgcache-error');
                    label.textContent = '{$failMsg}';
                    setTimeout(function() {
                        btn.classList.remove('sgcache-error');
                        label.textContent = origText;
                    }, 3000);
                });
        }

        function sgcacheToggleDropdown() {
            var menu = document.getElementById('sgcache-dropdown-menu');
            if (menu) {
                menu.classList.toggle('sgcache-open');
            }
        }

        function sgcacheCloseDropdown() {
            var menu = document.getElementById('sgcache-dropdown-menu');
            if (menu) {
                menu.classList.remove('sgcache-open');
            }
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.sgcache-btn-group')) {
                sgcacheCloseDropdown();
            }
        });
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

        // com_templates file editor uses task=template.save or template.apply
        if ($option === 'com_templates' && str_contains($task, 'save') || str_contains($task, 'apply')) {
            // Check if this was a POST (actual save, not just viewing)
            if ($app->input->getMethod() === 'POST') {
                $this->fullFlushTriggered = true;
            }
        }
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
            SiteToolsClient::flushDynamicCache($hostname, '/(.*)');
            return;
        }

        if (empty($this->purgeQueue)) {
            return;
        }

        $threshold = (int) $this->params->get('purge_threshold', 10);
        $urls = array_unique($this->purgeQueue);

        if (\count($urls) >= $threshold) {
            SiteToolsClient::flushDynamicCache($hostname, '/(.*)');
        } else {
            foreach ($urls as $url) {
                $path = parse_url($url, PHP_URL_PATH) ?: '/';
                SiteToolsClient::flushDynamicCache($hostname, $path . '(.*)');
            }
        }
    }

    // ------------------------------------------------------------------
    // AJAX handler for toolbar button + settings purge button
    // ------------------------------------------------------------------

    public function onAjaxSgcache(Event $event): void
    {
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

        if ($action !== 'purge') {
            $this->setAjaxResult($event, json_encode(['error' => Text::_('PLG_SYSTEM_SGCACHE_INVALID_ACTION')]));
            return;
        }

        if (!SiteToolsClient::isSiteGround()) {
            $this->setAjaxResult($event, json_encode([
                'success' => false,
                'message' => Text::_('PLG_SYSTEM_SGCACHE_NOT_SITEGROUND'),
            ]));
            return;
        }

        $hostname = $this->getSiteHostname();
        $purgePath = $app->input->getString('purge_path', '');

        if (!empty($purgePath)) {
            // Purge a specific path
            $purgePath = '/' . ltrim($purgePath, '/');
            $result = SiteToolsClient::flushDynamicCache($hostname, $purgePath . '(.*)');
            $message = $result
                ? Text::sprintf('PLG_SYSTEM_SGCACHE_PURGE_PATH_SUCCESS', $purgePath)
                : Text::_('PLG_SYSTEM_SGCACHE_PURGE_FAILED');
        } else {
            // Purge everything
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
     *
     * @return array<array{label: string, path: string}>
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
