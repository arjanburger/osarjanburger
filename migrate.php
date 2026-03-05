#!/usr/bin/env php
<?php
/**
 * ArjanBurger OS - Database Migratie
 *
 * Draait automatisch op dev of prod op basis van .env
 * Veilig om meerdere keren te draaien (idempotent)
 *
 * Gebruik:
 *   php migrate.php              → gebruikt .env uit projectroot
 *   php migrate.php --prod       → gebruikt .env.prod uit projectroot
 *   php migrate.php --dry-run    → toont queries zonder uit te voeren
 */

// ── CLI opties ──────────────────────────────────────────
$isProd = in_array('--prod', $argv);
$isDryRun = in_array('--dry-run', $argv);

// ── .env laden ──────────────────────────────────────────
$envFile = __DIR__ . ($isProd ? '/.env.prod' : '/.env');
if (!file_exists($envFile)) {
    die("❌ Env bestand niet gevonden: $envFile\n");
}

$envLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($envLines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;
    [$key, $val] = explode('=', $line, 2);
    putenv(trim($key) . '=' . trim($val));
}

// ── Database connectie ──────────────────────────────────
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$name = getenv('DB_NAME') ?: 'arjanburger_os';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

$env = $isProd ? 'PRODUCTIE' : 'DEV';
echo "╔══════════════════════════════════════════╗\n";
echo "║  ArjanBurger OS — Database Migratie      ║\n";
echo "╠══════════════════════════════════════════╣\n";
echo "║  Omgeving:  $env" . str_repeat(' ', 29 - strlen($env)) . "║\n";
echo "║  Database:  $name" . str_repeat(' ', 29 - strlen($name)) . "║\n";
echo "║  Host:      $host:$port" . str_repeat(' ', 29 - strlen("$host:$port")) . "║\n";
if ($isDryRun) {
echo "║  Modus:     DRY RUN (geen wijzigingen)   ║\n";
}
echo "╚══════════════════════════════════════════╝\n\n";

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "✓ Database verbinding OK\n\n";
} catch (PDOException $e) {
    die("❌ Database verbinding mislukt: " . $e->getMessage() . "\n");
}

// ── Helper functies ─────────────────────────────────────
function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
    return $stmt->rowCount() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $stmt->rowCount() > 0;
}

function indexExists(PDO $pdo, string $table, string $indexName): bool {
    $stmt = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
    return $stmt->rowCount() > 0;
}

$changes = 0;

function runMigration(PDO $pdo, string $description, string $sql, bool $isDryRun): void {
    global $changes;
    echo "  → $description\n";
    if ($isDryRun) {
        echo "    [DRY RUN] $sql\n";
    } else {
        $pdo->exec($sql);
        echo "    ✓ OK\n";
    }
    $changes++;
}

// ═══════════════════════════════════════════════════════
// MIGRATIES — voeg nieuwe migraties onderaan toe
// ═══════════════════════════════════════════════════════

echo "Controleren tabellen...\n\n";

// ── 1. os_users ─────────────────────────────────────────
if (!tableExists($pdo, 'os_users')) {
    runMigration($pdo, 'Tabel os_users aanmaken', "
        CREATE TABLE os_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ", $isDryRun);
} else {
    echo "  ✓ os_users bestaat\n";
}

// ── 2. clients ──────────────────────────────────────────
if (!tableExists($pdo, 'clients')) {
    runMigration($pdo, 'Tabel clients aanmaken', "
        CREATE TABLE clients (
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
        ) ENGINE=InnoDB
    ", $isDryRun);
} else {
    echo "  ✓ clients bestaat\n";
    // Kolom migraties
    if (!columnExists($pdo, 'clients', 'visitor_id')) {
        runMigration($pdo, 'Kolom clients.visitor_id toevoegen',
            "ALTER TABLE clients ADD COLUMN visitor_id VARCHAR(100) DEFAULT NULL", $isDryRun);
    }
    if (!columnExists($pdo, 'clients', 'source_page')) {
        runMigration($pdo, 'Kolom clients.source_page toevoegen',
            "ALTER TABLE clients ADD COLUMN source_page VARCHAR(100) DEFAULT NULL", $isDryRun);
    }
}

// ── 3. landing_pages ────────────────────────────────────
if (!tableExists($pdo, 'landing_pages')) {
    runMigration($pdo, 'Tabel landing_pages aanmaken', "
        CREATE TABLE landing_pages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(100) NOT NULL UNIQUE,
            url VARCHAR(500),
            client_id INT,
            status ENUM('draft', 'live', 'paused') NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
        ) ENGINE=InnoDB
    ", $isDryRun);
} else {
    echo "  ✓ landing_pages bestaat\n";
}

// ── 4. tracking_pageviews ───────────────────────────────
if (!tableExists($pdo, 'tracking_pageviews')) {
    runMigration($pdo, 'Tabel tracking_pageviews aanmaken', "
        CREATE TABLE tracking_pageviews (
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
        ) ENGINE=InnoDB
    ", $isDryRun);
} else {
    echo "  ✓ tracking_pageviews bestaat\n";
}

// ── 5. tracking_conversions ─────────────────────────────
if (!tableExists($pdo, 'tracking_conversions')) {
    runMigration($pdo, 'Tabel tracking_conversions aanmaken', "
        CREATE TABLE tracking_conversions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            page_slug VARCHAR(100) NOT NULL,
            visitor_id VARCHAR(100),
            action VARCHAR(100),
            label VARCHAR(255),
            url VARCHAR(500),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page_date (page_slug, created_at)
        ) ENGINE=InnoDB
    ", $isDryRun);
} else {
    echo "  ✓ tracking_conversions bestaat\n";
}

// ── 6. tracking_forms ───────────────────────────────────
if (!tableExists($pdo, 'tracking_forms')) {
    runMigration($pdo, 'Tabel tracking_forms aanmaken', "
        CREATE TABLE tracking_forms (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            page_slug VARCHAR(100) NOT NULL,
            visitor_id VARCHAR(100),
            form_id VARCHAR(100),
            fields_json JSON,
            url VARCHAR(500),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page_date (page_slug, created_at)
        ) ENGINE=InnoDB
    ", $isDryRun);
} else {
    echo "  ✓ tracking_forms bestaat\n";
}

// ── 7. tracking_scroll ──────────────────────────────────
if (!tableExists($pdo, 'tracking_scroll')) {
    runMigration($pdo, 'Tabel tracking_scroll aanmaken', "
        CREATE TABLE tracking_scroll (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            page_slug VARCHAR(100) NOT NULL,
            visitor_id VARCHAR(100),
            depth INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page_date (page_slug, created_at)
        ) ENGINE=InnoDB
    ", $isDryRun);
} else {
    echo "  ✓ tracking_scroll bestaat\n";
}

// ── 8. tracking_time ────────────────────────────────────
if (!tableExists($pdo, 'tracking_time')) {
    runMigration($pdo, 'Tabel tracking_time aanmaken', "
        CREATE TABLE tracking_time (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            page_slug VARCHAR(100) NOT NULL,
            visitor_id VARCHAR(100),
            seconds INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page_date (page_slug, created_at)
        ) ENGINE=InnoDB
    ", $isDryRun);
} else {
    echo "  ✓ tracking_time bestaat\n";
}

// ── 9. tracking_form_interactions ────────────────────────
if (!tableExists($pdo, 'tracking_form_interactions')) {
    runMigration($pdo, 'Tabel tracking_form_interactions aanmaken', "
        CREATE TABLE tracking_form_interactions (
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
        ) ENGINE=InnoDB
    ", $isDryRun);
} else {
    echo "  ✓ tracking_form_interactions bestaat\n";
}

// ── 10. tracking_video ──────────────────────────────────
if (!tableExists($pdo, 'tracking_video')) {
    runMigration($pdo, 'Tabel tracking_video aanmaken', "
        CREATE TABLE tracking_video (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            page_slug VARCHAR(100) NOT NULL,
            visitor_id VARCHAR(100),
            event VARCHAR(50) NOT NULL,
            video_id VARCHAR(50),
            seconds_watched INT DEFAULT 0,
            duration INT DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page_date (page_slug, created_at)
        ) ENGINE=InnoDB
    ", $isDryRun);
} else {
    echo "  ✓ tracking_video bestaat\n";
}

// ── 11. visitor_aliases ─────────────────────────────────
if (!tableExists($pdo, 'visitor_aliases')) {
    runMigration($pdo, 'Tabel visitor_aliases aanmaken', "
        CREATE TABLE visitor_aliases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            canonical_id VARCHAR(100) NOT NULL,
            alias_id VARCHAR(100) NOT NULL,
            source VARCHAR(50) DEFAULT 'cookie_sync',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY (alias_id),
            INDEX (canonical_id)
        ) ENGINE=InnoDB
    ", $isDryRun);
} else {
    echo "  ✓ visitor_aliases bestaat\n";
}

// ═══════════════════════════════════════════════════════
// RESULTAAT
// ═══════════════════════════════════════════════════════

echo "\n";
if ($changes === 0) {
    echo "✓ Database is up-to-date. Geen wijzigingen nodig.\n";
} else {
    echo "✓ $changes migratie(s) uitgevoerd" . ($isDryRun ? ' (DRY RUN)' : '') . ".\n";
}
echo "\n";
