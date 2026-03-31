<?php
/**
 * SG Cache Log Viewer
 *
 * @package     Cybersalt.Plugin.System.SgCache
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

// Variables provided by ajaxRenderViewer(): $ajaxUrl, $token, $logFilePath
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SG Cache Log Viewer</title>
    <style>
        :root {
            --primary: #1a73e8;
            --danger: #dc3545;
            --success: #28a745;
            --warning: #ffc107;
            --info: #17a2b8;
            --bg-dark: #1e1e1e;
            --bg-card: #2d2d2d;
            --bg-header: #252525;
            --text: #e0e0e0;
            --text-muted: #999;
            --border: #333;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, monospace;
            background: var(--bg-dark);
            color: var(--text);
            font-size: 13px;
            line-height: 1.5;
        }
        .viewer-header {
            background: var(--bg-header);
            border-bottom: 2px solid var(--primary);
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .viewer-header h1 {
            font-size: 16px;
            font-weight: 600;
            color: var(--primary);
        }
        .btn-bar { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn {
            padding: 5px 14px;
            border: 1px solid var(--border);
            border-radius: 4px;
            background: var(--bg-card);
            color: var(--text);
            cursor: pointer;
            font-size: 12px;
            font-family: inherit;
            transition: background 0.15s;
        }
        .btn:hover { background: #3a3a3a; }
        .btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .btn-primary:hover { background: #1557b0; }
        .btn-success { background: var(--success); color: #fff; border-color: var(--success); }
        .btn-success:hover { background: #1e7e34; }
        .btn-danger { background: var(--danger); color: #fff; border-color: var(--danger); }
        .btn-danger:hover { background: #bd2130; }

        /* Stats bar */
        .stats-bar {
            display: flex;
            gap: 16px;
            padding: 8px 20px;
            background: var(--bg-header);
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }
        .stat-item { display: flex; align-items: center; gap: 6px; }
        .stat-value { font-weight: 700; color: var(--info); }
        .stat-value.warn { color: var(--warning); }
        .stat-value.err { color: var(--danger); }
        .stat-value.purge { color: var(--success); }

        /* Filters */
        .filter-bar {
            display: flex;
            gap: 10px;
            padding: 8px 20px;
            background: var(--bg-header);
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-bar label { color: var(--text-muted); font-size: 12px; }
        .filter-bar input, .filter-bar select {
            padding: 4px 8px;
            background: var(--bg-dark);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 3px;
            font-size: 12px;
            font-family: inherit;
        }

        /* Log entries */
        .log-container { padding: 10px 20px; }
        .log-entry {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 6px;
            margin-bottom: 6px;
            overflow: hidden;
        }
        .log-entry.level-error { border-left: 3px solid var(--danger); }
        .log-entry.level-warning { border-left: 3px solid var(--warning); }
        .log-entry.level-debug { border-left: 3px solid var(--text-muted); }
        .log-entry.level-info { border-left: 3px solid var(--info); }

        .log-entry-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 12px;
            cursor: pointer;
            user-select: none;
            flex-wrap: wrap;
        }
        .log-entry-header:hover { background: rgba(255,255,255,0.03); }
        .log-entry-expand { color: var(--text-muted); font-size: 10px; width: 14px; }
        .log-entry-time { color: var(--text-muted); font-size: 11px; white-space: nowrap; }
        .log-entry-rid {
            background: #3a3a3a;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 11px;
            color: var(--info);
            font-family: monospace;
        }
        .badge {
            padding: 1px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-info { background: rgba(23,162,184,0.2); color: var(--info); }
        .badge-error { background: rgba(220,53,69,0.2); color: var(--danger); }
        .badge-warning { background: rgba(255,193,7,0.2); color: var(--warning); }
        .badge-debug { background: rgba(108,117,125,0.2); color: var(--text-muted); }
        .badge-event { background: rgba(26,115,232,0.15); color: #5b9bf5; }
        .badge-elapsed { color: var(--text-muted); font-size: 11px; }

        .log-entry-details {
            display: none;
            padding: 10px 12px 10px 36px;
            border-top: 1px solid var(--border);
            background: rgba(0,0,0,0.15);
        }
        .log-entry-details.open { display: block; }
        .log-entry-details pre {
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 12px;
            line-height: 1.6;
        }
        /* JSON syntax highlighting */
        .json-key { color: #56b6c2; }
        .json-string { color: #ce9178; }
        .json-number { color: #b5cea8; }
        .json-bool, .json-null { color: #569cd6; }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            color: var(--text-muted);
        }

        /* Status messages */
        .status-msg {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }

        /* Debug info */
        .debug-info {
            padding: 6px 20px;
            background: var(--bg-header);
            border-bottom: 1px solid var(--border);
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>

<div class="viewer-header">
    <h1>SG Cache Log Viewer</h1>
    <div class="btn-bar">
        <button class="btn btn-primary" onclick="loadLog()">Refresh</button>
        <button class="btn" onclick="dumpLog()">Dump to Clipboard</button>
        <button class="btn btn-success" onclick="downloadLog()">Download</button>
        <button class="btn btn-danger" onclick="clearLog()">Clear Log</button>
        <button class="btn" onclick="testLogging()">Test Logging</button>
    </div>
</div>

<div id="stats-bar" class="stats-bar">
    <div class="stat-item">Entries: <span class="stat-value" id="stat-entries">-</span></div>
    <div class="stat-item">Requests: <span class="stat-value" id="stat-requests">-</span></div>
    <div class="stat-item">Size: <span class="stat-value" id="stat-size">-</span></div>
    <div class="stat-item">Purges: <span class="stat-value purge" id="stat-purges">-</span></div>
    <div class="stat-item">Warnings: <span class="stat-value warn" id="stat-warnings">-</span></div>
    <div class="stat-item">Errors: <span class="stat-value err" id="stat-errors">-</span></div>
</div>

<div class="debug-info">
    <span>Log file: <?php echo htmlspecialchars($logFilePath); ?></span>
    <span>Exists: <?php echo is_file($logFilePath) ? 'Yes' : 'No'; ?></span>
    <?php if (is_file($logFilePath)): ?>
    <span>Size: <?php echo number_format(filesize($logFilePath)); ?> bytes</span>
    <span>Writable: <?php echo is_writable($logFilePath) ? 'Yes' : 'No'; ?></span>
    <?php endif; ?>
</div>

<div class="filter-bar">
    <label>Request ID:</label>
    <input type="text" id="filter-rid" placeholder="e.g. a1b2c3d4" size="12">
    <label>Level:</label>
    <select id="filter-level">
        <option value="">All</option>
        <option value="info">Info</option>
        <option value="warning">Warning</option>
        <option value="error">Error</option>
        <option value="debug">Debug</option>
    </select>
    <label>Event:</label>
    <input type="text" id="filter-event" placeholder="e.g. flush_request" size="16">
    <label>Show:</label>
    <select id="filter-limit">
        <option value="50">50</option>
        <option value="100">100</option>
        <option value="250">250</option>
        <option value="500">500</option>
    </select>
    <button class="btn btn-primary" onclick="applyFilters()">Apply</button>
    <button class="btn" onclick="resetFilters()">Reset</button>
</div>

<div id="log-container" class="log-container">
    <div class="status-msg">Loading...</div>
</div>

<div id="pagination" class="pagination" style="display:none;">
    <span id="page-info"></span>
    <div class="btn-bar">
        <button class="btn" id="btn-prev" onclick="prevPage()">Previous</button>
        <button class="btn" id="btn-next" onclick="nextPage()">Next</button>
    </div>
</div>

<script>
var AJAX_URL = '<?php echo $ajaxUrl; ?>';
var currentOffset = 0;
var currentTotal = 0;

function getLimit() { return parseInt(document.getElementById('filter-limit').value) || 50; }

function loadLog() {
    var url = AJAX_URL + '&action=view'
        + '&lines=' + getLimit()
        + '&offset=' + currentOffset;

    var rid = document.getElementById('filter-rid').value.trim();
    var level = document.getElementById('filter-level').value;
    var event = document.getElementById('filter-event').value.trim();
    if (rid) url += '&request_id=' + encodeURIComponent(rid);
    if (level) url += '&level=' + encodeURIComponent(level);
    if (event) url += '&event=' + encodeURIComponent(event);

    document.getElementById('log-container').innerHTML = '<div class="status-msg">Loading...</div>';

    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                document.getElementById('log-container').innerHTML = '<div class="status-msg">Error: ' + (data.error || 'Unknown') + '</div>';
                return;
            }
            currentTotal = data.total || 0;
            renderEntries(data.entries || []);
            updatePagination();
        })
        .catch(function(e) {
            document.getElementById('log-container').innerHTML = '<div class="status-msg">Fetch error: ' + e.message + '</div>';
        });

    loadStats();
}

function loadStats() {
    fetch(AJAX_URL + '&action=stats')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) return;
            var s = data.stats;
            document.getElementById('stat-entries').textContent = s.entry_count;
            document.getElementById('stat-requests').textContent = s.request_count;
            document.getElementById('stat-size').textContent = s.file_size_human;
            document.getElementById('stat-purges').textContent = s.purge_count;
            document.getElementById('stat-warnings').textContent = s.warnings;
            document.getElementById('stat-errors').textContent = s.errors;
        });
}

function renderEntries(entries) {
    var container = document.getElementById('log-container');
    if (!entries.length) {
        container.innerHTML = '<div class="status-msg">No log entries found.</div>';
        return;
    }

    var html = '';
    entries.forEach(function(entry, idx) {
        var level = entry.level || 'info';
        var levelBadge = 'badge-' + level;
        var event = escHtml(entry.event || '');
        var time = entry.timestamp || '';
        var rid = entry.request_id || '';
        var elapsed = entry.elapsed_ms || 0;
        var data = entry.data || {};

        html += '<div class="log-entry level-' + level + '">'
            + '<div class="log-entry-header" onclick="toggleDetails(' + idx + ')">'
            + '<span class="log-entry-expand" id="expand-' + idx + '">&#9654;</span>'
            + '<span class="log-entry-time">' + escHtml(time) + '</span>'
            + '<span class="log-entry-rid">' + escHtml(rid) + '</span>'
            + '<span class="badge ' + levelBadge + '">' + level + '</span>'
            + '<span class="badge badge-event">' + event + '</span>'
            + '<span class="badge-elapsed">' + elapsed + 'ms</span>'
            + '</div>'
            + '<div class="log-entry-details" id="details-' + idx + '">'
            + '<pre>' + syntaxHighlight(JSON.stringify(data, null, 2)) + '</pre>'
            + '</div></div>';
    });

    container.innerHTML = html;
}

function toggleDetails(idx) {
    var el = document.getElementById('details-' + idx);
    var arrow = document.getElementById('expand-' + idx);
    if (el.classList.contains('open')) {
        el.classList.remove('open');
        arrow.innerHTML = '&#9654;';
    } else {
        el.classList.add('open');
        arrow.innerHTML = '&#9660;';
    }
}

function updatePagination() {
    var limit = getLimit();
    var pag = document.getElementById('pagination');
    if (currentTotal <= limit && currentOffset === 0) {
        pag.style.display = 'none';
        return;
    }
    pag.style.display = 'flex';
    var showing = Math.min(limit, currentTotal - currentOffset);
    document.getElementById('page-info').textContent = 'Showing ' + (currentOffset + 1) + '-' + (currentOffset + showing) + ' of ' + currentTotal;
    document.getElementById('btn-prev').disabled = currentOffset <= 0;
    document.getElementById('btn-next').disabled = (currentOffset + limit) >= currentTotal;
}

function nextPage() { currentOffset += getLimit(); loadLog(); }
function prevPage() { currentOffset = Math.max(0, currentOffset - getLimit()); loadLog(); }
function applyFilters() { currentOffset = 0; loadLog(); }
function resetFilters() {
    document.getElementById('filter-rid').value = '';
    document.getElementById('filter-level').value = '';
    document.getElementById('filter-event').value = '';
    document.getElementById('filter-limit').value = '50';
    currentOffset = 0;
    loadLog();
}

function clearLog() {
    if (!confirm('Clear all log entries? This cannot be undone.')) return;
    fetch(AJAX_URL + '&action=clear')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) { alert('Log cleared.'); loadLog(); }
            else { alert('Error clearing log: ' + (data.error || 'Unknown')); }
        })
        .catch(function(e) { alert('Error: ' + e.message); });
}

function downloadLog() {
    window.location.href = AJAX_URL + '&action=download';
}

function testLogging() {
    fetch(AJAX_URL + '&action=test')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var msg = 'Logging Test Results\n\n';
            msg += 'Log file: ' + data.log_file + '\n';
            msg += 'Directory exists: ' + data.dir_exists + '\n';
            msg += 'Directory writable: ' + data.dir_writable + '\n';
            msg += 'File exists: ' + data.file_exists + '\n';
            msg += 'Write test: ' + (data.write_test ? 'PASSED' : 'FAILED') + '\n';
            msg += 'SiteGround: ' + (data.siteground ? 'Detected' : 'Not detected') + '\n';
            if (data.errors && data.errors.length) { msg += '\nErrors:\n' + data.errors.join('\n'); }
            alert(msg);
            if (data.write_test) loadLog();
        })
        .catch(function(e) { alert('Test failed: ' + e.message); });
}

function dumpLog() {
    fetch(AJAX_URL + '&action=view&lines=9999&offset=0')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.entries || !data.entries.length) { alert('No entries to dump.'); return; }
            var text = data.entries.map(function(e) {
                return e.timestamp + ' [' + e.request_id + '] [' + (e.level || 'info').toUpperCase() + '] '
                    + e.event + ' ' + JSON.stringify(e.data || {});
            }).join('\n');
            navigator.clipboard.writeText(text).then(function() { alert('Log copied to clipboard (' + data.entries.length + ' entries).'); });
        })
        .catch(function(e) { alert('Error: ' + e.message); });
}

function syntaxHighlight(json) {
    json = escHtml(json);
    return json.replace(/("(\\u[\da-fA-F]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function(match) {
        var cls = 'json-number';
        if (/^"/.test(match)) {
            cls = /:$/.test(match) ? 'json-key' : 'json-string';
        } else if (/true|false/.test(match)) {
            cls = 'json-bool';
        } else if (/null/.test(match)) {
            cls = 'json-null';
        }
        return '<span class="' + cls + '">' + match + '</span>';
    });
}

function escHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Init
document.addEventListener('DOMContentLoaded', function() { loadLog(); });
document.getElementById('filter-rid').addEventListener('keydown', function(e) { if (e.key === 'Enter') applyFilters(); });
document.getElementById('filter-event').addEventListener('keydown', function(e) { if (e.key === 'Enter') applyFilters(); });
</script>
</body>
</html>
