<?php
/**
 * Get switch logs
 * GET /api/switches/logs/get.php?switch_id=<id>&lines=<n>&filter=<text>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

requireRole(['operator', 'admin']);

$switchId = $_GET['switch_id'] ?? null;
if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}
$switchId = (int)$switchId;

$lines = $_GET['lines'] ?? 200;
$lines = is_numeric($lines) ? (int)$lines : 200;
$lines = max(1, min($lines, 5000));

$filterText = isset($_GET['filter']) ? trim($_GET['filter']) : '';
$severityFilter = isset($_GET['severity']) ? $_GET['severity'] : null;
if ($severityFilter !== null && !is_array($severityFilter)) {
    $severityFilter = [$severityFilter];
}
if (is_array($severityFilter)) {
    $severityFilter = array_map('strtoupper', array_filter($severityFilter));
}

$db = Database::getInstance();
$switch = $db->queryOne("SELECT id FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

$severityMap = [
    0 => 'EMERGENCY',
    1 => 'ALERT',
    2 => 'CRITICAL',
    3 => 'ERROR',
    4 => 'WARNING',
    5 => 'NOTICE',
    6 => 'INFO',
    7 => 'DEBUG'
];

try {
    $eapi = new AristaEAPI($switchId);
    $rawLogs = $eapi->getLogs($lines);
    
    // DEBUG: Log raw output to file
    $debugPath = __DIR__ . '/../../logs_debug.txt';
    file_put_contents($debugPath, "Raw logs output:\n" . var_export($rawLogs, true) . "\n\n", FILE_APPEND);
    
    if ($rawLogs === null) {
        echo json_encode([
            'success' => true,
            'entries' => [],
            'severities' => [],
            'warnings' => ['No log output received (rawLogs is null)']
        ]);
        exit;
    }
    
    if (trim($rawLogs) === '') {
        echo json_encode([
            'success' => true,
            'entries' => [],
            'severities' => [],
            'warnings' => ['No log output received (rawLogs is empty string)']
        ]);
        exit;
    }

    $entries = [];
    $uniqueSeverities = [];
    $linesArray = preg_split('/\r?\n/', $rawLogs);

    // Only include content after the 'Log Buffer:' section marker if it exists
    $filteredLines = [];
    $inLogBuffer = false;
    foreach ($linesArray as $lineRaw) {
        $lineTrimmed = trim($lineRaw);
        if (!$inLogBuffer) {
            if (stripos($lineTrimmed, 'Log Buffer:') === 0) {
                $inLogBuffer = true;
                continue; // skip the marker line itself
            }
            continue; // skip any preamble lines
        }
        if ($lineTrimmed === '') {
            continue; // ignore blank lines inside the buffer
        }
        $filteredLines[] = $lineRaw;
    }
    if (!empty($filteredLines)) {
        $linesArray = $filteredLines;
    }

    foreach ($linesArray as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $timestamp = null;
        $device = null;
        $facility = null;
        $severityNum = null;
        $severityName = 'UNKNOWN';
        $code = null;
        $message = $line;

        // Extract timestamp, device, and remainder (e.g. 'Nov 10 12:34:56 switch ...')
        if (preg_match('/^([A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})\s+(\S+)\s+(.*)$/', $line, $matches)) {
            $timestamp = $matches[1];
            $device = $matches[2];
            $rest = $matches[3];
        } else {
            $rest = $line;
        }

        if (preg_match('/^(.*?)(?:\s+)?%(?:[A-Z0-9_-]+)-(\d)-([A-Z0-9_]+):\s*(.*)$/i', $rest, $matches)) {
            $facility = trim($matches[1]);
            $severityNum = (int)$matches[2];
            $code = strtoupper($matches[3]);
            $message = $matches[4];
        } else {
            $message = $rest;
        }

        if ($severityNum !== null) {
            $severityName = $severityMap[$severityNum] ?? ('SEVERITY-' . $severityNum);
        } else {
            if (preg_match('/\b(emerg|alert|crit|err|error|warn|warning|notice|info|debug)\b/i', $message, $m)) {
                $map = [
                    'emerg' => 'EMERGENCY',
                    'alert' => 'ALERT',
                    'crit' => 'CRITICAL',
                    'err' => 'ERROR',
                    'error' => 'ERROR',
                    'warn' => 'WARNING',
                    'warning' => 'WARNING',
                    'notice' => 'NOTICE',
                    'info' => 'INFO',
                    'debug' => 'DEBUG'
                ];
                $severityName = $map[strtolower($m[1])] ?? $severityName;
            }
        }

        if ($severityNum === null && ($severityName === 'EMERGENCY' || $severityName === 'ALERT')) {
            $severityNum = array_search($severityName, $severityMap, true);
        }

        $entry = [
            'timestamp' => $timestamp,
            'device' => $device,
            'facility' => $facility,
            'severity' => $severityName,
            'severity_number' => $severityNum,
            'code' => $code,
            'message' => trim($message),
            'raw' => $line
        ];

        if ($filterText !== '' && stripos($entry['raw'], $filterText) === false) {
            continue;
        }

        if ($severityFilter && !in_array($severityName, $severityFilter, true)) {
            continue;
        }

        $entries[] = $entry;
        $uniqueSeverities[$severityName] = true;
    }

    $severities = array_keys($uniqueSeverities);
    sort($severities);

    echo json_encode([
        'success' => true,
        'entries' => $entries,
        'severities' => $severities,
        'warnings' => []
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to retrieve logs: ' . $e->getMessage()]);
}

