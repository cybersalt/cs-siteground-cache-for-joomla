<?php

/**
 * @package     Cybersalt.Plugin.System.SgCache
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

return new class () implements InstallerScriptInterface {
    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        // The watchdog routine lives in this plugin; its tasks would be
        // left as orphans in Scheduled Tasks.
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->delete($db->quoteName('#__scheduler_tasks'))
                ->where($db->quoteName('type') . ' = ' . $db->quote(self::WATCHDOG_ROUTINE));
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            // Best effort — the admin can delete the task by hand.
        }

        // Our logs: visitor URLs and cache activity have no use once the
        // plugin is gone.
        $logDir = $this->logDir();
        $stem   = basename($this->logFileName());
        foreach (array_merge(
            [$logDir . '/' . $this->phpName($stem), $logDir . '/sgcache_decisions.php'],
            glob($logDir . '/' . substr($this->phpName($stem), 0, -4) . '-[0-9][0-9][0-9][0-9]-*.php') ?: []
        ) as $file) {
            @unlink($file);
        }

        return true;
    }

    private const WATCHDOG_ROUTINE = 'sgcache.watchdog';

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        // Before uninstall: disable the plugin first to prevent events
        // firing on a half-removed plugin (avoids "Class not found" errors)
        if ($type === 'uninstall') {
            $this->disablePlugin();
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if (!\in_array($type, ['install', 'update', 'discover_install'], true)) {
            return true;
        }

        if ($type !== 'update') {
            $this->enablePlugin();
        }

        $this->purgeSiteGroundCache();
        $this->seedWatchdogTask();
        $this->moveLegacyLogs();

        $this->renderInstallCard($type);

        return true;
    }

    /**
     * Start clean. A page cached under an older version (2.2.2 could store a
     * feed as the home page) would otherwise be served until it expires. The
     * old version's own install-event purge never runs: the installer
     * redirects before it would be sent.
     */
    private function purgeSiteGroundCache(): void
    {
        if (!file_exists('/chroot/tmp/site-tools.sock')) {
            return;
        }

        try {
            $client = 'Cybersalt\\Plugin\\System\\SgCache\\SiteToolsClient';

            // On a first install the plugin's namespace isn't registered yet.
            if (!class_exists($client)) {
                $src = JPATH_PLUGINS . '/system/sgcache/src';
                if (!class_exists('Cybersalt\\Plugin\\System\\SgCache\\Logger')) {
                    require_once $src . '/Logger.php';
                }
                require_once $src . '/SiteToolsClient.php';
            }

            $host = preg_replace('/^www\./', '', $this->siteHost());
            if ($host !== '') {
                $client::flushDynamicCache($host, '/(.*)');
            }
        } catch (\Throwable $e) {
            // Non-fatal — the admin can purge with the toolbar button.
        }
    }

    /**
     * Add the home page watchdog to Scheduled Tasks, unpublished, so it's
     * there to switch on. Never adds a second one, and never touches one
     * the admin already has.
     */
    private function seedWatchdogTask(): void
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select('COUNT(*)')
                ->from($db->quoteName('#__scheduler_tasks'))
                ->where($db->quoteName('type') . ' = ' . $db->quote(self::WATCHDOG_ROUTINE));

            if ((int) $db->setQuery($query)->loadResult() > 0) {
                $this->fillWatchdogSiteUrl($db);
                return;
            }

            $now = Factory::getDate()->toSql();
            $row = (object) [
                'title'           => 'SiteGround Cache: home page watchdog',
                'type'            => self::WATCHDOG_ROUTINE,
                'execution_rules' => json_encode([
                    'rule-type'        => 'interval-minutes',
                    'interval-minutes' => '15',
                    'exec-day'         => '01',
                    'exec-time'        => '00:00',
                ]),
                'cron_rules'      => json_encode(['type' => 'interval', 'exp' => 'PT15M']),
                'state'           => 0,
                'last_exit_code'  => -1,
                'times_executed'  => 0,
                'times_failed'    => 0,
                'priority'        => 0,
                'ordering'        => 0,
                'note'            => 'Added by the SiteGround Cache installer. Publish it to have the home page checked every 15 minutes.',
                'params'          => json_encode([
                    'individual_log' => false,
                    'log_file'       => '',
                    'notifications'  => ['success_mail' => '0', 'failure_mail' => '1', 'fatal_failure_mail' => '1', 'orphan_mail' => '1'],
                    'site_url'       => \PHP_SAPI === 'cli' ? '' : Uri::root(),
                    'notify_email'   => '',
                    'extra_paths'    => '',
                ]),
                'created'         => $now,
                'created_by'      => (int) (Factory::getApplication()->getIdentity()?->id ?? 0),
            ];

            $db->insertObject('#__scheduler_tasks', $row, 'id');
        } catch (\Throwable $e) {
            // Non-fatal — the task can be created by hand in Scheduled Tasks.
        }
    }

    /**
     * Give existing watchdog tasks the site address they need when run
     * from the command line, if they don't have one yet.
     */
    private function fillWatchdogSiteUrl(DatabaseInterface $db): void
    {
        if (\PHP_SAPI === 'cli') {
            return;
        }

        $query = $db->createQuery()
            ->select($db->quoteName(['id', 'params']))
            ->from($db->quoteName('#__scheduler_tasks'))
            ->where($db->quoteName('type') . ' = ' . $db->quote(self::WATCHDOG_ROUTINE));

        foreach ($db->setQuery($query)->loadObjectList() as $task) {
            $params = json_decode((string) $task->params, true) ?: [];
            if (!empty($params['site_url'])) {
                continue;
            }
            $params['site_url'] = Uri::root();
            $json   = json_encode($params);
            $update = $db->createQuery()
                ->update($db->quoteName('#__scheduler_tasks'))
                ->set($db->quoteName('params') . ' = ' . $db->quote($json))
                ->where($db->quoteName('id') . ' = ' . (int) $task->id);
            $db->setQuery($update)->execute();
        }
    }

    /**
     * Before 2.3.0 the log was a plain file in /logs under the site root,
     * which a browser could download (verbose mode records visitor URLs).
     * Move its entries into the protected .php log in Joomla's log folder
     * and delete the old file and its rotated copies. Self-contained: the
     * previous version's Logger class may already be loaded.
     */
    private function moveLegacyLogs(): void
    {
        try {
            $name   = basename($this->logFileName());
            $oldDir = JPATH_ROOT . '/logs';
            $old    = $oldDir . '/' . $name;
            $new    = $this->logDir() . '/' . $this->phpName($name);
            $header = "#<?php die('Forbidden.'); ?>\n";

            if (is_file($old)) {
                $entries = (string) @file_get_contents($old);
                if (trim($entries) !== '') {
                    if (!is_dir(\dirname($new))) {
                        @mkdir(\dirname($new), 0755, true);
                    }
                    $existing = is_file($new) ? (string) @file_get_contents($new) : '';
                    $existing = str_starts_with($existing, $header) ? substr($existing, \strlen($header)) : $existing;
                    @file_put_contents($new, $header . $entries . $existing, LOCK_EX);
                }
                @unlink($old);
            }

            foreach (glob($old . '.*') ?: [] as $rotated) {
                @unlink($rotated);
            }

            // Remove the folder if this plugin was all that used it.
            if (is_dir($oldDir) && \count(array_diff(scandir($oldDir) ?: [], ['.', '..', 'index.html'])) === 0) {
                @unlink($oldDir . '/index.html');
                @rmdir($oldDir);
            }
        } catch (\Throwable $e) {
            // Non-fatal.
        }
    }

    /**
     * The site's host name. Installed from cli/joomla.php, Joomla can't
     * build the request URI, so fall back to the address the watchdog task
     * stored, then to live_site.
     */
    private function siteHost(): string
    {
        if (\PHP_SAPI !== 'cli') {
            try {
                return (string) parse_url(Uri::root(), PHP_URL_HOST);
            } catch (\Throwable $e) {
            }
        }

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__scheduler_tasks'))
                ->where($db->quoteName('type') . ' = ' . $db->quote(self::WATCHDOG_ROUTINE));
            foreach ($db->setQuery($query)->loadColumn() as $params) {
                $url = (string) (json_decode((string) $params, true)['site_url'] ?? '');
                if ($url !== '' && ($host = (string) parse_url($url, PHP_URL_HOST)) !== '') {
                    return $host;
                }
            }
        } catch (\Throwable $e) {
        }

        return (string) parse_url((string) Factory::getApplication()->get('live_site'), PHP_URL_HOST);
    }

    /** Same rule as Logger::pathFor(): the log is <name>.php. */
    private function phpName(string $name): string
    {
        return str_ends_with(strtolower($name), '.php') ? $name : $name . '.php';
    }

    private function logDir(): string
    {
        return rtrim((string) Factory::getApplication()->get('log_path', JPATH_ADMINISTRATOR . '/logs'), '/\\');
    }

    /** The Log Filename setting (default sgcache.log). */
    private function logFileName(): string
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('sgcache'));
            $params = json_decode((string) $db->setQuery($query)->loadResult(), true);
            $name   = basename((string) ($params['log_file'] ?? ''));

            return $name !== '' ? $name : 'sgcache.log';
        } catch (\Throwable $e) {
            return 'sgcache.log';
        }
    }

    private function enablePlugin(): void
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('sgcache'));
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            // Non-fatal — user can enable manually from the plugin manager.
        }
    }

    private function disablePlugin(): void
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 0')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('sgcache'));
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            // Best effort — don't block uninstall if this fails
        }
    }

    private function renderInstallCard(string $type): void
    {
        // Per Joomla-Brain JOOMLA-EXTENSION-WISHLIST.md §"Post-Install Card With Next Steps":
        // logo + title in header, description paragraph, action buttons in Cybersalt orange,
        // footer with vendor + support link, light/dark CSS variables on the header surface.

        $e = static fn (string $key): string => htmlspecialchars(
            Text::_($key),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        // Description and the NOT_SITEGROUND warning contain HTML (<strong>, <a>, <small>).
        // Pass those through Text::_ without escaping so the markup renders.
        $rawHtml = static fn (string $key): string => Text::_($key);

        $title = $type === 'update'
            ? $e('PLG_SYSTEM_SGCACHE_POSTINSTALL_UPDATED')
            : $e('PLG_SYSTEM_SGCACHE_POSTINSTALL_INSTALLED');

        $description = $rawHtml('PLG_SYSTEM_SGCACHE_DESCRIPTION');
        $openLabel   = $e('PLG_SYSTEM_SGCACHE_POSTINSTALL_OPEN');

        $pluginUrl  = 'index.php?option=com_plugins&filter[folder]=system&filter[element]=sgcache';
        $vendorUrl  = 'https://www.cybersalt.com';
        $supportUrl = 'https://github.com/cybersalt/cs-siteground-cache-for-joomla/issues';
        $logoUrl    = Uri::root() . 'media/plg_system_sgcache/images/logo.svg';

        // SiteGround-detection warning only renders when the daemon socket isn't present.
        $notSiteGroundWarning = '';
        if (!file_exists('/chroot/tmp/site-tools.sock')) {
            $notSiteGroundWarning = '<div class="alert alert-warning mt-3 mb-0">'
                . $rawHtml('PLG_SYSTEM_SGCACHE_POSTINSTALL_NOT_SITEGROUND')
                . '</div>';
        }

        echo <<<HTML
<style>
/* Light mode defaults */
.cs-install-card {
    --cs-header-bg: #fff;
    --cs-header-title: #0102E1;        /* Cybersalt cobalt */
    --cs-header-border: rgba(0, 0, 0, 0.1);
}
/* Dark mode — Joomla 5/6 uses BOTH selectors depending on template/version */
html[data-bs-theme="dark"] .cs-install-card,
html[data-color-scheme="dark"] .cs-install-card {
    --cs-header-bg: #1f2937;           /* slate-800 */
    --cs-header-title: #FE9904;        /* Cybersalt brand orange */
    --cs-header-border: rgba(255, 255, 255, 0.1);
}

.cs-install-card .cs-card-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    background-color: var(--cs-header-bg);
    padding: 0.9rem 1.25rem;
    border-bottom: 1px solid var(--cs-header-border);
}
.cs-install-card .cs-card-header img {
    height: 56px;
    width: 56px;
    flex: 0 0 56px;
}
.cs-install-card .cs-card-header h3 {
    margin: 0;
    font-size: 1.4rem;
    color: var(--cs-header-title);
}

/* Action buttons in Cybersalt orange — white text reads in either mode */
.cs-install-card a.cs-cybersalt-btn,
.cs-install-card a.cs-cybersalt-btn:link,
.cs-install-card a.cs-cybersalt-btn:visited {
    background-color: #dc6b1a;
    border-color: #dc6b1a;
    color: #fff !important;
}
.cs-install-card a.cs-cybersalt-btn:hover,
.cs-install-card a.cs-cybersalt-btn:focus,
.cs-install-card a.cs-cybersalt-btn:active {
    background-color: #b85614;
    border-color: #b85614;
    color: #fff !important;
}
</style>
<div class="card my-3 cs-install-card">
    <div class="card-header cs-card-header">
        <img src="{$logoUrl}" alt="" />
        <h3>{$title}</h3>
    </div>
    <div class="card-body">
        <p class="lead mb-3">{$description}</p>
        {$notSiteGroundWarning}
        <hr>
        <p class="mb-0">
            <a class="btn btn-sm cs-cybersalt-btn" href="{$pluginUrl}">{$openLabel}</a>
            &middot;
            <a href="{$vendorUrl}" target="_blank" rel="noopener noreferrer">Cybersalt</a>
            &middot;
            <a href="{$supportUrl}" target="_blank" rel="noopener noreferrer">Report a bug</a>
        </p>
    </div>
</div>
HTML;
    }
};
