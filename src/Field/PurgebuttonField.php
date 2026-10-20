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
        $token   = Session::getFormToken();
        $ajaxUrl = Uri::base() . 'index.php?option=com_ajax&plugin=sgcache&group=system&format=raw&action=purge&' . $token . '=1';
        $label   = Text::_('PLG_SYSTEM_SGCACHE_PURGE_ALL_CACHE');

        // All values flowing into JS string context use json_encode() to defend against
        // any character that could break out of a single-quoted JS literal — even though
        // Uri::base() and language strings are author-controlled, this matches the v2.1.0
        // hardening pattern in tmpl/viewer.php.
        $ajaxUrlJs = json_encode($ajaxUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $labelJs   = json_encode($label,   JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $labelHtml = $this->escape($label);

        return <<<HTML
        <button type="button" class="btn btn-danger" id="sgcache-purge-btn" onclick="sgcachePurge()">
            {$labelHtml}
        </button>
        <span id="sgcache-purge-result" style="margin-left: 10px;"></span>
        <script>
        function sgcachePurge() {
            var btn = document.getElementById('sgcache-purge-btn');
            var result = document.getElementById('sgcache-purge-result');
            btn.disabled = true;
            btn.textContent = '...';
            result.textContent = '';

            fetch({$ajaxUrlJs})
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
                    btn.textContent = {$labelJs};
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
