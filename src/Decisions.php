<?php

/**
 * Recent cache decisions, for the status dashboard.
 *
 * Pages SiteGround serves from its cache never reach PHP, so what the plugin
 * sees is the uncached traffic. If nearly all of it is bypassed for the same
 * fixable reason (a form token on every page, a cookie that won't go), the
 * site gets no caching and nothing else would say so.
 *
 * Kept small and private: one line per decision with the time, the reason
 * code and, for the fixable reasons only, which of the site's own forms or
 * settings caused it. Nothing taken from the request (no URLs, query
 * strings, IPs or user agents). Stored in Joomla's log folder as a .php file
 * that starts with die(), so a browser can't read it.
 *
 * @package     Cybersalt.Plugin.System.SgCache
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Cybersalt\Plugin\System\SgCache;

\defined('_JEXEC') or die;

class Decisions
{
    private const FILE      = 'sgcache_decisions';
    private const MAX_BYTES = 32768;
    private const KEEP      = 200;

    /**
     * Reasons that mean the site isn't caching when it could be. The others
     * (exclusions, feeds, query strings, POSTs) are by design.
     */
    public const PROBLEMS = [
        'form_token_on_page',
        'suppress_session_cookie_off',
        'set_cookie_in_app_headers',
        'headers_already_sent',
    ];

    public static function record(string $decision, string $detail = ''): void
    {
        try {
            // Only the fixable reasons carry a detail, and that detail
            // describes the site's own forms, never the request.
            $detail = \in_array($decision, self::PROBLEMS, true) ? $detail : '';
            $file   = self::path();
            $line   = time() . "\t" . $decision . "\t" . str_replace(["\t", "\r", "\n"], ' ', mb_substr($detail, 0, 300)) . "\n";

            Logger::append($file, $line);

            clearstatcache(true, $file);
            if (@filesize($file) > self::MAX_BYTES) {
                $lines = self::lines();
                @file_put_contents($file, Logger::HEADER . implode("\n", \array_slice($lines, -self::KEEP)) . "\n", LOCK_EX);
            }
        } catch (\Throwable $e) {
            // Never let the dashboard's bookkeeping break a page.
        }
    }

    /** @return string[] Decision lines, without the die() header. */
    private static function lines(): array
    {
        return array_values(array_filter(explode("\n", Logger::contents(self::path())), 'strlen'));
    }

    /**
     * Summary of the last $count decisions.
     *
     * @return array{total: int, cached: int, problems: int, top: string, detail: string, since: int}
     *         top/detail: the most common problem reason and its latest detail.
     */
    public static function summary(int $count = 50): array
    {
        $summary = ['total' => 0, 'cached' => 0, 'problems' => 0, 'top' => '', 'detail' => '', 'since' => 0];

        $lines = \array_slice(self::lines(), -$count);

        $tally   = [];
        $details = [];
        foreach ($lines as $line) {
            [$time, $decision, $detail] = array_pad(explode("\t", $line, 3), 3, '');

            $summary['total']++;
            $summary['since'] = $summary['since'] ?: (int) $time;

            if ($decision === 'cached') {
                $summary['cached']++;
            } elseif (\in_array($decision, self::PROBLEMS, true)) {
                $summary['problems']++;
                $tally[$decision]   = ($tally[$decision] ?? 0) + 1;
                $details[$decision] = $detail;
            }
        }

        if ($tally) {
            arsort($tally);
            $summary['top']    = (string) array_key_first($tally);
            $summary['detail'] = $details[$summary['top']];
        }

        return $summary;
    }

    public static function clear(): void
    {
        @unlink(self::path());
    }

    public static function path(): string
    {
        return Logger::pathFor(self::FILE);
    }
}
