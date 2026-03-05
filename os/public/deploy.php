<?php
/**
 * ArjanBurger OS - Deploy webhook
 *
 * Doet git pull + database migraties in één call.
 * URL: https://os.arjanburger.com/deploy.php?key=DEPLOY_SECRET
 *
 * Hostinger webhook triggert dit na elke push.
 * Kan ook handmatig aangeroepen worden.
 */

// ── Auth ────────────────────────────────────────────────
$root = dirname(__DIR__, 2);
$envFile = $root . '/.env';
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
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

set_time_limit(120);
header('Content-Type: application/json');

// Debug: lees mail log
if (($_GET['action'] ?? '') === 'maillog') {
    $mailLog = $root . '/mail_debug.log';
    echo json_encode(['mail_log' => file_exists($mailLog) ? file_get_contents($mailLog) : 'no log file']);
    exit;
}

// Voeg env var toe aan .env als die ontbreekt
if (($_GET['action'] ?? '') === 'addenv' && !empty($_GET['k']) && !empty($_GET['v'])) {
    $envContent = file_exists($envFile) ? file_get_contents($envFile) : '';
    $envKey = $_GET['k'];
    if (!str_contains($envContent, $envKey . '=')) {
        file_put_contents($envFile, "\n$envKey=" . $_GET['v'] . "\n", FILE_APPEND);
        echo json_encode(['status' => 'ok', 'added' => $envKey]);
    } else {
        echo json_encode(['status' => 'exists', 'key' => $envKey]);
    }
    exit;
}

$log = [];
$hasError = false;

// ── Stap 1: Git pull ────────────────────────────────────
$gitResult = shell_exec("cd $root && git pull origin main 2>&1");
$log[] = ['step' => 'git_pull', 'output' => trim($gitResult)];
if (str_contains($gitResult, 'fatal')) {
    $hasError = true;
}

// ── Stap 2: Database migraties ──────────────────────────
// Laad .env voor DB credentials
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

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_PORT') ?: '3306',
        getenv('DB_NAME') ?: 'arjanburger_os'
    );
    $pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $migrations = [];

    // ── Tabel definities ────────────────────────────────
    $tables = [
        'os_users' => "CREATE TABLE os_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",

        'clients' => "CREATE TABLE clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(255),
            phone VARCHAR(50),
            company VARCHAR(100),
            notes TEXT,
            visitor_id VARCHAR(100),
            source_page VARCHAR(100),
            status ENUM('lead', 'active', 'client', 'inactive') NOT NULL DEFAULT 'lead',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",

        'landing_pages' => "CREATE TABLE landing_pages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(100) NOT NULL UNIQUE,
            url VARCHAR(500),
            client_id INT,
            status ENUM('draft', 'live', 'paused') NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
        ) ENGINE=InnoDB",

        'tracking_pageviews' => "CREATE TABLE tracking_pageviews (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            page_slug VARCHAR(100) NOT NULL,
            visitor_id VARCHAR(100),
            url VARCHAR(500),
            referrer VARCHAR(500),
            utm_json JSON,
            screen VARCHAR(20),
            viewport VARCHAR(20),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page_date (page_slug, created_at)
        ) ENGINE=InnoDB",

        'tracking_conversions' => "CREATE TABLE tracking_conversions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            page_slug VARCHAR(100) NOT NULL,
            visitor_id VARCHAR(100),
            action VARCHAR(100),
            label VARCHAR(255),
            url VARCHAR(500),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page_date (page_slug, created_at)
        ) ENGINE=InnoDB",

        'tracking_forms' => "CREATE TABLE tracking_forms (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            page_slug VARCHAR(100) NOT NULL,
            visitor_id VARCHAR(100),
            form_id VARCHAR(100),
            fields_json JSON,
            url VARCHAR(500),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page_date (page_slug, created_at)
        ) ENGINE=InnoDB",

        'tracking_scroll' => "CREATE TABLE tracking_scroll (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            page_slug VARCHAR(100) NOT NULL,
            visitor_id VARCHAR(100),
            depth INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page_date (page_slug, created_at)
        ) ENGINE=InnoDB",

        'tracking_time' => "CREATE TABLE tracking_time (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            page_slug VARCHAR(100) NOT NULL,
            visitor_id VARCHAR(100),
            seconds INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page_date (page_slug, created_at)
        ) ENGINE=InnoDB",

        'tracking_form_interactions' => "CREATE TABLE tracking_form_interactions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            page_slug VARCHAR(100) NOT NULL,
            visitor_id VARCHAR(100),
            form_id VARCHAR(100),
            event ENUM('start', 'progress', 'abandon') NOT NULL,
            fields_json JSON,
            field_count INT DEFAULT 0,
            time_spent INT DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page_date (page_slug, created_at),
            INDEX idx_visitor_form (visitor_id, form_id)
        ) ENGINE=InnoDB",

        'tracking_video' => "CREATE TABLE tracking_video (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            page_slug VARCHAR(100) NOT NULL,
            visitor_id VARCHAR(100),
            event VARCHAR(50) NOT NULL,
            video_id VARCHAR(50),
            seconds_watched INT DEFAULT 0,
            duration INT DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page_date (page_slug, created_at)
        ) ENGINE=InnoDB",

        'visitor_aliases' => "CREATE TABLE visitor_aliases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            canonical_id VARCHAR(100) NOT NULL,
            alias_id VARCHAR(100) NOT NULL,
            source VARCHAR(50) DEFAULT 'cookie_sync',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY (alias_id),
            INDEX (canonical_id)
        ) ENGINE=InnoDB",

        'products' => "CREATE TABLE products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(100) NOT NULL UNIQUE,
            description TEXT,
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",

        'os_login_tokens' => "CREATE TABLE os_login_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(255) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            FOREIGN KEY (user_id) REFERENCES os_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB",
    ];

    // Maak ontbrekende tabellen
    foreach ($tables as $name => $sql) {
        $exists = $pdo->query("SHOW TABLES LIKE '$name'")->rowCount() > 0;
        if (!$exists) {
            $pdo->exec($sql);
            $migrations[] = "Tabel $name aangemaakt";
        }
    }

    // ── Kolom migraties ─────────────────────────────────
    $columnMigrations = [
        ['clients', 'visitor_id', "ALTER TABLE clients ADD COLUMN visitor_id VARCHAR(100) DEFAULT NULL"],
        ['clients', 'source_page', "ALTER TABLE clients ADD COLUMN source_page VARCHAR(100) DEFAULT NULL"],
        ['clients', 'product_id', "ALTER TABLE clients ADD COLUMN product_id INT DEFAULT NULL"],
        ['landing_pages', 'product_id', "ALTER TABLE landing_pages ADD COLUMN product_id INT DEFAULT NULL"],
    ];

    foreach ($columnMigrations as [$table, $column, $sql]) {
        $exists = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->rowCount() > 0;
        if (!$exists) {
            $pdo->exec($sql);
            $migrations[] = "Kolom $table.$column toegevoegd";
        }
    }

    // ── Admin user aanmaken als die niet bestaat ───────
    $adminEmail = 'arjan@burgerweb.nl';
    $exists = $pdo->prepare('SELECT id FROM os_users WHERE email = ? LIMIT 1');
    $exists->execute([$adminEmail]);
    if (!$exists->fetch()) {
        $hash = password_hash('ktv18#vp', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO os_users (name, email, password_hash) VALUES (?, ?, ?)');
        $stmt->execute(['Arjan Burger', $adminEmail, $hash]);
        $migrations[] = "Admin user $adminEmail aangemaakt";
    }

    // ── Seed data: product + landing page koppeling ────
    // High Impact Doorbraak product
    $prodExists = $pdo->query("SELECT id FROM products WHERE slug = 'hid'")->fetch();
    if (!$prodExists) {
        $pdo->exec("INSERT INTO products (name, slug, description, status) VALUES ('High Impact Doorbraak', 'hid', 'Premium coaching programma voor ondernemers', 'active')");
        $migrations[] = "Product 'High Impact Doorbraak' aangemaakt";
    }
    $hidId = $pdo->query("SELECT id FROM products WHERE slug = 'hid'")->fetchColumn();

    // Koppel doorbraak landing page aan HID product
    if ($hidId) {
        $lpExists = $pdo->query("SELECT id FROM landing_pages WHERE slug = 'doorbraak'")->fetch();
        if (!$lpExists) {
            $stmt = $pdo->prepare("INSERT INTO landing_pages (title, slug, url, product_id, status) VALUES (?, ?, ?, ?, 'live')");
            $stmt->execute(['High Impact Doorbraak', 'doorbraak', 'https://flow.arjanburger.com/doorbraak/', $hidId]);
            $migrations[] = "Landing page 'doorbraak' geregistreerd en gekoppeld aan HID";
        } else {
            // Zorg dat product_id gezet is
            $pdo->prepare("UPDATE landing_pages SET product_id = ? WHERE slug = 'doorbraak' AND product_id IS NULL")->execute([$hidId]);
        }

        // Backfill: alle bestaande clients zonder product/source koppelen aan doorbraak/HID
        $updated = $pdo->prepare("UPDATE clients SET source_page = 'doorbraak', product_id = ? WHERE product_id IS NULL");
        $updated->execute([$hidId]);
        $backfilled = $updated->rowCount();
        if ($backfilled > 0) {
            $migrations[] = "$backfilled leads gekoppeld aan doorbraak/HID product";
        }
    }

    $log[] = [
        'step' => 'database',
        'status' => 'ok',
        'migrations' => empty($migrations) ? ['Database is up-to-date'] : $migrations,
    ];

} catch (PDOException $e) {
    $hasError = true;
    $log[] = [
        'step' => 'database',
        'status' => 'error',
        'error' => $e->getMessage(),
    ];
}

// ── Resultaat ───────────────────────────────────────────
http_response_code($hasError ? 500 : 200);
echo json_encode([
    'status' => $hasError ? 'error' : 'ok',
    'deployed_at' => date('Y-m-d H:i:s'),
    'log' => $log,
], JSON_PRETTY_PRINT);
