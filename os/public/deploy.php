<?php
/**
 * ArjanBurger OS - Deploy webhook
 * URL: https://os.arjanburger.com/deploy.php?key=DEPLOY_SECRET
 */

// Laad secret uit .env
$envFile = dirname(__DIR__, 2) . '/.env';
$secret = '';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, 'DEPLOY_SECRET=')) {
            $secret = trim(substr($line, 14));
            break;
        }
    }
}

$key = $_GET['key'] ?? '';
if (empty($secret) || !hash_equals($secret, $key)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

set_time_limit(120);
header('Content-Type: application/json');

$root = dirname(__DIR__, 2);
$output = [];
$hasError = false;

$commands = [
    "cd $root && git pull origin main 2>&1",
];

foreach ($commands as $cmd) {
    $result = shell_exec($cmd);
    $output[] = ['cmd' => $cmd, 'output' => trim($result)];
    if (str_contains($result, 'fatal') || str_contains($result, 'error')) {
        $hasError = true;
    }
}

http_response_code($hasError ? 500 : 200);
echo json_encode([
    'status' => $hasError ? 'error' : 'ok',
    'deployed_at' => date('Y-m-d H:i:s'),
    'output' => $output,
]);
