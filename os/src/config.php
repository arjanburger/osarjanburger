<?php
/**
 * ArjanBurger OS - Configuratie
 */

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
