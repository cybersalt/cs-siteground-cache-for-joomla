<?php

/**
 * @package     Cybersalt.Plugin.System.SgCache
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

class PlgSystemSgcacheInstallerScript
{
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        // Before uninstall: disable the plugin first to prevent events
        // firing on a half-removed plugin (avoids "Class not found" errors)
        if ($type === 'uninstall') {
            $this->disablePlugin();
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): void
    {
        if ($type === 'install' || $type === 'update') {
            $this->showPostInstallMessage($type);
        }
    }

    private function disablePlugin(): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 0')
                ->where($db->quoteName('element') . ' = ' . $db->quote('sgcache'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));

            $db->setQuery($query);
            $db->execute();
        } catch (\Throwable $e) {
            // Best effort — don't block uninstall if this fails
        }
    }

    private function showPostInstallMessage(string $type): void
    {
        $messageKey = $type === 'update'
            ? 'PLG_SYSTEM_SGCACHE_POSTINSTALL_UPDATED'
            : 'PLG_SYSTEM_SGCACHE_POSTINSTALL_INSTALLED';

        $url = 'index.php?option=com_plugins&view=plugins&filter[search]=SiteGround%20Cache';

        $notSiteground = '';
        if (!file_exists('/chroot/tmp/site-tools.sock')) {
            $notSiteground = '<p class="alert alert-warning">'
                . Text::_('PLG_SYSTEM_SGCACHE_POSTINSTALL_NOT_SITEGROUND')
                . '</p>';
        }

        echo '<div class="card mb-3" style="margin: 20px 0;">'
            . '<div class="card-body">'
            . '<h3 class="card-title">' . Text::_('PLG_SYSTEM_SGCACHE') . '</h3>'
            . '<p class="card-text">' . Text::_($messageKey) . '</p>'
            . $notSiteground
            . '<a href="' . $url . '" class="btn btn-primary text-white">'
            . Text::_('PLG_SYSTEM_SGCACHE_POSTINSTALL_OPEN')
            . '</a></div></div>';
    }
}
