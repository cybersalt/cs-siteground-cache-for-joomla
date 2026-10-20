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

class GettingstartedField extends FormField
{
    protected $type = 'Gettingstarted';

    protected function getInput(): string
    {
        // Cache-bust the logo on plugin updates so browsers fetch the new SVG
        $logoVer = '2.2.2';
        $logoUrl = Uri::root(true) . '/media/plg_system_sgcache/images/logo.svg?v=' . $logoVer;

        $welcome   = Text::_('PLG_SYSTEM_SGCACHE_GS_WELCOME');
        $tagline   = Text::_('PLG_SYSTEM_SGCACHE_GS_TAGLINE');
        $stepsH    = Text::_('PLG_SYSTEM_SGCACHE_GS_STEPS_HEADING');
        $step1     = Text::_('PLG_SYSTEM_SGCACHE_GS_STEP_1');
        $step2     = Text::_('PLG_SYSTEM_SGCACHE_GS_STEP_2');
        $step3     = Text::_('PLG_SYSTEM_SGCACHE_GS_STEP_3');
        $step4     = Text::_('PLG_SYSTEM_SGCACHE_GS_STEP_4');
        $tipsH     = Text::_('PLG_SYSTEM_SGCACHE_GS_TIPS_HEADING');
        $tip1      = Text::_('PLG_SYSTEM_SGCACHE_GS_TIP_1');
        $tip2      = Text::_('PLG_SYSTEM_SGCACHE_GS_TIP_2');
        $tip3      = Text::_('PLG_SYSTEM_SGCACHE_GS_TIP_3');

        return <<<HTML
        <style>
            /* Scoped theme variables (light defaults, switch on any dark-mode trigger) */
            .sggs-wrap {
                --sg-text: #212529;
                --sg-text-muted: #6c757d;
                --sg-bg: #ffffff;
                --sg-bg-subtle: #f8f9fa;
                --sg-bg-elevated: #ffffff;
                --sg-border: #dee2e6;
            }
            [data-bs-theme="dark"] .sggs-wrap,
            [data-color-scheme="dark"] .sggs-wrap,
            .theme-dark .sggs-wrap {
                --sg-text: rgba(255, 255, 255, 0.92);
                --sg-text-muted: rgba(255, 255, 255, 0.6);
                --sg-bg: rgba(255, 255, 255, 0.02);
                --sg-bg-subtle: rgba(255, 255, 255, 0.04);
                --sg-bg-elevated: rgba(255, 255, 255, 0.06);
                --sg-border: rgba(255, 255, 255, 0.12);
            }
            .sggs-wrap { max-width: 880px; color: var(--sg-text); }
            .sggs-hero {
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
            .sggs-hero-logo {
                width: 90px; height: 90px; flex: 0 0 90px;
                background: rgba(255, 255, 255, 0.96);
                border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
                padding: 12px;
                box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
            }
            .sggs-hero-logo img { display: block; width: 100%; height: 100%; }
            .sggs-hero-text { flex: 1 1 300px; }
            .sggs-hero-text h2 { margin: 0 0 6px; font-size: 22px; font-weight: 700; color: #fff; }
            .sggs-hero-text p  { margin: 0; font-size: 14px; color: rgba(255,255,255,0.85); line-height: 1.45; }

            .sggs-section { margin: 0 0 22px; }
            .sggs-section h3 {
                font-size: 16px;
                font-weight: 700;
                margin: 0 0 10px;
                padding: 0 0 6px;
                border-bottom: 2px solid #fbbf24;
                display: inline-block;
            }
            .sggs-steps { list-style: none; padding: 0; margin: 0; counter-reset: sgstep; }
            .sggs-steps li {
                counter-increment: sgstep;
                position: relative;
                padding: 10px 12px 10px 46px;
                margin-bottom: 6px;
                background: var(--sg-bg-subtle);
                border-left: 4px solid #1e40af;
                border-radius: 0 6px 6px 0;
                font-size: 14px;
                line-height: 1.5;
                color: var(--sg-text);
            }
            .sggs-steps li::before {
                content: counter(sgstep);
                position: absolute;
                left: 12px;
                top: 50%;
                transform: translateY(-50%);
                width: 24px; height: 24px;
                background: #1e40af;
                color: #fff;
                font-weight: 700;
                font-size: 13px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .sggs-tips { list-style: none; padding: 0; margin: 0; }
            .sggs-tips li {
                padding: 8px 12px 8px 32px;
                position: relative;
                font-size: 13px;
                line-height: 1.5;
                color: var(--sg-text);
            }
            .sggs-tips li::before {
                content: "★";
                position: absolute;
                left: 10px;
                top: 8px;
                color: #fbbf24;
                font-size: 14px;
            }
        </style>

        <div class="sggs-wrap" id="sggs-wrap">
            <div class="sggs-hero">
                <div class="sggs-hero-logo"><img src="{$logoUrl}" alt="Cybersalt SiteGround Cache"></div>
                <div class="sggs-hero-text">
                    <h2>{$welcome}</h2>
                    <p>{$tagline}</p>
                </div>
            </div>

            <div class="sggs-section">
                <h3>{$stepsH}</h3>
                <ol class="sggs-steps">
                    <li>{$step1}</li>
                    <li>{$step2}</li>
                    <li>{$step3}</li>
                    <li>{$step4}</li>
                </ol>
            </div>

            <div class="sggs-section">
                <h3>{$tipsH}</h3>
                <ul class="sggs-tips">
                    <li>{$tip1}</li>
                    <li>{$tip2}</li>
                    <li>{$tip3}</li>
                </ul>
            </div>
        </div>

HTML;
    }

    protected function getLabel(): string
    {
        return '';
    }
}
