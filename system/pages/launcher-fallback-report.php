<?php
defined('MYAAC') or die('Direct access not allowed!');

// Aceita apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Suporta JSON body ou form-data
$input = [];
$contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower($_SERVER['CONTENT_TYPE']) : '';
if (strpos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $input = $json;
    }
} else {
    $input = $_POST;
}

// Campos esperados
$version = isset($input['version']) ? preg_replace('/[^0-9]/', '', (string)$input['version']) : null;
$primaryUrl = isset($input['primary_url']) ? (string)$input['primary_url'] : null;
$backupUrl = isset($input['backup_url']) ? (string)$input['backup_url'] : null;
$reason = isset($input['reason']) ? (string)$input['reason'] : null;

if (!$version || !$backupUrl) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: version, backup_url'], JSON_UNESCAPED_SLASHES);
    exit;
}

// Info do cliente
$ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null);
$ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;

// Registro em JSONL (uma linha por evento)
$event = [
    'ts' => date('c'),
    'version' => $version,
    'primary_url' => $primaryUrl,
    'backup_url' => $backupUrl,
    'reason' => $reason,
    'ip' => $ip,
    'user_agent' => $ua,
];

$logFile = __DIR__ . '/../data/launcher_fallback.jsonl';
@file_put_contents($logFile, json_encode($event, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

echo json_encode(['status' => 'ok'], JSON_UNESCAPED_SLASHES);
exit;