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

class PurgebuttonField extends FormField
{
    protected $type = 'Purgebutton';

    protected function getInput(): string
    {
        $token = Session::getFormToken();
        $ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=sgcache&group=system&format=raw&action=purge&' . $token . '=1';

        return <<<HTML
        <button type="button" class="btn btn-danger" id="sgcache-purge-btn" onclick="sgcachePurge()">
            {$this->escape(Text::_('PLG_SYSTEM_SGCACHE_PURGE_ALL_CACHE'))}
        </button>
        <span id="sgcache-purge-result" style="margin-left: 10px;"></span>
        <script>
        function sgcachePurge() {
            var btn = document.getElementById('sgcache-purge-btn');
            var result = document.getElementById('sgcache-purge-result');
            btn.disabled = true;
            btn.textContent = '...';
            result.textContent = '';

            fetch('{$ajaxUrl}')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    result.textContent = data.message || 'Done';
                    result.style.color = data.success ? 'green' : 'red';
                })
                .catch(function(e) {
                    result.textContent = 'Error: ' + e.message;
                    result.style.color = 'red';
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.textContent = '{$this->escape(Text::_('PLG_SYSTEM_SGCACHE_PURGE_ALL_CACHE'))}';
                });
        }
        </script>
        HTML;
    }

    protected function getLabel(): string
    {
        return '';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
