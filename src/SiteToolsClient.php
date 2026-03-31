<?php

/**
 * SiteGround Site Tools Client
 *
 * Communicates with SiteGround's caching daemon via UNIX domain socket.
 *
 * The communication protocol (UNIX socket path, JSON request format, and API
 * parameters) was learned by studying SiteGround's "Speed Optimizer" plugin
 * for WordPress (formerly SG CachePress), which is published under the
 * GNU General Public License v3 by SiteGround (https://siteground.com).
 * Source reviewed via: https://github.com/WordpressPluginDirectory/sg-cachepress
 *
 * No code was copied from the WordPress plugin. This implementation was
 * written from scratch for Joomla, informed by an understanding of the
 * server-side API. This is permitted under the GPL's freedom to study
 * how a program works (GPL Freedom 1).
 *
 * @package     Cybersalt.Plugin.System.SgCache
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Cybersalt\Plugin\System\SgCache;

\defined('_JEXEC') or die;

class SiteToolsClient
{
    /**
     * Path to the SiteGround Site Tools UNIX socket.
     */
    private const SOCKET_PATH = '/chroot/tmp/site-tools.sock';

    /**
     * Check whether we are running on a SiteGround server.
     */
    public static function isSiteGround(): bool
    {
        return file_exists(self::SOCKET_PATH);
    }

    /**
     * Flush the dynamic cache for a given hostname and path pattern.
     *
     * @param string $hostname  The site hostname (without www.)
     * @param string $path      The path regex pattern, e.g. "/some-page/(.*)"
     *
     * @return bool True on success, false on failure.
     */
    public static function flushDynamicCache(string $hostname, string $path): bool
    {
        $args = [
            'api'      => 'domain-all',
            'cmd'      => 'update',
            'settings' => ['json' => 1],
            'params'   => [
                'flush_cache' => '1',
                'id'          => $hostname,
                'path'        => $path,
            ],
        ];

        $result = self::callSiteToolsClient($args);

        if ($result === false) {
            return false;
        }

        if (isset($result['err_code'])) {
            return false;
        }

        return true;
    }

    /**
     * Send a request to the SiteGround Site Tools daemon via UNIX socket.
     *
     * @param array $args  The request payload.
     *
     * @return array|false Decoded JSON response, or false on failure.
     */
    private static function callSiteToolsClient(array $args): array|false
    {
        if (!file_exists(self::SOCKET_PATH)) {
            return false;
        }

        $fp = @stream_socket_client(
            'unix://' . self::SOCKET_PATH,
            $errno,
            $errstr,
            5
        );

        if ($fp === false) {
            return false;
        }

        $request = json_encode([
            'api'      => $args['api'],
            'cmd'      => $args['cmd'],
            'params'   => $args['params'],
            'settings' => $args['settings'],
        ]);

        fwrite($fp, $request . "\n");

        $response = fgets($fp, 32 * 1024);

        fclose($fp);

        if (empty($response)) {
            return false;
        }

        $decoded = @json_decode($response, true);

        if (!\is_array($decoded)) {
            return false;
        }

        return $decoded;
    }
}
