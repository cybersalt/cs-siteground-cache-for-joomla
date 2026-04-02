<?php

/**
 * @package     Cybersalt.Plugin.System.SgCache
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Cybersalt\Plugin\System\SgCache;

\defined('_JEXEC') or die;

class Logger
{
    /**
     * Default log filename.
     */
    private const DEFAULT_FILE = 'sgcache.log';

    /**
     * JSON encoding flags.
     */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Request ID for correlating entries within a single request.
     */
    private static string $requestId = '';

    /**
     * Microsecond timestamp when the request started.
     */
    private static float $requestStart = 0;

    /**
     * In-memory buffer of entries logged during this request (for debug panel).
     */
    private static array $requestEntries = [];

    /**
     * Whether logging is enabled.
     */
    private static bool $enabled = false;

    /**
     * Full path to the log file.
     */
    private static string $logFile = '';

    /**
     * Maximum log file size in bytes before rotation.
     */
    private static int $maxSize = 5242880; // 5 MB

    /**
     * Whether logging is suppressed for this request (e.g. our own AJAX calls).
     */
    private static bool $suppressed = false;

    /**
     * Initialize the logger for this request.
     */
    public static function init(bool $enabled, string $logFile = '', int $maxSizeMb = 5): void
    {
        self::$enabled = $enabled;
        self::$requestId = substr(md5(uniqid('', true)), 0, 8);
        self::$requestStart = microtime(true);
        self::$requestEntries = [];
        self::$maxSize = $maxSizeMb * 1048576;

        if (empty($logFile)) {
            self::$logFile = JPATH_ROOT . '/logs/' . self::DEFAULT_FILE;
        } else {
            self::$logFile = JPATH_ROOT . '/logs/' . basename($logFile);
        }
    }

    /**
     * Suppress file logging for this request (e.g. our own AJAX calls).
     * Debug panel entries are still buffered in memory.
     */
    public static function suppress(): void
    {
        self::$suppressed = true;
    }

    /**
     * Log an event.
     *
     * @param string $event   Event name (e.g. 'purge_url', 'set_header', 'socket_send')
     * @param array  $data    Event-specific data
     * @param string $level   Log level: 'info', 'warning', 'error', 'debug'
     */
    public static function log(string $event, array $data = [], string $level = 'info'): void
    {
        $entry = [
            'timestamp'  => date('Y-m-d H:i:s.') . substr(microtime(), 2, 4),
            'request_id' => self::$requestId,
            'elapsed_ms' => (int) round((microtime(true) - self::$requestStart) * 1000),
            'level'      => $level,
            'event'      => $event,
            'data'       => $data,
        ];

        // Always buffer for debug panel
        self::$requestEntries[] = $entry;

        // Write to file if logging is enabled and not suppressed
        if (!self::$enabled || empty(self::$logFile) || self::$suppressed) {
            return;
        }

        $logDir = \dirname(self::$logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        // Auto-rotate if file is too large
        if (is_file(self::$logFile) && filesize(self::$logFile) > self::$maxSize) {
            self::rotate();
        }

        $line = json_encode($entry, self::JSON_FLAGS) . "\n";
        @file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Convenience methods for different log levels.
     */
    public static function info(string $event, array $data = []): void
    {
        self::log($event, $data, 'info');
    }

    public static function warning(string $event, array $data = []): void
    {
        self::log($event, $data, 'warning');
    }

    public static function error(string $event, array $data = []): void
    {
        self::log($event, $data, 'error');
    }

    public static function debug(string $event, array $data = []): void
    {
        self::log($event, $data, 'debug');
    }

    /**
     * Get all entries logged during this request (for debug panel).
     */
    public static function getRequestEntries(): array
    {
        return self::$requestEntries;
    }

    /**
     * Get the request ID.
     */
    public static function getRequestId(): string
    {
        return self::$requestId;
    }

    /**
     * Get the log file path.
     */
    public static function getLogFile(): string
    {
        return self::$logFile;
    }

    /**
     * Read log entries from file.
     *
     * @param int    $limit      Max entries to return
     * @param int    $offset     Entries to skip
     * @param string $requestId  Filter by request ID (partial match)
     * @param string $level      Filter by level
     * @param string $event      Filter by event name
     *
     * @return array{entries: array, total: int, offset: int, limit: int}
     */
    public static function readEntries(
        int $limit = 50,
        int $offset = 0,
        string $requestId = '',
        string $level = '',
        string $event = ''
    ): array {
        if (!is_file(self::$logFile)) {
            return ['entries' => [], 'total' => 0, 'offset' => 0, 'limit' => $limit];
        }

        $raw = file_get_contents(self::$logFile);
        if (empty($raw)) {
            return ['entries' => [], 'total' => 0, 'offset' => 0, 'limit' => $limit];
        }

        $lines = array_filter(explode("\n", $raw));
        $entries = [];

        foreach ($lines as $line) {
            $decoded = @json_decode($line, true);
            if (!\is_array($decoded)) {
                continue;
            }

            // Apply filters
            if ($requestId && !str_contains($decoded['request_id'] ?? '', $requestId)) {
                continue;
            }
            if ($level && ($decoded['level'] ?? '') !== $level) {
                continue;
            }
            if ($event && ($decoded['event'] ?? '') !== $event) {
                continue;
            }

            $entries[] = $decoded;
        }

        // Sort newest first
        usort($entries, fn($a, $b) => ($b['timestamp'] ?? '') <=> ($a['timestamp'] ?? ''));

        $total = \count($entries);
        $sliced = \array_slice($entries, $offset, $limit);

        return ['entries' => $sliced, 'total' => $total, 'offset' => $offset, 'limit' => $limit];
    }

    /**
     * Get log statistics.
     */
    public static function getStats(): array
    {
        $stats = [
            'file_exists'   => false,
            'file_size'     => 0,
            'file_size_human' => '0 B',
            'entry_count'   => 0,
            'request_count' => 0,
            'events'        => [],
            'warnings'      => 0,
            'errors'        => 0,
            'purge_count'   => 0,
        ];

        if (!is_file(self::$logFile)) {
            return $stats;
        }

        $stats['file_exists'] = true;
        $stats['file_size'] = filesize(self::$logFile);
        $stats['file_size_human'] = self::humanSize($stats['file_size']);

        $raw = file_get_contents(self::$logFile);
        if (empty($raw)) {
            return $stats;
        }

        $lines = array_filter(explode("\n", $raw));
        $requestIds = [];

        foreach ($lines as $line) {
            $decoded = @json_decode($line, true);
            if (!\is_array($decoded)) {
                continue;
            }

            $stats['entry_count']++;

            $rid = $decoded['request_id'] ?? '';
            if ($rid) {
                $requestIds[$rid] = true;
            }

            $evt = $decoded['event'] ?? 'unknown';
            $stats['events'][$evt] = ($stats['events'][$evt] ?? 0) + 1;

            $lvl = $decoded['level'] ?? 'info';
            if ($lvl === 'warning') {
                $stats['warnings']++;
            } elseif ($lvl === 'error') {
                $stats['errors']++;
            }

            if (str_contains($evt, 'purge') || str_contains($evt, 'flush')) {
                $stats['purge_count']++;
            }
        }

        $stats['request_count'] = \count($requestIds);

        return $stats;
    }

    /**
     * Clear the log file.
     */
    public static function clear(): array
    {
        $result = [
            'success' => false,
            'file'    => self::$logFile,
            'existed' => is_file(self::$logFile),
            'previous_size' => 0,
        ];

        if ($result['existed']) {
            $result['previous_size'] = filesize(self::$logFile);

            if (@unlink(self::$logFile)) {
                $result['success'] = true;
                $result['method'] = 'deleted';
            } elseif (@file_put_contents(self::$logFile, '') !== false) {
                $result['success'] = true;
                $result['method'] = 'truncated';
            }
        } else {
            $result['success'] = true;
            $result['method'] = 'not_found';
        }

        return $result;
    }

    /**
     * Run diagnostics on the logging system.
     */
    public static function test(): array
    {
        $logDir = \dirname(self::$logFile);

        $result = [
            'log_file'      => self::$logFile,
            'log_dir'       => $logDir,
            'dir_exists'    => is_dir($logDir),
            'dir_writable'  => is_writable($logDir),
            'file_exists'   => is_file(self::$logFile),
            'file_writable' => is_file(self::$logFile) ? is_writable(self::$logFile) : null,
            'siteground'    => SiteToolsClient::isSiteGround(),
            'write_test'    => false,
            'errors'        => [],
        ];

        // Try to create directory
        if (!$result['dir_exists']) {
            if (@mkdir($logDir, 0755, true)) {
                $result['dir_exists'] = true;
                $result['dir_writable'] = is_writable($logDir);
            } else {
                $result['errors'][] = 'Cannot create log directory: ' . $logDir;
            }
        }

        if (!$result['dir_writable']) {
            $result['errors'][] = 'Log directory is not writable: ' . $logDir;
        }

        // Write test entry
        if ($result['dir_exists'] && $result['dir_writable']) {
            $testEntry = json_encode([
                'timestamp'  => date('Y-m-d H:i:s'),
                'request_id' => 'test0000',
                'elapsed_ms' => 0,
                'level'      => 'info',
                'event'      => 'diagnostic_test',
                'data'       => ['message' => 'Logging system test'],
            ], self::JSON_FLAGS) . "\n";

            $bytes = @file_put_contents(self::$logFile, $testEntry, FILE_APPEND | LOCK_EX);

            if ($bytes !== false && $bytes > 0) {
                $result['write_test'] = true;
                $result['bytes_written'] = $bytes;
            } else {
                $result['errors'][] = 'Failed to write test entry';
            }
        }

        $result['success'] = empty($result['errors']);

        return $result;
    }

    /**
     * Rotate the log file.
     */
    private static function rotate(): void
    {
        $rotated = self::$logFile . '.' . date('Y-m-d-His');
        @rename(self::$logFile, $rotated);

        // Clean up old rotated files (keep last 5)
        $dir = \dirname(self::$logFile);
        $base = basename(self::$logFile);
        $rotatedFiles = glob($dir . '/' . $base . '.*');

        if ($rotatedFiles && \count($rotatedFiles) > 5) {
            sort($rotatedFiles);
            $toDelete = \array_slice($rotatedFiles, 0, \count($rotatedFiles) - 5);
            foreach ($toDelete as $old) {
                @unlink($old);
            }
        }
    }

    /**
     * Format bytes as human-readable string.
     */
    private static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < 3) {
            $size /= 1024;
            $i++;
        }

        return round($size, 1) . ' ' . $units[$i];
    }
}
