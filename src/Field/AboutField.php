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
use Joomla\CMS\Uri\Uri;

class AboutField extends FormField
{
    protected $type = 'About';

    protected function getInput(): string
    {
        // Cache-bust the logo on plugin updates so browsers fetch the new SVG
        $logoVer = '2.2.2';
        $logoUrl = Uri::root(true) . '/media/plg_system_sgcache/images/logo.svg?v=' . $logoVer;

        $version    = '2.2.2';
        $aboutH     = Text::_('PLG_SYSTEM_SGCACHE_ABOUT_HEADING');
        $aboutBody  = Text::_('PLG_SYSTEM_SGCACHE_ABOUT_BODY');
        $linksH     = Text::_('PLG_SYSTEM_SGCACHE_ABOUT_LINKS_HEADING');
        $linkExts   = Text::_('PLG_SYSTEM_SGCACHE_ABOUT_LINK_EXTENSIONS');
        $linkGH     = Text::_('PLG_SYSTEM_SGCACHE_ABOUT_LINK_GITHUB');
        $linkBug    = Text::_('PLG_SYSTEM_SGCACHE_ABOUT_LINK_BUG_REPORT');
        $supportH   = Text::_('PLG_SYSTEM_SGCACHE_ABOUT_SUPPORT_HEADING');
        $supportTxt = Text::_('PLG_SYSTEM_SGCACHE_ABOUT_SUPPORT_BODY');
        $attribH    = Text::_('PLG_SYSTEM_SGCACHE_ABOUT_ATTRIBUTION_HEADING');
        $attribTxt  = Text::_('PLG_SYSTEM_SGCACHE_ABOUT_ATTRIBUTION_BODY');
        $licenseTxt = Text::_('PLG_SYSTEM_SGCACHE_ABOUT_LICENSE');

        return <<<HTML
        <style>
            /* Scoped theme variables (light defaults, switch on any dark-mode trigger) */
            .sgabout-wrap {
                --sg-text: #212529;
                --sg-text-muted: #6c757d;
                --sg-bg: #ffffff;
                --sg-bg-subtle: #f8f9fa;
                --sg-bg-elevated: #ffffff;
                --sg-border: #dee2e6;
            }
            [data-bs-theme="dark"] .sgabout-wrap,
            [data-color-scheme="dark"] .sgabout-wrap,
            .theme-dark .sgabout-wrap {
                --sg-text: rgba(255, 255, 255, 0.92);
                --sg-text-muted: rgba(255, 255, 255, 0.6);
                --sg-bg: rgba(255, 255, 255, 0.02);
                --sg-bg-subtle: rgba(255, 255, 255, 0.04);
                --sg-bg-elevated: rgba(255, 255, 255, 0.06);
                --sg-border: rgba(255, 255, 255, 0.12);
            }
            .sgabout-wrap { max-width: 880px; color: var(--sg-text); }
            .sgabout-hero {
                display: flex;
                align-items: center;
                gap: 22px;
                padding: 22px 24px;
                background: linear-gradient(135deg, #1e40af 0%, #0f1e4d 100%);
                color: #fff;
                border-radius: 12px;
                margin-bottom: 22px;
                flex-wrap: wrap;
            }
            .sgabout-hero-logo {
                width: 90px; height: 90px; flex: 0 0 90px;
                background: rgba(255, 255, 255, 0.96);
                border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
                padding: 12px;
                box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
            }
            .sgabout-hero-logo img { display: block; width: 100%; height: 100%; }
            .sgabout-hero-text { flex: 1 1 300px; }
            .sgabout-hero-text h2 { margin: 0 0 4px; font-size: 22px; font-weight: 700; color: #fff; }
            .sgabout-hero-text .sgabout-ver { font-size: 12px; color: rgba(255,255,255,0.7); margin: 0 0 6px; }
            .sgabout-hero-text p { margin: 0; font-size: 14px; color: rgba(255,255,255,0.9); line-height: 1.5; }

            .sgabout-section { margin: 0 0 22px; }
            .sgabout-section h3 {
                font-size: 16px; font-weight: 700; margin: 0 0 10px;
                padding: 0 0 6px;
                border-bottom: 2px solid #fbbf24;
                display: inline-block;
            }
            .sgabout-section p {
                font-size: 13px;
                line-height: 1.55;
                color: var(--sg-text);
                margin: 0 0 8px;
            }
            .sgabout-links {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 10px;
                margin: 0;
                padding: 0;
                list-style: none;
            }
            .sgabout-links a {
                display: block;
                padding: 10px 14px;
                background: var(--sg-bg-subtle);
                border: 1px solid var(--sg-border);
                border-left: 4px solid #1e40af;
                border-radius: 0 6px 6px 0;
                font-size: 13px;
                font-weight: 600;
                color: #1e40af;
                text-decoration: none;
                transition: background 0.15s, color 0.15s;
            }
            .sgabout-links a:hover {
                background: #1e40af;
                color: #fff;
            }
            /* In dark mode the cobalt link text dies against Atum's dark navy chrome.
               Switch to Cybersalt orange — high contrast on dark, on-brand. */
            [data-bs-theme="dark"] .sgabout-links a,
            [data-color-scheme="dark"] .sgabout-links a,
            .theme-dark .sgabout-links a {
                border-left-color: #fbbf24;
                color: #fbbf24;
            }
            [data-bs-theme="dark"] .sgabout-links a:hover,
            [data-color-scheme="dark"] .sgabout-links a:hover,
            .theme-dark .sgabout-links a:hover {
                background: #fbbf24;
                color: #0f1e4d;
            }

            .sgabout-meta {
                font-size: 11px;
                color: var(--sg-text-muted);
                margin-top: 16px;
                padding-top: 12px;
                border-top: 1px solid var(--sg-border);
            }
        </style>

        <div class="sgabout-wrap" id="sgabout-wrap">
            <div class="sgabout-hero">
                <div class="sgabout-hero-logo"><img src="{$logoUrl}" alt="Cybersalt SiteGround Cache"></div>
                <div class="sgabout-hero-text">
                    <h2>{$aboutH}</h2>
                    <p class="sgabout-ver">Version {$version}</p>
                    <p>{$aboutBody}</p>
                </div>
            </div>

            <div class="sgabout-section">
                <h3>{$linksH}</h3>
                <ul class="sgabout-links">
                    <li><a href="https://www.cybersalt.com/joomla-extensions" target="_blank" rel="noopener">{$linkExts} &rarr;</a></li>
                    <li><a href="https://github.com/cybersalt/cs-siteground-cache-for-joomla" target="_blank" rel="noopener">{$linkGH} &rarr;</a></li>
                    <li><a href="https://github.com/cybersalt/cs-siteground-cache-for-joomla/issues" target="_blank" rel="noopener">{$linkBug} &rarr;</a></li>
                </ul>
            </div>

            <div class="sgabout-section">
                <h3>{$supportH}</h3>
                <p>{$supportTxt}</p>
            </div>

            <div class="sgabout-section">
                <h3>{$attribH}</h3>
                <p>{$attribTxt}</p>
            </div>

            <p class="sgabout-meta">{$licenseTxt}</p>
        </div>

HTML;
    }

    protected function getLabel(): string
    {
        return '';
    }
}
