<?php
// Handle POST: page toevoegen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['title']) && !empty($_POST['slug'])) {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_csrf'] ?? '')) {
        http_response_code(403);
        echo 'CSRF token mismatch';
        exit;
    }
    require_once dirname(__DIR__) . '/src/config.php';
    try {
        $slug = strtolower(trim($_POST['slug'] ?? '', "/ \t\n\r\0\x0B"));
        $stmt = db()->prepare("INSERT INTO landing_pages (title, slug, url, product_id, status) VALUES (?, ?, ?, ?, 'draft')");
        $stmt->execute([$_POST['title'], $slug, $_POST['url'] ?? null, $_POST['product_id'] ?: null]);
        $p = defined('OS_URL_PREFIX') ? OS_URL_PREFIX : '';
        header('Location: ' . $p . '/pages');
        exit;
    } catch (PDOException $e) { /* slug exists */ }
}

$pageTitle = 'Landing Pages';
require __DIR__ . '/layout.php';

try {
    // Geregistreerde pages met product info
    $registeredPages = db()->query("
        SELECT lp.*, pr.name as product_name, pr.slug as product_slug,
            (SELECT COUNT(*) FROM tracking_pageviews tp WHERE tp.page_slug = lp.slug) as total_views,
            (SELECT COUNT(*) FROM tracking_conversions tc WHERE tc.page_slug = lp.slug) as total_conversions,
            (SELECT COUNT(*) FROM tracking_forms tf WHERE tf.page_slug = lp.slug) as total_forms,
            (SELECT COUNT(*) FROM clients c WHERE c.source_page = lp.slug) as lead_count
        FROM landing_pages lp
        LEFT JOIN products pr ON pr.id = lp.product_id
        ORDER BY pr.name, lp.created_at DESC
    ")->fetchAll();

    // Auto-detectie: pagina's met tracking data maar niet geregistreerd
    $registeredSlugs = array_column($registeredPages, 'slug');
    $slugPlaceholders = !empty($registeredSlugs)
        ? implode(',', array_map(fn($s) => db()->quote($s), $registeredSlugs))
        : "'__none__'";

    $unregisteredPages = db()->query("
        SELECT page_slug as slug, COUNT(*) as total_views,
            (SELECT COUNT(*) FROM tracking_conversions tc WHERE tc.page_slug = tp.page_slug) as total_conversions,
            (SELECT COUNT(*) FROM tracking_forms tf WHERE tf.page_slug = tp.page_slug) as total_forms,
            (SELECT COUNT(*) FROM clients c WHERE c.source_page = tp.page_slug) as lead_count
        FROM tracking_pageviews tp
        WHERE page_slug NOT IN ($slugPlaceholders) AND page_slug != ''
        GROUP BY page_slug
        ORDER BY total_views DESC
    ")->fetchAll();

    // Producten voor dropdown
    $products = db()->query("SELECT id, name FROM products ORDER BY name")->fetchAll();

} catch (PDOException $e) {
    $registeredPages = []; $unregisteredPages = []; $products = [];
}

// Groepeer geregistreerde pages per product
$grouped = [];
foreach ($registeredPages as $pg) {
    $key = $pg['product_name'] ?? '__none__';
    $grouped[$key][] = $pg;
}
?>

<div class="os-toolbar">
    <button class="os-btn os-btn-primary" onclick="document.getElementById('addPageModal').classList.add('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Pagina registreren
    </button>
</div>

<?php if (empty($registeredPages) && empty($unregisteredPages)): ?>
    <div class="os-panel">
        <div class="os-panel-body">
            <p class="os-empty">Nog geen landing pages. Registreer een pagina of wacht tot er tracking data binnenkomt.</p>
        </div>
    </div>
<?php else: ?>

    <?php foreach ($grouped as $productName => $pages): ?>
        <?php if ($productName !== '__none__'): ?>
            <div style="display:flex;align-items:center;gap:0.75rem;margin:1.5rem 0 0.75rem">
                <a href="<?= $p ?>/products/<?= htmlspecialchars($pages[0]['product_slug']) ?>" style="font-weight:700;font-size:1rem;color:var(--os-accent);text-decoration:none"><?= htmlspecialchars($productName) ?></a>
            </div>
        <?php elseif (count($grouped) > 1): ?>
            <div style="margin:1.5rem 0 0.75rem;font-weight:600;font-size:0.85rem;color:var(--os-text-muted);text-transform:uppercase;letter-spacing:0.05em">Niet gekoppeld</div>
        <?php endif; ?>

        <div class="os-pages-grid">
            <?php foreach ($pages as $page): ?>
            <div class="os-page-card os-clickable-row" onclick="location.href='<?= $p ?>/pages/<?= htmlspecialchars($page['slug']) ?>'">
                <div class="os-page-card-header">
                    <h3><?= htmlspecialchars($page['title']) ?></h3>
                    <span class="os-badge os-badge-<?= htmlspecialchars($page['status']) ?>"><?= htmlspecialchars($page['status']) ?></span>
                </div>
                <div class="os-page-card-slug">/<?= htmlspecialchars($page['slug']) ?></div>
                <div class="os-page-card-stats">
                    <div class="os-page-stat">
                        <span class="os-page-stat-val"><?= number_format($page['total_views']) ?></span>
                        <span class="os-page-stat-label">Views</span>
                    </div>
                    <div class="os-page-stat">
                        <span class="os-page-stat-val"><?= number_format($page['total_conversions']) ?></span>
                        <span class="os-page-stat-label">Conversies</span>
                    </div>
                    <div class="os-page-stat">
                        <span class="os-page-stat-val"><?= number_format($page['lead_count']) ?></span>
                        <span class="os-page-stat-label">Leads</span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <?php if (!empty($unregisteredPages)): ?>
        <div style="margin:1.5rem 0 0.75rem;font-weight:600;font-size:0.85rem;color:var(--os-text-muted);text-transform:uppercase;letter-spacing:0.05em">Niet-geregistreerd (auto-detectie)</div>
        <div class="os-pages-grid">
            <?php foreach ($unregisteredPages as $page): ?>
            <div class="os-page-card os-clickable-row" onclick="location.href='<?= $p ?>/pages/<?= htmlspecialchars($page['slug']) ?>'">
                <div class="os-page-card-header">
                    <h3>/<?= htmlspecialchars($page['slug']) ?></h3>
                </div>
                <div class="os-page-card-stats">
                    <div class="os-page-stat">
                        <span class="os-page-stat-val"><?= number_format($page['total_views']) ?></span>
                        <span class="os-page-stat-label">Views</span>
                    </div>
                    <div class="os-page-stat">
                        <span class="os-page-stat-val"><?= number_format($page['total_conversions']) ?></span>
                        <span class="os-page-stat-label">Conversies</span>
                    </div>
                    <div class="os-page-stat">
                        <span class="os-page-stat-val"><?= number_format($page['lead_count']) ?></span>
                        <span class="os-page-stat-label">Leads</span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Add page modal -->
<div class="os-modal" id="addPageModal">
    <div class="os-modal-backdrop" onclick="this.parentElement.classList.remove('open')"></div>
    <div class="os-modal-content">
        <h2>Landing page registreren</h2>
        <form method="POST" action="<?= $p ?>/pages">
            <input type="hidden" name="_csrf" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="form-group">
                <label>Titel</label>
                <input type="text" name="title" required placeholder="High Impact Doorbraak">
            </div>
            <div class="form-group">
                <label>Slug</label>
                <input type="text" name="slug" required placeholder="doorbraak">
            </div>
            <div class="form-group">
                <label>URL</label>
                <input type="url" name="url" placeholder="https://flow.arjanburger.com/doorbraak/">
            </div>
            <div class="form-group">
                <label>Product</label>
                <select name="product_id">
                    <option value="">— Geen product —</option>
                    <?php foreach ($products as $prod): ?>
                        <option value="<?= $prod['id'] ?>"><?= htmlspecialchars($prod['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="os-modal-actions">
                <button type="button" class="os-btn" onclick="this.closest('.os-modal').classList.remove('open')">Annuleren</button>
                <button type="submit" class="os-btn os-btn-primary">Registreren</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/layout-end.php'; ?>
