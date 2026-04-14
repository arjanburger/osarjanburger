<?php
require_once __DIR__ . '/../src/config.php';
$key = $_GET['key'] ?? '';
if (!defined('DEPLOY_SECRET') || empty(DEPLOY_SECRET) || !hash_equals(DEPLOY_SECRET, $key)) {
    http_response_code(403); echo 'Unauthorized'; exit;
}
header('Content-Type: application/json');

$out = [
    'products' => db()->query("SELECT id, name, slug, status FROM products ORDER BY id")->fetchAll(),
    'landing_pages' => db()->query("SELECT id, slug, title, product_id, status FROM landing_pages ORDER BY id")->fetchAll(),
    'tracking_slugs' => db()->query("SELECT page_slug, COUNT(*) as n FROM tracking_pageviews GROUP BY page_slug ORDER BY n DESC")->fetchAll(),
    'clients' => db()->query("SELECT id, name, email, source_page, product_id, created_at FROM clients ORDER BY id DESC LIMIT 20")->fetchAll(),
];
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
