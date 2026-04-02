<?php

/**
 * @package     Cybersalt.Plugin.System.SgCache
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Cybersalt\Plugin\System\SgCache\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;

class ChangelogviewerField extends FormField
{
    protected $type = 'Changelogviewer';

    protected function getInput(): string
    {
        // Read the CHANGELOG.html from the plugin directory
        $changelogFile = JPATH_PLUGINS . '/system/sgcache/CHANGELOG.html';

        if (!is_file($changelogFile)) {
            return '<div class="alert alert-info">Changelog file not found.</div>';
        }

        $html = file_get_contents($changelogFile);

        if (empty($html)) {
            return '<div class="alert alert-info">Changelog is empty.</div>';
        }

        return <<<HTML
        <style>
            .sgcache-changelog {
                max-height: 600px;
                overflow-y: auto;
                padding: 16px 20px;
                border: 1px solid var(--template-bg-dark-7, #dee2e6);
                border-radius: 8px;
                font-size: 14px;
                line-height: 1.7;
            }
            .sgcache-changelog h1 {
                font-size: 20px;
                margin-bottom: 16px;
                padding-bottom: 8px;
                border-bottom: 2px solid var(--template-bg-dark-7, #dee2e6);
            }
            .sgcache-changelog h2 {
                font-size: 17px;
                margin-top: 20px;
                margin-bottom: 8px;
            }
            .sgcache-changelog h3 {
                font-size: 14px;
                margin-top: 12px;
                margin-bottom: 6px;
            }
            .sgcache-changelog ul {
                margin: 0 0 12px 0;
                padding-left: 24px;
            }
            .sgcache-changelog li {
                margin-bottom: 4px;
            }
            .sgcache-changelog code {
                padding: 1px 5px;
                border-radius: 3px;
                font-size: 12px;
            }
            .sgcache-changelog .version-badge {
                font-size: 13px;
                font-weight: normal;
                opacity: 0.7;
            }
        </style>
        <div class="sgcache-changelog">
            {$html}
        </div>
        HTML;
    }

    protected function getLabel(): string
    {
        return '';
    }
}
