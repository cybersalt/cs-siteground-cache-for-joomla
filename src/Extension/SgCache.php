<?php

/**
 * @package     Cybersalt.Plugin.System.SgCache
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Cybersalt\Plugin\System\SgCache\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Event;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;
use Cybersalt\Plugin\System\SgCache\Decisions;
use Cybersalt\Plugin\System\SgCache\FormTokens;
use Cybersalt\Plugin\System\SgCache\Logger;
use Cybersalt\Plugin\System\SgCache\PageProbe;
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

    /**
     * URL of the current frontend page if it passed every cacheability check
     * in onAfterRender; null otherwise.
     */
    private ?string $cacheCandidateUrl = null;

    /**
     * Whether X-Cache-Enabled: True was sent for this request.
     */
    private bool $cacheClaimed = false;

    /**
     * Query parameters that may never be cached even when an admin lists
     * them: they change what Joomla outputs, not just which page it shows.
     */
    private const NEVER_CACHE_PARAMS = ['format', 'tmpl', 'task'];

    /** Scheduled-task routine: the home page watchdog. */
    private const WATCHDOG_ROUTINE = 'sgcache.watchdog';

    /**
     * Save/delete/state contexts that never change what visitors see. The
     * scheduler, for one, saves a log row through onContentAfterSave after
     * every task run, which used to purge the whole cache each time.
     */
    private const IGNORED_CONTEXTS = [
        'com_scheduler.', 'com_actionlogs.', 'com_privacy.', 'com_messages.',
        'com_mails.', 'com_checkin.', 'com_guidedtours.', 'com_installer.',
    ];

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

            // Our own settings saved — full purge
            'onExtensionAfterSave'      => 'onExtensionAfterSave',

            // Admin toolbar button + cache headers + template editor detection
            'onAfterRender'           => 'onAfterRender',

            // Strip Set-Cookie + decide X-Cache-Enabled, after everyone else
            'onBeforeRespond'         => ['onBeforeRespond', Priority::MIN],

            // Cookie self-check + flush the queue at shutdown
            'onAfterRespond'          => 'onAfterRespond',

            // AJAX handler
            'onAjaxSgcache'           => 'onAjaxSgcache',

            // Scheduled task: home page watchdog
            'onTaskOptionsList'       => 'onTaskOptionsList',
            'onContentPrepareForm'    => 'onContentPrepareForm',
            'onExecuteTask'           => 'onExecuteTask',
        ];
    }

    // ------------------------------------------------------------------
    // Initialization
    // ------------------------------------------------------------------

    public function onAfterInitialise(): void
    {
        $this->initLogger();

        // Load our language files on all admin pages so toolbar button,
        // purge messages, and non-SiteGround notice strings are available
        $app = $this->getApplication();
        if ($app->isClient('administrator')) {
            $this->loadLanguage();
        }
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

        // Skip logging our own log viewer AJAX requests (not purge actions)
        $app = $this->getApplication();
        if ($app->input->get('option') === 'com_ajax' && $app->input->get('plugin') === 'sgcache') {
            $action = $app->input->get('action', '');
            if ($action !== 'purge') {
                Logger::suppress();
                return;
            }
        }
    }

    // ------------------------------------------------------------------
    // Content events — queue related URLs for selective purge
    // ------------------------------------------------------------------

    public function onContentAfterSave(Event $event): void
    {
        if (!$this->params->get('enable_autoflush', 1) || !SiteToolsClient::isSiteGround()) {
            return;
        }

        [$context, $article] = array_values($event->getArguments());

        if ($this->isIgnoredContext((string) $context)) {
            return;
        }

        // Detect template file saves via com_templates
        if ($context === 'com_templates.style' || $context === 'com_templates.template') {
            Logger::info('template_save_detected', ['context' => $context]);
            $this->triggerFullFlush();
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
        if (!$this->params->get('enable_autoflush', 1) || !SiteToolsClient::isSiteGround()) {
            return;
        }

        [$context, $article] = array_values($event->getArguments());

        if ($this->isIgnoredContext((string) $context)) {
            return;
        }

        Logger::info('content_delete', [
            'context' => $context,
            'id'      => $article->id ?? null,
            'title'   => $article->title ?? null,
        ]);

        $this->queueContentUrls($context, $article);
    }

    public function onContentChangeState(Event $event): void
    {
        if (!$this->params->get('enable_autoflush', 1) || !SiteToolsClient::isSiteGround()) {
            return;
        }

        [$context, $pks] = array_values($event->getArguments());

        if ($this->isIgnoredContext((string) $context)) {
            return;
        }

        // A plugin published or unpublished from the Plugins list (this one
        // included) can change every page. While this plugin is off nothing
        // is cached, so purging when it's switched off also means it starts
        // clean when it's switched back on.
        if ($context === 'com_plugins.plugin') {
            Logger::info('full_flush_triggered', ['trigger' => 'plugin_state_changed', 'ids' => $pks]);
            $this->triggerFullFlush();
            return;
        }

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
        if (!$this->params->get('enable_autoflush', 1) || !SiteToolsClient::isSiteGround()) {
            return;
        }

        Logger::info('category_state_change');
        $this->addToQueue(Uri::root(true) . '/');
    }

    public function onCategoryAfterSave(Event $event): void
    {
        if (!$this->params->get('enable_autoflush', 1) || !SiteToolsClient::isSiteGround()) {
            return;
        }

        Logger::info('category_save');
        $this->addToQueue(Uri::root(true) . '/');
    }

    public function onCategoryAfterDelete(Event $event): void
    {
        if (!$this->params->get('enable_autoflush', 1) || !SiteToolsClient::isSiteGround()) {
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
        try {
            if (!$this->params->get('enable_autoflush', 1) || !SiteToolsClient::isSiteGround()) {
                return;
            }

            Logger::info('full_flush_triggered', ['trigger' => $event->getName()]);
            $this->triggerFullFlush();
        } catch (\Throwable $e) {
            // Silently fail — this can happen during self-uninstall when
            // plugin files are being removed while the event fires
        }
    }

    /**
     * Saving this plugin's settings purges everything, so pages cached
     * under the old settings (or by an older version) aren't served on.
     */
    public function onExtensionAfterSave(Event $event): void
    {
        if (!SiteToolsClient::isSiteGround()) {
            return;
        }

        [$context, $table] = array_values($event->getArguments());

        if ($context !== 'com_plugins.plugin'
            || ($table->folder ?? '') !== 'system'
            || ($table->element ?? '') !== 'sgcache') {
            return;
        }

        Logger::info('full_flush_triggered', ['trigger' => 'plugin_settings_saved']);
        $this->triggerFullFlush();
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
            if (!SiteToolsClient::isSiteGround()) {
                $this->injectNonSiteGroundNotice();
            }
            $this->injectAdminToolbarButton();
            $this->detectTemplateEditorSave();
            $this->injectDebugPanel();
            return;
        }

        if (!$app->isClient('site')) {
            return;
        }

        // -- Frontend: set cache headers --

        $currentUrl = Uri::getInstance()->toString(['path']);
        $verbose = (bool) $this->params->get('verbose_logging', 0);

        if (!SiteToolsClient::isSiteGround()) {
            if ($verbose) {
                Logger::debug('frontend_request', ['url' => $currentUrl, 'cache' => 'skipped', 'reason' => 'not_siteground']);
            }
            return;
        }

        if (!$this->params->get('enable_dynamic_cache', 1)) {
            if ($verbose) {
                Logger::debug('frontend_request', ['url' => $currentUrl, 'cache' => 'disabled']);
            }
            return;
        }

        $user = $app->getIdentity();
        if ($user && !$user->guest && !$this->params->get('cache_logged_in', 0)) {
            Logger::info('frontend_request', ['url' => $currentUrl, 'cache' => 'bypass', 'reason' => 'logged_in_user']);
            $app->setHeader('X-Cache-Enabled', 'False', true);
            $this->sendProbeDecision('bypass:logged_in_user');
            return;
        }

        if ($this->isUrlExcluded($currentUrl)) {
            $this->bypass($currentUrl, 'url_excluded');
            return;
        }

        $option = $app->input->get('option', '');
        if ($this->isComponentExcluded($option)) {
            $this->bypass($currentUrl, 'component_excluded', ['component' => $option]);
            return;
        }

        $menuItem = $this->excludedMenuItem();
        if ($menuItem !== null) {
            $this->bypass($currentUrl, 'menu_item_excluded', ['menu_item' => $menuItem]);
            return;
        }

        $agent = $this->excludedUserAgent();
        if ($agent !== null) {
            $this->bypass($currentUrl, 'user_agent_excluded', ['matched' => $agent]);
            return;
        }

        // SiteGround appears to drop the whole query string from its cache key
        // when a tracking parameter (fbclid, utm_*...) is present, so
        // /?fbclid=x&format=feed gets stored as the home page. A page whose only
        // parameters are tracking ones renders the same as the bare URL and is
        // safe to cache. Other parameters (pagination, filters) are cached only
        // if the admin lists them, and never alongside a tracking parameter.
        [$blocked, $mixed] = $this->queryStringProblem();
        if ($blocked !== []) {
            $this->bypass($currentUrl, 'query_string', ['params' => $blocked]);
            return;
        }
        if ($mixed) {
            $this->bypass($currentUrl, 'query_string_with_tracking');
            return;
        }

        // Cache candidate. The final X-Cache-Enabled value is decided in
        // onBeforeRespond, once we know whether a Set-Cookie survives. Until
        // then the page is marked not cacheable, so any path that skips
        // onBeforeRespond never claims a cacheability it can't deliver.
        if ($verbose) {
            Logger::info('frontend_request', ['url' => $currentUrl, 'cache' => 'candidate']);
        }
        $app->setHeader('X-Cache-Enabled', 'False', true);
        $this->cacheCandidateUrl = $currentUrl;
    }

    // ------------------------------------------------------------------
    // onBeforeRespond: strip Set-Cookie, then tell SiteGround the truth
    // ------------------------------------------------------------------

    /**
     * Runs last, immediately before Joomla sends the headers.
     *
     * SiteGround refuses to cache any response that carries Set-Cookie
     * (X-Proxy-Cache-Info: SKIP_CACHE_SET_COOKIE). Joomla starts a session,
     * and so Set-Cookies, for every guest. WordPress doesn't, which is why
     * SG's WP plugin never needed this. So on cacheable anonymous GETs we
     * strip Set-Cookie here, after every other onAfterRender / onAfterCompress
     * / onBeforeRespond handler has had its chance to add one, and only then
     * claim X-Cache-Enabled: True. If a cookie survives, the claim is False
     * and the reason is logged as a warning.
     *
     * Safe because: the caller is anonymous (logged-in check in onAfterRender)
     * and URL/component exclusions keep full sessions for form pages.
     *
     * Only a 200 HTML page is ever claimed. A feed, JSON, raw output or an
     * error page sent as cacheable can end up served in place of the real
     * page (seen with a crawler's /?fbclid=...&format=feed stored as "/").
     */
    public function onBeforeRespond(): void
    {
        if ($this->cacheCandidateUrl === null) {
            return;
        }

        $app        = $this->getApplication();
        $currentUrl = $this->cacheCandidateUrl;
        $verbose    = (bool) $this->params->get('verbose_logging', 0);
        $suppress   = (bool) $this->params->get('suppress_session_cookie', 1);
        $reason     = '';

        $notPage = $this->notCacheablePageReason();
        if ($notPage !== []) {
            $reason = $notPage['reason'];
            unset($notPage['reason']);
            $this->bypass($currentUrl, $reason, $notPage);
            return;
        }

        // A visitor who arrived with a session can have things of their own
        // on the page: a cart, a form refilled after an error, a message.
        // Only pages rendered for a visitor without one are stored. Cookie-
        // less first visits and crawlers fill the cache; everyone, this
        // visitor included, is still served from it. (Logged-in visitors only
        // get this far when Cache for Logged-in Users is on, by choice.)
        $user = $app->getIdentity();
        if ((!$user || $user->guest) && $this->visitorHasSession()) {
            $this->bypass($currentUrl, 'visitor_has_session');
            return;
        }

        // Read before any Set-Cookie is stripped: it starts the session if
        // nothing has yet.
        $token = Session::getFormToken();

        // With Joomla's Gzip Page Compression on, the body is compressed by
        // now (compress() runs between onAfterRender and onBeforeRespond).
        [$html, $encoding] = $this->readBody();
        if ($html === null) {
            $this->bypass($currentUrl, 'body_unreadable', ['encoding' => $encoding]);
            return;
        }

        // A message queued for this visitor ("Thank you for subscribing")
        // would be shown to everyone served the copy.
        if ($app->getMessageQueue() !== []) {
            $this->bypass($currentUrl, 'messages_queued');
            return;
        }

        // Some forms and modules print the visitor's IP address (RSForm and
        // Convert Forms smart tags, "your IP is" modules).
        if ($this->bodyHasVisitorIp($html)) {
            $this->bypass($currentUrl, 'visitor_ip_on_page');
            return;
        }

        // A page carrying a Joomla form token (Convert Forms, contact forms,
        // login modules...) needs the visitor's own session: the token is
        // checked against it on submit. Caching it as-is would serve one
        // visitor's token to everyone. Forms we recognise get the token
        // swapped in by script for each visitor (FormTokens); any other form
        // keeps the page uncached, with its cookie and no-cache headers.
        if ($app->input->getMethod() === 'GET') {
            $forms = $this->analyseForms($html, $token);
            if ($forms['found']) {
                if (!$this->params->get('cache_form_pages', 1)) {
                    $this->bypass($currentUrl, 'form_token_on_page', ['forms' => $forms['forms']]);
                    return;
                }
                if ($forms['blocked'] !== []) {
                    $this->bypass($currentUrl, 'form_token_on_page', ['forms' => $forms['blocked']]);
                    return;
                }
            }
        }

        if ($app->input->getMethod() !== 'GET') {
            $reason = 'not_get';
        } elseif ($suppress) {
            if (\headers_sent($file, $line)) {
                $reason = 'headers_already_sent';
                Logger::warning('set_cookie_not_stripped', [
                    'url'    => $currentUrl,
                    'reason' => $reason,
                    'at'     => $file . ':' . $line,
                ]);
            } else {
                \header_remove('Set-Cookie');
            }
        }

        $cookieLeft = $this->hasSetCookie();

        if ($cookieLeft && $reason === '') {
            if (!$suppress) {
                $reason = 'suppress_session_cookie_off';
            } else {
                // Stripped natively but still present: it's in Joomla's own
                // header list (set via $app->setHeader), which header_remove()
                // can't reach.
                $reason = 'set_cookie_in_app_headers';
                Logger::warning('set_cookie_not_stripped', ['url' => $currentUrl, 'reason' => $reason]);
            }
        }

        if ($cookieLeft) {
            $this->bypass($currentUrl, $reason);
            return;
        }

        // Nothing of this visitor's session may go out in a cached copy.
        [$scrubbed, $punched] = FormTokens::scrub($html, $token);
        if ($punched) {
            $scrubbed = str_ireplace('</body>', FormTokens::script($this->tokenEndpoint()) . '</body>', $scrubbed);
        }
        if ($scrubbed !== $html) {
            $this->writeBody($scrubbed, $encoding);
        }

        $app->setHeader('X-Cache-Enabled', 'True', true);
        $this->cacheClaimed = true;
        $this->recordDecision('cached', $punched ? 'forms_punched' : '');
        $this->sendProbeDecision($punched ? 'cached; forms=punched' : 'cached');

        // SiteGround's Dynamic Cache won't store a response that carries
        // Cache-Control: no-cache. Joomla sends no-store/no-cache on every
        // page unless it is marked cacheable, and PHP's session cache limiter
        // adds its own no-cache set at session_start(). Mark the page
        // cacheable so respond() skips its no-cache headers, and drop the
        // session limiter's. Purges on content save keep the edge fresh.
        $app->allowCache(true);
        if (!\headers_sent()) {
            \header_remove('Cache-Control');
            \header_remove('Pragma');
            \header_remove('Expires');
        }

        if ($this->params->get('vary_user_agent', 0)) {
            $app->setHeader('Vary', 'User-Agent', true);
        }

        if ($verbose) {
            Logger::info('frontend_request', ['url' => $currentUrl, 'cache' => 'enabled', 'forms_punched' => $punched]);
        }
    }

    /**
     * Mark the current page not cacheable, log why, and remember the reason
     * for the dashboard and the home page test.
     */
    private function bypass(string $url, string $reason, array $data = []): void
    {
        $this->getApplication()->setHeader('X-Cache-Enabled', 'False', true);
        Logger::info('frontend_request', ['url' => $url, 'cache' => 'bypass', 'reason' => $reason] + $data);

        $detail = '';
        foreach ($data as $value) {
            $detail .= ($detail === '' ? '' : '; ') . (\is_array($value) ? implode(', ', $value) : (string) $value);
        }

        $this->recordDecision($reason, $detail);
        $this->sendProbeDecision('bypass:' . $reason . ($detail !== '' ? '; ' . $detail : ''));
        $this->cacheCandidateUrl = null;
    }

    private function recordDecision(string $decision, string $detail = ''): void
    {
        // Pages for visitors with a session are skipped by design and would
        // drown out the cache fills the dashboard is meant to show.
        if ($decision === 'visitor_has_session') {
            return;
        }

        if ($this->getApplication()->input->getMethod() === 'GET' && !PageProbe::isProbe()) {
            Decisions::record($decision, $detail);
        }
    }

    /** Tell our own home page test why this page was (not) cached. */
    private function sendProbeDecision(string $decision): void
    {
        if (PageProbe::isProbe()) {
            $value = preg_replace('/[^\x20-\x7E]/', '?', $decision);
            $this->getApplication()->setHeader(PageProbe::DECISION_HEADER, mb_substr($value, 0, 500), true);
        }
    }

    /**
     * Token forms on the page, see FormTokens::analyse().
     */
    private function analyseForms(string $html, string $token): array
    {
        $types = $this->params->get('form_types', ['login', 'rsform', 'convertforms']);
        if (\is_string($types)) {
            $types = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $types)));
        }

        return FormTokens::analyse($html, $token, (array) $types, $this->lines('form_markers'));
    }

    /**
     * The response body as HTML, decompressing it if Joomla's Gzip Page
     * Compression already ran.
     *
     * @return array{0: ?string, 1: string}  HTML (null if it can't be
     *                                       decoded) and the Content-Encoding.
     */
    private function readBody(): array
    {
        $app      = $this->getApplication();
        $body     = (string) $app->getBody();
        $encoding = '';

        foreach ($app->getHeaders() as $header) {
            if (\strtolower($header['name']) === 'content-encoding') {
                $encoding = \strtolower(\trim($header['value']));
            }
        }

        $html = match ($encoding) {
            ''                => $body,
            'gzip', 'x-gzip'  => @gzdecode($body),
            'deflate'         => @gzuncompress($body),
            default           => false,
        };

        return [$html === false ? null : $html, $encoding];
    }

    /** Store new HTML, compressed the same way compress() did. */
    private function writeBody(string $html, string $encoding): void
    {
        $body = match ($encoding) {
            'gzip', 'x-gzip' => gzencode($html, 4, FORCE_GZIP),
            'deflate'        => gzencode($html, 4, FORCE_DEFLATE),
            default          => $html,
        };

        $this->getApplication()->setBody($body);
    }

    /** Whether the request came with a cookie for a Joomla session. */
    private function visitorHasSession(): bool
    {
        try {
            $name = $this->getApplication()->getSession()->getName();
        } catch (\Throwable $e) {
            return false;
        }

        return $name !== '' && $this->getApplication()->input->cookie->getString($name, '') !== '';
    }

    /** Whether the page shows the address the request came from. */
    private function bodyHasVisitorIp(string $html): bool
    {
        $server = $this->getApplication()->input->server;
        $ips    = [$server->getString('REMOTE_ADDR', '')];
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'] as $key) {
            $ips[] = trim(explode(',', $server->getString($key, ''))[0]);
        }

        foreach (array_unique(array_filter($ips)) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) && preg_match('/(?<![\d.:a-f])' . preg_quote($ip, '/') . '(?![\d.:a-f])/i', $html)) {
                return true;
            }
        }

        return false;
    }

    /** Frontend com_ajax URL that hands a visitor their own form token. */
    private function tokenEndpoint(): string
    {
        return Uri::root(true) . '/index.php?option=com_ajax&plugin=sgcache&group=system&format=raw&action=token';
    }

    /**
     * Whether a Set-Cookie is still queued, natively or in Joomla's header list.
     */
    private function hasSetCookie(): bool
    {
        foreach (\headers_list() as $header) {
            if (\stripos($header, 'set-cookie:') === 0) {
                return true;
            }
        }

        foreach ($this->getApplication()->getHeaders() as $header) {
            if (\strtolower($header['name']) === 'set-cookie') {
                return true;
            }
        }

        return false;
    }

    /**
     * Why the response isn't an ordinary 200 HTML page, as log fields
     * (reason + detail), or [] if it is one.
     */
    private function notCacheablePageReason(): array
    {
        $app = $this->getApplication();

        $document = $app->getDocument();
        $type     = $document ? $document->getType() : '';
        if ($type !== 'html') {
            return ['reason' => 'not_html', 'document' => $type];
        }

        // respond() sends Joomla's own Content-Type header if one was set,
        // otherwise the document's mime type.
        $contentType = $app->mimeType;
        $status      = $app->getResponse()->getStatusCode();
        foreach ($app->getHeaders() as $header) {
            $name = \strtolower($header['name']);
            if ($name === 'content-type') {
                $contentType = $header['value'];
            } elseif ($name === 'status') {
                $status = (int) $header['value'];
            }
        }

        if (!str_starts_with(\strtolower(\trim($contentType)), 'text/html')) {
            return ['reason' => 'not_html', 'content_type' => $contentType];
        }

        if ($status !== 200) {
            return ['reason' => 'not_200', 'status' => $status];
        }

        return [];
    }

    /**
     * What, if anything, about the query string stops the page being cached.
     *
     * @return array{0: string[], 1: bool}  Parameters that may not be cached,
     *         and whether allowed parameters are mixed with tracking ones.
     */
    private function queryStringProblem(): array
    {
        $tracking = [
            'fbclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'dclid', 'yclid',
            'igshid', 'ttclid', 'twclid', 'mc_cid', 'mc_eid', '_ga', '_gl',
        ];
        $allowed = array_map('strtolower', $this->lines('cacheable_query_params'));

        $blocked     = [];
        $other       = 0;
        $hasTracking = false;
        foreach (array_keys(Uri::getInstance()->getQuery(true)) as $name) {
            $name  = (string) $name;
            $lower = \strtolower($name);

            if (str_starts_with($lower, 'utm_') || \in_array($lower, $tracking, true)) {
                $hasTracking = true;
            } elseif (!\in_array($lower, $allowed, true) || \in_array($lower, self::NEVER_CACHE_PARAMS, true)) {
                $blocked[] = $name;
            } else {
                $other++;
            }
        }

        // With a tracking parameter present SiteGround would key the page on
        // its bare path, so /blog?start=10&utm_source=x would be stored as /blog.
        return [$blocked, $other > 0 && $hasTracking];
    }

    /** The active menu item's id, if it's one the admin excluded. */
    private function excludedMenuItem(): ?int
    {
        $ids = array_map('intval', (array) $this->params->get('excluded_menu_items', []));
        if ($ids === []) {
            return null;
        }

        $active = $this->getApplication()->getMenu()->getActive();

        return $active && \in_array((int) $active->id, $ids, true) ? (int) $active->id : null;
    }

    /** The excluded user-agent string the visitor's browser matches, if any. */
    private function excludedUserAgent(): ?string
    {
        $agent = (string) $this->getApplication()->input->server->getString('HTTP_USER_AGENT', '');
        if ($agent === '') {
            return null;
        }

        foreach ($this->lines('excluded_user_agents') as $needle) {
            if (\stripos($agent, $needle) !== false) {
                return $needle;
            }
        }

        return null;
    }

    /** A textarea setting as trimmed, non-empty lines. */
    private function lines(string $param): array
    {
        $raw = (string) $this->params->get($param, '');

        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw))));
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

        $assets = $this->buildToolbarAssets($ajaxUrl);

        // Inject button into Atum's header-items container (right side of top bar)
        if (str_contains($body, 'header-items')) {
            $body = preg_replace(
                '/(<div[^>]*class="[^"]*header-items[^"]*"[^>]*>)/i',
                '$1' . $buttonHtml,
                $body,
                1
            );
        } else {
            // Fallback: prepend to the header element
            $body = preg_replace(
                '/(<header[^>]*>)/i',
                '$1' . $buttonHtml,
                $body,
                1
            );
        }

        // Add CSS/JS before </body>
        $body = str_replace('</body>', $assets . '</body>', $body);
        $app->setBody($body);
    }

    private function buildSimpleToolbarButton(string $ajaxUrl, string $label): string
    {
        return <<<HTML
        <div class="header-item" id="sgcache-toolbar-wrapper">
            <a href="javascript:" id="sgcache-toolbar-btn" class="header-item-content" onclick="sgcacheToolbarPurge('all')" title="{$this->esc(Text::_('PLG_SYSTEM_SGCACHE_TOOLBAR_PURGE_TITLE'))}">
                <div class="header-item-icon">
                    <span class="icon-trash" aria-hidden="true"></span>
                </div>
                <div class="header-item-text sgcache-label">
                    {$this->esc($label)}
                </div>
            </a>
        </div>
        HTML;
    }

    private function buildDropdownToolbarButton(string $ajaxUrl, string $label): string
    {
        $purgeAllLabel = Text::_('PLG_SYSTEM_SGCACHE_TOOLBAR_PURGE_ALL');

        // Custom path values flow into a JS function call inside an onclick attribute.
        // Use a data-* attribute + delegated event listener instead of interpolating the
        // path into the JS string — defends against admin-supplied strings containing
        // quote characters that would otherwise break out of the JS string context.
        $pathItems = '';
        $customPaths = $this->getToolbarPurgePaths();
        foreach ($customPaths as $path) {
            $pathEsc = $this->esc($path['label']);
            $pathVal = $this->esc($path['path']);
            $pathItems .= '<li><a class="dropdown-item sgcache-purge-path" href="#" data-sgcache-path="' . $pathVal . '">' . $pathEsc . '</a></li>';
        }

        return <<<HTML
        <div class="header-item" id="sgcache-toolbar-wrapper">
            <div class="btn-group">
                <a href="javascript:" id="sgcache-toolbar-btn" class="header-item-content" onclick="sgcacheToolbarPurge('all')" title="{$this->esc(Text::_('PLG_SYSTEM_SGCACHE_TOOLBAR_PURGE_TITLE'))}">
                    <div class="header-item-icon">
                        <span class="icon-trash" aria-hidden="true"></span>
                    </div>
                    <div class="header-item-text sgcache-label">
                        {$this->esc($label)}
                    </div>
                </a>
                <button type="button" class="header-item-content dropdown-toggle dropdown-toggle-split sgcache-split-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="icon-chevron-down" aria-hidden="true"></span>
                    <span class="visually-hidden">Toggle Dropdown</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="#" onclick="sgcacheToolbarPurge('all'); return false;">{$this->esc($purgeAllLabel)}</a></li>
                    <li><hr class="dropdown-divider"></li>
                    {$pathItems}
                </ul>
            </div>
        </div>
        HTML;
    }

    private function buildToolbarAssets(string $ajaxUrl): string
    {
        // All values passed into JS string context use json_encode() — safe across
        // any character including quotes, newlines, unicode tricks. Defends against
        // a malicious live_site config or unusual HTTP_HOST that could otherwise
        // break out of a single-quoted JS literal. Matches the v2.1.0 hardening
        // pattern in tmpl/viewer.php.
        $ajaxUrlJs   = json_encode($ajaxUrl,                                                       JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $successJs   = json_encode(Text::_('PLG_SYSTEM_SGCACHE_PURGE_SUCCESS'),                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $failJs      = json_encode(Text::_('PLG_SYSTEM_SGCACHE_PURGE_FAILED'),                     JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $purgingJs   = json_encode(Text::_('PLG_SYSTEM_SGCACHE_TOOLBAR_PURGING'),                  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<HTML
        <style>
        /* J6's Atum shows no caret on a split toggle; draw our own and hide
           Bootstrap's so J5 doesn't get two. */
        .sgcache-split-toggle::after { display: none; }
        .sgcache-split-toggle .icon-chevron-down { font-size: .75em; }
        </style>
        <script>
        var sgcacheAjaxUrl = {$ajaxUrlJs};
        function sgcacheToolbarPurge(pathOrAll) {
            var btn = document.getElementById('sgcache-toolbar-btn');
            var label = btn.querySelector('.sgcache-label');
            var origText = label ? label.textContent.trim() : '';
            btn.style.opacity = '0.6';
            btn.style.pointerEvents = 'none';
            if (label) label.textContent = {$purgingJs};
            var url = sgcacheAjaxUrl + '&action=purge';
            if (pathOrAll && pathOrAll !== 'all') { url += '&purge_path=' + encodeURIComponent(pathOrAll); }
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                btn.style.opacity = '';
                btn.style.pointerEvents = '';
                if (data.success) {
                    if (label) label.textContent = {$successJs};
                } else {
                    if (label) label.textContent = data.message || {$failJs};
                }
                setTimeout(function() { if (label) label.textContent = origText; }, 3000);
            }).catch(function(e) {
                btn.style.opacity = '';
                btn.style.pointerEvents = '';
                if (label) label.textContent = {$failJs};
                setTimeout(function() { if (label) label.textContent = origText; }, 3000);
            });
        }
        // Bind data-attribute-driven path picks via delegated event listener so
        // path values never get interpolated into onclick attributes (which the
        // browser HTML-decodes before JS sees them, defeating htmlspecialchars).
        document.addEventListener('click', function (ev) {
            var el = ev.target && ev.target.closest && ev.target.closest('.sgcache-purge-path');
            if (!el) return;
            ev.preventDefault();
            sgcacheToolbarPurge(el.getAttribute('data-sgcache-path') || 'all');
        });
        </script>
        HTML;
    }

    // ------------------------------------------------------------------
    // Non-SiteGround admin notice
    // ------------------------------------------------------------------

    private function injectNonSiteGroundNotice(): void
    {
        $app = $this->getApplication();
        $user = $app->getIdentity();

        if (!$user || !$user->authorise('core.manage')) {
            return;
        }

        $body = $app->getBody();

        if (str_contains($body, 'sgcache-sg-notice')) {
            return;
        }

        $message = Text::_('PLG_SYSTEM_SGCACHE_NOT_SITEGROUND_NOTICE');
        $settingsUrl = 'index.php?option=com_plugins&view=plugins&filter[search]=SiteGround%20Cache';
        $settingsLabel = Text::_('PLG_SYSTEM_SGCACHE_GO_TO_SETTINGS');

        $notice = <<<HTML
        <div id="sgcache-sg-notice" class="alert alert-warning d-flex align-items-center gap-2" style="margin:10px 20px 0;border-radius:6px;">
            <span class="icon-warning-circle" aria-hidden="true" style="font-size:18px;"></span>
            <div style="flex:1;">
                <strong>SiteGround Cache:</strong> {$this->esc($message)}
            </div>
            <a href="{$settingsUrl}" class="btn btn-sm btn-warning">{$this->esc($settingsLabel)}</a>
        </div>
        HTML;

        // Inject after the <header> closing tag so it appears below the header bar
        if (str_contains($body, '</header>')) {
            $body = preg_replace('/<\/header>/', '</header>' . $notice, $body, 1);
        }

        $app->setBody($body);
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
                $this->triggerFullFlush();
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
        // Self-check: we told SiteGround "cache this". If a Set-Cookie still
        // went out with it, SG skipped the page and nothing else would say so.
        if ($this->cacheClaimed) {
            foreach (\headers_list() as $header) {
                if (\stripos($header, 'set-cookie:') === 0) {
                    Logger::warning('cache_claimed_with_cookie', [
                        'url'    => $this->cacheCandidateUrl,
                        'cookie' => \substr($header, 12, \strcspn($header, '=', 12)),
                    ]);
                    break;
                }
            }
        }

        $this->processQueue();
    }

    /**
     * Process the purge queue. Called from onAfterRespond, and also
     * registered as a shutdown function as a fallback for admin redirects
     * where onAfterRespond may not fire.
     */
    private function processQueue(): void
    {
        // Only process once per request
        static $processed = false;
        if ($processed) {
            return;
        }
        $processed = true;

        if (!SiteToolsClient::isSiteGround()) {
            return;
        }

        if (!$this->fullFlushTriggered && empty($this->purgeQueue)) {
            return;
        }

        $hostname = $this->getSiteHostname();

        if ($this->fullFlushTriggered) {
            Logger::info('queue_process', ['action' => 'full_flush', 'reason' => 'full_flush_triggered']);
            SiteToolsClient::flushDynamicCache($hostname, '/(.*)');
            return;
        }

        $threshold = (int) $this->params->get('purge_threshold', 10);
        $urls = array_unique($this->purgeQueue);

        Logger::info('queue_process', [
            'url_count' => \count($urls),
            'threshold' => $threshold,
            'urls'      => $urls,
        ]);

        // Every content save queues the home page, and the home page pattern
        // '/(.*)' covers the whole site. That's deliberate: modules such as
        // Latest News list articles on every page, so a site-wide flush is
        // what keeps them fresh. Send it once rather than once per URL.
        if (\in_array(Uri::root(true) . '/', $urls, true)) {
            Logger::info('queue_process', ['action' => 'full_flush', 'reason' => 'home_page_queued']);
            SiteToolsClient::flushDynamicCache($hostname, '/(.*)');
        } elseif (\count($urls) >= $threshold) {
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

        // Frontend: hand a visitor on a cached form page their own token.
        if ($app->isClient('site') && $app->input->get('action', '') === 'token') {
            $this->ajaxToken($event);
            return;
        }

        if (!$app->isClient('administrator')) {
            $this->setAjaxResult($event, json_encode(['error' => Text::_('PLG_SYSTEM_SGCACHE_ACCESS_DENIED')]));
            return;
        }

        $user = $app->getIdentity();
        if (!$user || !$user->authorise('core.manage')) {
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
            case 'selftest':
                $this->ajaxSelfTest($event);
                break;
            case 'decisions':
                $this->setAjaxResult($event, json_encode(['success' => true] + $this->decisionSummary()));
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
            // Validate path format — only allow URL-safe characters
            $purgePath = '/' . ltrim($purgePath, '/');
            if (!preg_match('#^/[a-zA-Z0-9/_\-\.]*$#', $purgePath)) {
                $this->setAjaxResult($event, json_encode([
                    'success' => false,
                    'message' => 'Invalid path format.',
                ]));
                return;
            }
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

        $contents = Logger::contents($logFile);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="sgcache_' . date('Y-m-d_His') . '.log"');
        header('Content-Length: ' . \strlen($contents));
        echo $contents;
        $this->getApplication()->close();
    }

    private function ajaxRenderViewer(): void
    {
        $token = Session::getFormToken();
        $ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=sgcache&group=system&format=raw&' . $token . '=1';
        $logFilePath = Logger::getLogFile();

        // Defense-in-depth: prevent clickjacking on the standalone viewer page.
        // The viewer page has a "Clear Log" button; pinning frame-ancestors to none
        // means even a stolen-token attacker can't load it inside an iframe to fool
        // an admin into clicking-through. Belt-and-suspenders behind the token check.
        if (!\headers_sent()) {
            \header('X-Frame-Options: DENY');
            \header("Content-Security-Policy: frame-ancestors 'none'");
            \header('X-Content-Type-Options: nosniff');
            \header('Referrer-Policy: same-origin');
        }

        ob_start();
        include \dirname(__DIR__, 2) . '/tmpl/viewer.php';
        $html = ob_get_clean();

        echo $html;
        $this->getApplication()->close();
    }

    private function ajaxTestLogging(Event $event): void
    {
        $result = Logger::test();
        $result['daemon'] = SiteToolsClient::canConnect();
        $this->setAjaxResult($event, json_encode($result));
    }

    /**
     * The visitor's own form token, for FormTokens' script on cached pages.
     *
     * POST only, so no proxy or browser cache keeps it. Reading the token
     * starts the visitor's session, and this response (never cacheable: it
     * isn't HTML and has a query string) carries the session cookie, so the
     * token matches when the form is submitted. Other sites can't read the
     * response: no CORS headers are sent.
     */
    private function ajaxToken(Event $event): void
    {
        $app = $this->getApplication();

        if ($app->input->getMethod() !== 'POST') {
            $app->setHeader('Allow', 'POST', true);
            $app->setHeader('status', '405', true);
            $this->setAjaxResult($event, json_encode(['error' => 'POST only']));
            return;
        }

        $app->mimeType = 'application/json';
        $app->getDocument()?->setMimeEncoding('application/json');
        $app->setHeader('Cache-Control', 'no-store, private', true);
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);

        $this->setAjaxResult($event, json_encode(['token' => Session::getFormToken()]));
    }

    /**
     * "Test my home page": fetch the home page and the newest article as an
     * anonymous visitor, twice each, and say whether SiteGround caches them
     * and, if not, why.
     */
    private function ajaxSelfTest(Event $event): void
    {
        if (!SiteToolsClient::isSiteGround()) {
            $this->setAjaxResult($event, json_encode([
                'success' => false,
                'message' => Text::_('PLG_SYSTEM_SGCACHE_NOT_SITEGROUND'),
            ]));
            return;
        }

        $pages = ['home' => Uri::root()];
        $article = $this->newestArticleUrl();
        if ($article !== null) {
            $pages['article'] = $article;
        }

        $rows = [];
        foreach ($pages as $kind => $url) {
            $first = PageProbe::fetch($url);
            if ($first['ok'] && ($first['headers']['x-proxy-cache'] ?? '') !== 'HIT') {
                \usleep(1500000);
            }
            $second = $first['ok'] ? PageProbe::fetch($url) : $first;

            $rows[] = ['kind' => $kind, 'url' => $url] + $this->judgeProbe($kind, $first, $second);
        }

        Logger::info('self_test', ['results' => array_map(fn ($r) => $r['kind'] . ':' . $r['status'], $rows)]);

        $this->setAjaxResult($event, json_encode(['success' => true, 'rows' => $rows] + $this->decisionSummary()));
    }

    /**
     * Verdict for one probed page.
     *
     * @return array{status: string, message: string, first: array, second: array}
     *         status: ok | warn | fail
     */
    private function judgeProbe(string $kind, array $first, array $second): array
    {
        $brief = static fn (array $r): array => [
            'status'       => $r['status'],
            'content_type' => $r['headers']['content-type'] ?? '',
            'proxy_cache'  => $r['headers']['x-proxy-cache'] ?? '',
            'proxy_info'   => $r['headers']['x-proxy-cache-info'] ?? '',
            'cache_enabled'=> $r['headers']['x-cache-enabled'] ?? '',
            'decision'     => $r['headers'][\strtolower(PageProbe::DECISION_HEADER)] ?? '',
            'cookie'       => isset($r['headers']['set-cookie']),
        ];
        $out = ['first' => $first['ok'] ? $brief($first) : [], 'second' => $second['ok'] ? $brief($second) : []];

        if (!$first['ok']) {
            return ['status' => 'fail', 'message' => Text::sprintf('PLG_SYSTEM_SGCACHE_SELFTEST_FETCH_FAILED', $first['error'])] + $out;
        }

        if ($second['status'] !== 200) {
            return ['status' => 'fail', 'message' => Text::sprintf('PLG_SYSTEM_SGCACHE_SELFTEST_HTTP', $second['status'])] + $out;
        }

        if (!$second['html']) {
            $key = $kind === 'home' ? 'PLG_SYSTEM_SGCACHE_SELFTEST_NOT_HTML_HOME' : 'PLG_SYSTEM_SGCACHE_SELFTEST_NOT_HTML';
            return ['status' => 'fail', 'message' => Text::sprintf($key, $out['second']['content_type'] ?: '?')] + $out;
        }

        if ($out['second']['proxy_cache'] === 'HIT') {
            return ['status' => 'ok', 'message' => Text::_('PLG_SYSTEM_SGCACHE_SELFTEST_CACHED')] + $out;
        }

        if ($out['first']['proxy_cache'] === '' && $out['second']['proxy_cache'] === '') {
            return ['status' => 'warn', 'message' => Text::_('PLG_SYSTEM_SGCACHE_SELFTEST_NO_PROXY')] + $out;
        }

        $decision = $out['first']['decision'] ?: $out['second']['decision'];
        if (str_starts_with($decision, 'bypass:')) {
            [$reason, $detail] = array_pad(explode('; ', substr($decision, 7), 2), 2, '');
            return ['status' => 'warn', 'message' => $this->reasonText($reason, $detail)] + $out;
        }

        $info = $out['second']['proxy_info'] ?: $out['first']['proxy_info'];
        if (str_contains($info, 'SET_COOKIE')) {
            return ['status' => 'warn', 'message' => Text::_('PLG_SYSTEM_SGCACHE_SELFTEST_COOKIE')] + $out;
        }

        return ['status' => 'warn', 'message' => Text::sprintf('PLG_SYSTEM_SGCACHE_SELFTEST_NOT_STORED', $info ?: '-')] + $out;
    }

    /** Plain-language explanation of a bypass reason code. */
    private function reasonText(string $reason, string $detail = ''): string
    {
        $key = 'PLG_SYSTEM_SGCACHE_REASON_' . \strtoupper(preg_replace('/[^a-z0-9_]/i', '', $reason));
        $lang = $this->getApplication()->getLanguage();
        $text = $lang->hasKey($key) ? Text::_($key) : Text::sprintf('PLG_SYSTEM_SGCACHE_REASON_OTHER', $reason);

        return $detail !== '' ? $text . ' (' . $detail . ')' : $text;
    }

    /**
     * Recent decisions, plus a warning when the uncached traffic is mostly
     * bypassed for one fixable reason.
     */
    private function decisionSummary(): array
    {
        $s = Decisions::summary(50);
        $s['warning'] = '';

        if ($s['total'] >= 10 && $s['problems'] * 2 >= $s['total'] && $s['problems'] > $s['cached']) {
            $s['warning'] = Text::sprintf(
                'PLG_SYSTEM_SGCACHE_DECISIONS_WARNING',
                $s['problems'],
                $s['total'],
                $this->reasonText($s['top'], $s['detail'])
            );
        }

        return ['decisions' => $s];
    }

    /** Absolute URL of the newest article a guest can see, if any. */
    private function newestArticleUrl(): ?string
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $now   = Factory::getDate()->toSql();
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'catid', 'language']))
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('state') . ' = 1')
                ->where($db->quoteName('access') . ' = 1')
                ->where('(' . $db->quoteName('publish_up') . ' IS NULL OR ' . $db->quoteName('publish_up') . ' <= :now1)')
                ->where('(' . $db->quoteName('publish_down') . ' IS NULL OR ' . $db->quoteName('publish_down') . ' > :now2)')
                ->bind(':now1', $now)
                ->bind(':now2', $now)
                ->order($db->quoteName('publish_up') . ' DESC');
            $row = $db->setQuery($query, 0, 1)->loadObject();

            if (!$row) {
                return null;
            }

            $link = 'index.php?option=com_content&view=article&id=' . (int) $row->id . '&catid=' . (int) $row->catid;
            if ($row->language && $row->language !== '*') {
                $link .= '&lang=' . $row->language;
            }

            return Route::link('site', $link, false, Route::TLS_IGNORE, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Scheduled task: home page watchdog
    // ------------------------------------------------------------------

    public function onTaskOptionsList(Event $event): void
    {
        $this->loadLanguage();
        $event->getArgument('subject')->addOptions([self::WATCHDOG_ROUTINE => 'PLG_SYSTEM_SGCACHE_TASK_WATCHDOG']);
    }

    public function onContentPrepareForm(Event $event): void
    {
        [$form, $data] = array_values($event->getArguments());

        if (!$form instanceof \Joomla\CMS\Form\Form || $form->getName() !== 'com_scheduler.task') {
            return;
        }

        $routine = \is_object($data) ? ($data->taskOption->id ?? $data->type ?? '') : ($data['type'] ?? '');
        if ($routine === '') {
            $routine = (string) ($this->getApplication()->input->get('jform', [], 'array')['type'] ?? $form->getValue('type') ?? '');
        }

        if ($routine === self::WATCHDOG_ROUTINE) {
            $this->loadLanguage();
            $form->loadFile(JPATH_PLUGINS . '/system/sgcache/forms/watchdog.xml');
            // The admin knows the site's real address; the command-line
            // scheduler doesn't.
            $form->setFieldAttribute('site_url', 'default', Uri::root(), 'params');
        }
    }

    /**
     * Fetch the home page (and any extra paths) as a visitor. If one comes
     * back as something other than an HTML page, which is what a poisoned
     * cache entry looks like, purge everything, check again, and email.
     */
    public function onExecuteTask(Event $event): void
    {
        if ($event->getRoutineId() !== self::WATCHDOG_ROUTINE) {
            return;
        }

        $start = microtime(true);
        $this->initLogger();
        $this->loadLanguage();

        $status = $this->runWatchdog((object) ($event->getArgument('params') ?? new \stdClass()));

        $event->setResult([
            'status'    => $status,
            'plugin'    => $this->_name,
            'startTime' => $start,
            'endTime'   => microtime(true),
            'duration'  => microtime(true) - $start,
        ]);
    }

    /** @return int  A com_scheduler Status code (0 = OK, 5 = knockout). */
    private function runWatchdog(object $params): int
    {
        $ok = 0;
        $failed = 5;

        if (!SiteToolsClient::isSiteGround()) {
            Logger::info('watchdog', ['result' => 'skipped', 'reason' => 'not_siteground']);
            return $ok;
        }

        // Run from cli/joomla.php, Joomla can't tell the site's address
        // (Uri::root() comes out as http://host/cli/joomla.php or empty), so
        // use the one saved with the task.
        $root = rtrim((string) ($params->site_url ?? ''), '/');
        if ($root === '' && \PHP_SAPI !== 'cli') {
            $root = rtrim(Uri::root(), '/');
        }
        if (!preg_match('#^https?://[^/?\#]+(/[^?\#]*)?$#i', $root)) {
            Logger::error('watchdog', ['result' => 'no_site_url', 'note' => 'Open the task in Scheduled Tasks and save it, so it stores the site address.']);
            return $failed;
        }

        $paths = array_merge(['/'], preg_split('/\r\n|\r|\n/', (string) ($params->extra_paths ?? '')));
        $bad   = [];

        foreach (array_unique(array_filter(array_map('trim', $paths))) as $path) {
            if (!str_starts_with($path, '/') || str_contains($path, '//')) {
                continue;
            }
            $r = PageProbe::fetch($root . $path, false);
            if (!$r['ok'] || $r['status'] >= 500 || ($r['status'] === 200 && !$r['html'])) {
                $bad[$path] = $r;
            }
        }

        if ($bad === []) {
            Logger::info('watchdog', ['result' => 'ok']);
            return $ok;
        }

        $report = [];
        foreach ($bad as $path => $r) {
            $report[] = $path . ': ' . ($r['ok']
                ? 'HTTP ' . $r['status'] . ', ' . ($r['headers']['content-type'] ?? 'no content type') . ', ' . $r['bytes'] . ' bytes, X-Proxy-Cache ' . ($r['headers']['x-proxy-cache'] ?? '-')
                : 'request failed: ' . $r['error']);
        }

        Logger::warning('watchdog_bad_page', ['pages' => $report]);
        $host    = preg_replace('/^www\./', '', (string) parse_url($root, PHP_URL_HOST));
        $flushed = SiteToolsClient::flushDynamicCache($host, '/(.*)');

        // Did the purge fix it?
        $still = [];
        foreach (array_keys($bad) as $path) {
            $r = PageProbe::fetch($root . $path, false);
            if (!$r['ok'] || $r['status'] >= 500 || ($r['status'] === 200 && !$r['html'])) {
                $still[] = $path;
            }
        }

        Logger::warning('watchdog_after_purge', ['flushed' => $flushed, 'still_bad' => $still]);
        $this->mailWatchdogReport((string) ($params->notify_email ?? ''), $root . '/', $report, $flushed, $still);

        return $still === [] ? $ok : $failed;
    }

    private function mailWatchdogReport(string $to, string $siteUrl, array $report, bool $flushed, array $still): void
    {
        try {
            $app  = $this->getApplication();
            $to   = $to !== '' ? $to : (string) $app->get('mailfrom');
            $site = (string) $app->get('sitename');

            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $body = Text::sprintf('PLG_SYSTEM_SGCACHE_WATCHDOG_MAIL_INTRO', $site, $siteUrl) . "\n\n"
                . implode("\n", $report) . "\n\n"
                . ($flushed ? Text::_('PLG_SYSTEM_SGCACHE_WATCHDOG_MAIL_PURGED') : Text::_('PLG_SYSTEM_SGCACHE_WATCHDOG_MAIL_PURGE_FAILED')) . "\n"
                . ($still === [] ? Text::_('PLG_SYSTEM_SGCACHE_WATCHDOG_MAIL_FIXED') : Text::sprintf('PLG_SYSTEM_SGCACHE_WATCHDOG_MAIL_STILL', implode(', ', $still)));

            $mailer = Factory::getMailer();
            $mailer->addRecipient($to);
            $mailer->setSubject(Text::sprintf('PLG_SYSTEM_SGCACHE_WATCHDOG_MAIL_SUBJECT', $site));
            $mailer->setBody($body);
            $mailer->Send();
        } catch (\Throwable $e) {
            Logger::error('watchdog_mail_failed', ['error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function isIgnoredContext(string $context): bool
    {
        foreach (self::IGNORED_CONTEXTS as $prefix) {
            if (str_starts_with($context, $prefix)) {
                return true;
            }
        }

        return false;
    }

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

    /**
     * Shutdown function fallback — ensures the queue is flushed even if
     * onAfterRespond doesn't fire (common on admin save+redirect).
     */
    public function shutdownFlush(): void
    {
        $this->processQueue();
    }

    private function addToQueue(string $url): void
    {
        Logger::debug('queue_add', ['url' => $url]);
        $this->purgeQueue[] = $url;
        $this->registerShutdownFlush();
    }

    private function triggerFullFlush(): void
    {
        $this->fullFlushTriggered = true;
        $this->registerShutdownFlush();
    }

    /**
     * Fallback in case onAfterRespond doesn't fire: admin saves, installs
     * and list actions end in a redirect, which skips it.
     */
    private function registerShutdownFlush(): void
    {
        static $shutdownRegistered = false;
        if (!$shutdownRegistered) {
            register_shutdown_function([$this, 'shutdownFlush']);
            $shutdownRegistered = true;
        }
    }

    private function getSiteHostname(): string
    {
        try {
            $host = Uri::getInstance()->getHost() ?: parse_url(Uri::root(), PHP_URL_HOST) ?: '';
        } catch (\Throwable $e) {
            // On the command line Joomla can't build the request URI.
            $host = (string) parse_url((string) $this->getApplication()->get('live_site'), PHP_URL_HOST);
        }

        return preg_replace('/^www\./', '', $host);
    }

    /**
     * Excluded URLs, one per line:
     *   /members          path prefix (also /members/x, /members-area)
     *   /shop/STAR/cart   STAR (an asterisk) matches anything, including slashes
     *   /about$           a trailing $ means the path must end there
     *   /search?q=        a ? means match against the path plus query string
     */
    private function isUrlExcluded(string $path): bool
    {
        $query = (string) Uri::getInstance()->getQuery();

        foreach ($this->lines('excluded_urls') as $pattern) {
            $subject = str_contains($pattern, '?') && $query !== '' ? $path . '?' . $query : $path;
            $exact   = str_ends_with($pattern, '$');
            $pattern = $exact ? substr($pattern, 0, -1) : $pattern;

            if (!str_contains($pattern, '*') && !$exact) {
                if (str_starts_with($subject, $pattern)) {
                    return true;
                }
                continue;
            }

            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . ($exact ? '$' : '') . '#';
            if (preg_match($regex, $subject)) {
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

        $excluded = $this->params->get('excluded_components', []);

        // Handle both array (fancy-select) and string formats. A fresh install stores the
        // manifest default as one comma-separated string; the legacy textarea used newlines.
        if (\is_string($excluded)) {
            $excluded = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $excluded)));
        }

        if (empty($excluded)) {
            return false;
        }

        return \in_array($option, (array) $excluded, true);
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
