<?php

/**
 * @package     Cybersalt.Plugin.System.SgCache
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Language\Text;

class PlgSystemSgcacheInstallerScript
{
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): void
    {
        if ($type === 'install' || $type === 'update') {
            $this->showPostInstallMessage($type);
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
