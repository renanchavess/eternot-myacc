<?php
defined('MYAAC') or die('Direct access not allowed!');

// Apenas GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_SLASHES);
    exit;
}

// Params: file=Eternot-1511.zip, exp=unix_ts, sig=hex(hmac_sha256(file|exp, secret))
$file = isset($_GET['file']) ? (string)$_GET['file'] : '';
$exp = isset($_GET['exp']) ? (int)$_GET['exp'] : 0;
$sig = isset($_GET['sig']) ? strtolower((string)$_GET['sig']) : '';

// Valida nome do arquivo
if (!preg_match('/^Eternot-[0-9]{4,}\.(zip)$/', $file)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'invalid file name'], JSON_UNESCAPED_SLASHES);
    exit;
}

// Verifica expiração
if ($exp <= time()) {
    http_response_code(410); // Gone
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'link expired'], JSON_UNESCAPED_SLASHES);
    exit;
}

// Secret do .env ou config local
$secret = getenv('LAUNCHER_DOWNLOAD_SECRET');
if (!$secret || strlen($secret) < 16) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'download secret not configured'], JSON_UNESCAPED_SLASHES);
    exit;
}

// Verifica assinatura
$payload = $file . '|' . $exp;
$expectedSig = hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expectedSig, $sig)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'invalid signature'], JSON_UNESCAPED_SLASHES);
    exit;
}

// Caminho físico
$path = BASE . 'downloads/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'file not found'], JSON_UNESCAPED_SLASHES);
    exit;
}

// Log simples
$logEvent = [
    'ts' => date('c'),
    'file' => $file,
    'ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
];
@file_put_contents(SYSTEM . 'data/launcher_downloads.jsonl', json_encode($logEvent, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

// Headers e envio
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($path);
exit;