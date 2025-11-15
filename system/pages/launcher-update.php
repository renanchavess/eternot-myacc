<?php
defined('MYAAC') or die('Direct access not allowed!');

// Allow only GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Permite definir a versão via query (?version=1511). Padrão: 1511
$version = isset($_GET['version']) ? preg_replace('/[^0-9]/', '', $_GET['version']) : '1511';
// Base do link de backup pode vir via env (LAUNCHER_BACKUP_BASE) ou query (?backup_base=https://vps.exemplo/releases/)
$backupBase = isset($_GET['backup_base']) ? $_GET['backup_base'] : (getenv('LAUNCHER_BACKUP_BASE') ?: 'https://eternot.com.br/downloads/');
// Permite sobrescrever o link completo de backup via query (?backup=https://.../Eternot-1511.zip)
$backupUrl = isset($_GET['backup']) ? $_GET['backup'] : rtrim($backupBase, '/').'/Eternot-'.$version.'.zip';
// Caminho de extração adaptável ao padrão solicitado
$extractPath = sprintf('c:\\Eternot\\Eternot-%s', $version);

$response = [
    'date_version' => '2025-11-15 22:00:00',
    'version' => $version,
    'url_download' => 'https://drive.usercontent.google.com/download?id=1wpLPHWXMiYs-UYqdy2dbh2SqYx1T-sYv&export=download&authuser=0&confirm=t&uuid=535aa98e-4644-4c64-a7de-99bc47a003d7&at=ALWLOp4Rj2nvTKVcuUdjPDLWL1us:1763181346977',
    'url_download_backup' => $backupUrl,
];

// $response = [
//     'date_version' => '2025-11-10 13:00:00',
//     'version' => '1503',
//     'url_download' => 'https://drive.usercontent.google.com/download?id=16Dw57hGGevscuj8y4kWktMKf34ptMoXX&export=download&authuser=0&confirm=t&uuid=b7225e40-2988-46d7-abd5-6142f7665c0a&at=ALWLOp5ylpt9pD5GMQ9p0L-Z9p5k:1762811908968',
//     'url_download_backup' => $backupUrl
// ];

echo json_encode($response, JSON_UNESCAPED_SLASHES);
exit;