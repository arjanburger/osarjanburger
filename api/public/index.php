<?php
/**
 * ArjanBurger OS - API Entry point
 * Ontvangt tracking data van engine.js + CRUD voor OS dashboard
 */

header('Content-Type: application/json');

// CORS voor engine.js (cross-origin tracking)
$allowedOrigins = ['https://flow.arjanburger.com', 'https://arjanburger.com', 'http://192.168.3.135:8093', 'https://hid.dev'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$parsedHost = parse_url($origin, PHP_URL_HOST) ?: '';
$isLocal = in_array($parsedHost, ['localhost', '127.0.0.1']) || str_starts_with($parsedHost, '192.168.');
if (in_array($origin, $allowedOrigins) || $isLocal) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once dirname(__DIR__, 2) . '/os/src/config.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Strip /api/public prefix voor lokale dev
$uri = preg_replace('#^/api(/public)?#', '', $uri);
$uri = rtrim($uri, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

// JSON body parsen
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    match (true) {
        // ── Tracking endpoints (van engine.js) ───────────
        $uri === '/track/pageview' && $method === 'POST'
            => trackPageview($input),

        $uri === '/track/conversion' && $method === 'POST'
            => trackConversion($input),

        $uri === '/track/form' && $method === 'POST'
            => trackForm($input),

        $uri === '/track/scroll' && $method === 'POST'
            => trackScroll($input),

        $uri === '/track/time' && $method === 'POST'
            => trackTime($input),

        $uri === '/track/video' && $method === 'POST'
            => trackVideo($input),

        $uri === '/track/alias' && $method === 'POST'
            => trackAlias($input),

        $uri === '/track/form-interaction' && $method === 'POST'
            => trackFormInteraction($input),

        // ── CRUD endpoints (van OS dashboard) ────────────
        $uri === '/clients/create' && $method === 'POST'
            => createClient($input),

        $uri === '/pages/create' && $method === 'POST'
            => createPage($input),

        // ── Health check ─────────────────────────────────
        $uri === '/health' => respond(['status' => 'ok', 'version' => OS_VERSION]),


        default => respond(['error' => 'Not found'], 404),
    };
} catch (PDOException $e) {
    respond(['error' => 'Database error'], 500);
}

// ── Tracking handlers ────────────────────────────────────

function trackPageview(array $data): void {
    // IP-adres: pak het echte IP achter proxy/CDN
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ip && str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip)[0]); // Eerste IP in chain
    }

    $stmt = db()->prepare("
        INSERT INTO tracking_pageviews (page_slug, visitor_id, url, referrer, utm_json, screen, viewport, user_agent, language, platform, ip_address, fingerprint, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $data['page'] ?? '',
        $data['visitor_id'] ?? '',
        $data['url'] ?? '',
        $data['referrer'] ?? null,
        isset($data['utm']) ? json_encode($data['utm']) : null,
        $data['screen'] ?? null,
        $data['viewport'] ?? null,
        $data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
        $data['language'] ?? null,
        $data['platform'] ?? null,
        $ip,
        $data['fingerprint'] ?? null,
    ]);
    respond(['ok' => true]);
}

function trackConversion(array $data): void {
    $stmt = db()->prepare("
        INSERT INTO tracking_conversions (page_slug, visitor_id, action, label, url, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $data['page'] ?? '',
        $data['visitor_id'] ?? '',
        $data['action'] ?? '',
        $data['label'] ?? '',
        $data['url'] ?? '',
    ]);
    respond(['ok' => true]);
}

function trackForm(array $data): void {
    $visitorId = $data['visitor_id'] ?? '';
    $pageSlug = $data['page'] ?? '';
    $fields = $data['fields'] ?? [];

    // Sla formulier op
    $stmt = db()->prepare("
        INSERT INTO tracking_forms (page_slug, visitor_id, form_id, fields_json, url, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $pageSlug,
        $visitorId,
        $data['form_id'] ?? 'default',
        json_encode($fields),
        $data['url'] ?? '',
    ]);

    // Auto-create of update klant op basis van email
    $email = $fields['email'] ?? $fields['e-mail'] ?? $fields['Email'] ?? null;
    $name = $fields['naam'] ?? $fields['name'] ?? $fields['Naam'] ?? $fields['Name'] ?? '';

    if ($email) {
        // Zoek product_id via landing_page
        $productId = null;
        $lpStmt = db()->prepare("SELECT product_id FROM landing_pages WHERE slug = ? AND product_id IS NOT NULL LIMIT 1");
        $lpStmt->execute([$pageSlug]);
        $productId = $lpStmt->fetchColumn() ?: null;

        $existing = db()->prepare("SELECT id, visitor_id FROM clients WHERE email = ?");
        $existing->execute([$email]);
        $client = $existing->fetch();

        if ($client) {
            // Update bestaande klant
            $upd = db()->prepare("UPDATE clients SET visitor_id = COALESCE(visitor_id, ?), source_page = COALESCE(source_page, ?), product_id = COALESCE(product_id, ?) WHERE id = ?");
            $upd->execute([$visitorId, $pageSlug, $productId, $client['id']]);
            // Als klant een ander visitor_id had, koppel als alias
            if ($client['visitor_id'] && $client['visitor_id'] !== $visitorId) {
                mergeVisitorIds($client['visitor_id'], $visitorId);
            }
        } else {
            // Nieuwe klant aanmaken
            $ins = db()->prepare("
                INSERT INTO clients (name, email, visitor_id, source_page, product_id, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'lead', NOW())
            ");
            $ins->execute([$name, $email, $visitorId, $pageSlug, $productId]);
        }
    }

    respond(['ok' => true]);
}

function trackScroll(array $data): void {
    $stmt = db()->prepare("
        INSERT INTO tracking_scroll (page_slug, visitor_id, depth, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([
        $data['page'] ?? '',
        $data['visitor_id'] ?? '',
        $data['depth'] ?? 0,
    ]);
    respond(['ok' => true]);
}

function trackTime(array $data): void {
    $stmt = db()->prepare("
        INSERT INTO tracking_time (page_slug, visitor_id, seconds, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([
        $data['page'] ?? '',
        $data['visitor_id'] ?? '',
        $data['seconds'] ?? 0,
    ]);
    respond(['ok' => true]);
}

function trackVideo(array $data): void {
    $stmt = db()->prepare("
        INSERT INTO tracking_video (page_slug, visitor_id, event, video_id, seconds_watched, duration, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $data['page'] ?? '',
        $data['visitor_id'] ?? '',
        $data['event'] ?? '',
        $data['video_id'] ?? '',
        $data['seconds_watched'] ?? 0,
        $data['duration'] ?? 0,
    ]);
    respond(['ok' => true]);
}

function trackFormInteraction(array $data): void {
    $event = $data['event'] ?? '';
    if (!in_array($event, ['start', 'progress', 'abandon'])) {
        respond(['ok' => false, 'error' => 'Invalid event']);
        return;
    }

    $stmt = db()->prepare("
        INSERT INTO tracking_form_interactions (page_slug, visitor_id, form_id, event, fields_json, field_count, time_spent, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $data['page'] ?? '',
        $data['visitor_id'] ?? '',
        $data['form_id'] ?? 'default',
        $event,
        json_encode($data['fields'] ?? []),
        $data['field_count'] ?? 0,
        $data['time_spent'] ?? 0,
    ]);
    respond(['ok' => true]);
}

function trackAlias(array $data): void {
    $canonical = $data['canonical_id'] ?? '';
    $aliases = $data['alias_ids'] ?? [];

    if (!$canonical || empty($aliases)) {
        respond(['ok' => false, 'error' => 'Missing canonical_id or alias_ids']);
        return;
    }

    foreach ($aliases as $alias) {
        if ($alias === $canonical) continue;
        mergeVisitorIds($canonical, $alias);
    }

    respond(['ok' => true]);
}

// ── Visitor merge helper ────────────────────────────────

function mergeVisitorIds(string $canonical, string $alias): void {
    // Check of alias al bestaat
    $check = db()->prepare("SELECT canonical_id FROM visitor_aliases WHERE alias_id = ?");
    $check->execute([$alias]);
    $existing = $check->fetchColumn();

    if ($existing) {
        // Al gekoppeld, skip
        return;
    }

    // Voeg alias toe
    $stmt = db()->prepare("
        INSERT IGNORE INTO visitor_aliases (canonical_id, alias_id, source, created_at)
        VALUES (?, ?, 'auto_merge', NOW())
    ");
    $stmt->execute([$canonical, $alias]);
}

// ── Resolve alle visitor IDs voor een canonical ID ──────

function resolveVisitorIds(string $visitorId): array {
    // Zoek canonical ID (kan een alias zijn)
    $stmt = db()->prepare("SELECT canonical_id FROM visitor_aliases WHERE alias_id = ?");
    $stmt->execute([$visitorId]);
    $canonical = $stmt->fetchColumn() ?: $visitorId;

    // Haal alle aliases op
    $stmt = db()->prepare("SELECT alias_id FROM visitor_aliases WHERE canonical_id = ?");
    $stmt->execute([$canonical]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Voeg canonical zelf toe
    $ids[] = $canonical;
    return array_unique($ids);
}

// ── CRUD handlers ────────────────────────────────────────

function createClient(array $data): void {
    // Redirect-based (vanuit OS form POST)
    if (!empty($_POST)) $data = $_POST;

    $stmt = db()->prepare("
        INSERT INTO clients (name, email, phone, company, notes, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'lead', NOW())
    ");
    $stmt->execute([
        $data['name'] ?? '',
        $data['email'] ?? null,
        $data['phone'] ?? null,
        $data['company'] ?? null,
        $data['notes'] ?? null,
    ]);

    if (!empty($_POST)) {
        $osPrefix = defined('OS_URL_PREFIX') ? OS_URL_PREFIX : '';
        header('Location: ' . $osPrefix . '/clients');
        exit;
    }
    respond(['ok' => true, 'id' => db()->lastInsertId()]);
}

function createPage(array $data): void {
    if (!empty($_POST)) $data = $_POST;

    $stmt = db()->prepare("
        INSERT INTO landing_pages (title, slug, url, client_id, status, created_at)
        VALUES (?, ?, ?, ?, 'draft', NOW())
    ");
    $stmt->execute([
        $data['title'] ?? '',
        $data['slug'] ?? '',
        $data['url'] ?? null,
        $data['client_id'] ?: null,
    ]);

    if (!empty($_POST)) {
        $osPrefix = defined('OS_URL_PREFIX') ? OS_URL_PREFIX : '';
        header('Location: ' . $osPrefix . '/pages');
        exit;
    }
    respond(['ok' => true, 'id' => db()->lastInsertId()]);
}

// ── Helpers ──────────────────────────────────────────────

function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
