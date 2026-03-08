<?php
/**
 * ArjanBurger OS - Configuratie
 */

// Laad .env als die bestaat (productie op Hostinger)
$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile) && !getenv('DB_NAME')) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
        }
        putenv("$k=$v");
    }
}

define('OS_NAME', 'ArjanBurger OS');
define('OS_VERSION', '0.1.0');

// Database (MySQL via brew services)
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'arjanburger_os');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// API
define('API_BASE', getenv('API_BASE') ?: 'http://127.0.0.1:18093/api/public');
define('FLOW_DOMAIN', getenv('FLOW_DOMAIN') ?: 'http://127.0.0.1:18093/flow/public');

// Deploy
define('DEPLOY_SECRET', getenv('DEPLOY_SECRET') ?: '');

/**
 * Parse user agent string into browser, OS, and device type.
 */
function parseUserAgent(string $ua): array {
    $browser = 'Overig';
    $os = 'Overig';
    $device = 'Desktop';

    // Browser (order matters: Edge/Opera before Chrome, Chrome before Safari)
    if (preg_match('/Edg[e\/]/i', $ua)) $browser = 'Edge';
    elseif (preg_match('/OPR|Opera/i', $ua)) $browser = 'Opera';
    elseif (preg_match('/Chrome/i', $ua) && !preg_match('/Edg/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/Safari/i', $ua) && !preg_match('/Chrome|Chromium/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/Firefox/i', $ua)) $browser = 'Firefox';

    // OS + device
    if (preg_match('/iPhone/i', $ua)) { $os = 'iOS'; $device = 'Mobiel'; }
    elseif (preg_match('/iPad/i', $ua)) { $os = 'iPadOS'; $device = 'Tablet'; }
    elseif (preg_match('/Android/i', $ua)) {
        $os = 'Android';
        $device = preg_match('/Mobile/i', $ua) ? 'Mobiel' : 'Tablet';
    }
    elseif (preg_match('/Windows/i', $ua)) $os = 'Windows';
    elseif (preg_match('/Macintosh/i', $ua)) $os = 'macOS';
    elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';

    return compact('browser', 'os', 'device');
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
