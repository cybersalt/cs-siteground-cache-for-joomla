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

class LogviewerbuttonField extends FormField
{
    protected $type = 'Logviewerbutton';

    protected function getInput(): string
    {
        $token = Session::getFormToken();
        $baseUrl = Uri::base() . 'index.php?option=com_ajax&plugin=sgcache&group=system&format=raw&' . $token . '=1';

        $viewerUrl = $baseUrl . '&action=viewer';
        $downloadUrl = $baseUrl . '&action=download';

        $viewLabel = Text::_('PLG_SYSTEM_SGCACHE_LOG_VIEW');
        $downloadLabel = Text::_('PLG_SYSTEM_SGCACHE_LOG_DOWNLOAD');
        $clearLabel = Text::_('PLG_SYSTEM_SGCACHE_LOG_CLEAR');
        $testLabel = Text::_('PLG_SYSTEM_SGCACHE_LOG_TEST');
        $clearConfirm = Text::_('PLG_SYSTEM_SGCACHE_LOG_CLEAR_CONFIRM');

        $clearUrl = $baseUrl . '&action=clear';
        $testUrl = $baseUrl . '&action=test';

        return <<<HTML
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="{$viewerUrl}" target="_blank" class="btn btn-primary">
                <span class="icon-search" aria-hidden="true"></span> {$this->esc($viewLabel)}
            </a>
            <a href="{$downloadUrl}" class="btn btn-success">
                <span class="icon-download" aria-hidden="true"></span> {$this->esc($downloadLabel)}
            </a>
            <button type="button" class="btn btn-info" onclick="sgcacheTestLog('{$testUrl}')">
                <span class="icon-wrench" aria-hidden="true"></span> {$this->esc($testLabel)}
            </button>
            <button type="button" class="btn btn-danger" onclick="sgcacheClearLog('{$clearUrl}', '{$this->esc($clearConfirm)}')">
                <span class="icon-trash" aria-hidden="true"></span> {$this->esc($clearLabel)}
            </button>
        </div>
        <div id="sgcache-log-result" style="margin-top:8px;font-size:13px;"></div>
        <script>
        function sgcacheClearLog(url, confirmMsg) {
            if (!confirm(confirmMsg)) return;
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var el = document.getElementById('sgcache-log-result');
                el.textContent = data.success ? 'Log cleared.' : ('Error: ' + (data.error || 'Unknown'));
                el.style.color = data.success ? '#28a745' : '#dc3545';
            }).catch(function(e) {
                document.getElementById('sgcache-log-result').textContent = 'Error: ' + e.message;
            });
        }
        function sgcacheTestLog(url) {
            var el = document.getElementById('sgcache-log-result');
            el.textContent = 'Testing...';
            el.style.color = '#999';
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                if (data.success) {
                    el.textContent = 'Logging test PASSED. Write test: ' + data.bytes_written + ' bytes. SiteGround: ' + (data.siteground ? 'Detected' : 'Not detected');
                    el.style.color = '#28a745';
                } else {
                    el.textContent = 'Logging test FAILED. Errors: ' + (data.errors || []).join(', ');
                    el.style.color = '#dc3545';
                }
            }).catch(function(e) {
                el.textContent = 'Test error: ' + e.message;
                el.style.color = '#dc3545';
            });
        }
        </script>
        HTML;
    }

    protected function getLabel(): string
    {
        return '';
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
