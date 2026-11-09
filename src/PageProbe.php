<?php

/**
 * Fetches the site's own pages the way an anonymous visitor would, through
 * SiteGround's cache, for the "Test my home page" button and the home page
 * watchdog task.
 *
 * A probe request carries an HMAC header (X-SGCache-Probe). When the plugin
 * renders a page for it, it answers with X-SGCache-Decision: why the page was
 * or wasn't marked cacheable. Normal visitors never see that header unless
 * SiteGround stored it with a cached page, and then it only says "cached".
 *
 * @package     Cybersalt.Plugin.System.SgCache
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Cybersalt\Plugin\System\SgCache;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class PageProbe
{
    public const HEADER          = 'X-SGCache-Probe';
    public const DECISION_HEADER = 'X-SGCache-Decision';

    /** The value a genuine probe sends. Derived from the site secret. */
    public static function key(): string
    {
        return hash_hmac('sha256', 'sgcache-probe', (string) Factory::getApplication()->get('secret'));
    }

    /** Whether the current request is one of our probes. */
    public static function isProbe(): bool
    {
        $sent = (string) ($_SERVER['HTTP_X_SGCACHE_PROBE'] ?? '');

        return $sent !== '' && hash_equals(self::key(), $sent);
    }

    /**
     * GET a URL as an anonymous browser (no cookies), following redirects.
     *
     * @return array{ok: bool, error: string, status: int, url: string, headers: array<string, string>, html: bool, bytes: int}
     *         headers: lower-case name => value (last one wins, Set-Cookie joined).
     *         html:    Content-Type is text/html and the body has an <html tag.
     */
    public static function fetch(string $url, bool $probe = true): array
    {
        $result = ['ok' => false, 'error' => '', 'status' => 0, 'url' => $url, 'headers' => [], 'html' => false, 'bytes' => 0];

        if (!\function_exists('curl_init')) {
            $result['error'] = 'PHP curl extension not available';
            return $result;
        }

        $headers = [];
        $send    = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control:',
        ];
        if ($probe) {
            $send[] = self::HEADER . ': ' . self::key();
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36 SGCacheProbe',
            CURLOPT_HTTPHEADER     => $send,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                // A redirect starts a new header block; keep only the last.
                if (preg_match('#^HTTP/\S+\s+\d+#', $line)) {
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $name  = strtolower(trim($name));
                    $value = trim($value);
                    $headers[$name] = ($name === 'set-cookie' && isset($headers[$name])) ? $headers[$name] . ', ' . $value : $value;
                }

                return \strlen($line);
            },
        ]);

        $body = curl_exec($ch);

        if ($body === false) {
            $result['error'] = curl_error($ch);
            curl_close($ch);
            return $result;
        }

        $result['ok']      = true;
        $result['status']  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $result['url']     = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $result['headers'] = $headers;
        $result['bytes']   = \strlen((string) $body);
        $result['html']    = str_starts_with(strtolower($headers['content-type'] ?? ''), 'text/html')
            && stripos((string) $body, '<html') !== false;
        curl_close($ch);

        return $result;
    }
}
