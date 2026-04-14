<?php
/**
 * ONE-SHOT: wist alle tracking events + leads. Beschermd met DEPLOY_SECRET.
 * Verwijder dit bestand direct na gebruik.
 */
require_once __DIR__ . '/../src/config.php';

$key = $_GET['key'] ?? '';
if (!defined('DEPLOY_SECRET') || empty(DEPLOY_SECRET) || !hash_equals(DEPLOY_SECRET, $key)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$tables = [
    'tracking_pageviews',
    'tracking_conversions',
    'tracking_forms',
    'tracking_scroll',
    'tracking_time',
    'tracking_video',
    'tracking_form_interactions',
    'visitor_aliases',
    'clients',
];

$pdo = db();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$result = [];
foreach ($tables as $t) {
    try {
        $before = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        $pdo->exec("TRUNCATE TABLE `$t`");
        $result[$t] = ['wiped' => $before, 'ok' => true];
    } catch (Throwable $e) {
        $result[$t] = ['error' => $e->getMessage()];
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo json_encode(['status' => 'ok', 'tables' => $result], JSON_PRETTY_PRINT);
