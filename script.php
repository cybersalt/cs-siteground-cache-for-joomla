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
        return true;
    }

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

        $this->renderInstallCard($type);

        return true;
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
