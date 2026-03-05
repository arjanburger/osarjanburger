<?php
// Handle POST (product aanmaken) voor output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['name']) && !empty($_POST['slug'])) {
    require_once dirname(__DIR__) . '/src/config.php';
    try {
        $stmt = db()->prepare("INSERT INTO products (name, slug, description) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['name'], $_POST['slug'], $_POST['description'] ?? null]);
        $p = defined('OS_URL_PREFIX') ? OS_URL_PREFIX : '';
        header('Location: ' . $p . '/products');
        exit;
    } catch (PDOException $e) { /* slug exists */ }
}

$pageTitle = 'Producten';
require __DIR__ . '/layout.php';

try {
    $products = db()->query("
        SELECT pr.*,
            (SELECT COUNT(*) FROM landing_pages lp WHERE lp.product_id = pr.id) as page_count,
            (SELECT COUNT(*) FROM clients c WHERE c.product_id = pr.id) as lead_count
        FROM products pr
        ORDER BY pr.created_at DESC
    ")->fetchAll();

    // Stats per product (uit tracking data via landing_pages)
    foreach ($products as &$prod) {
        $slugs = db()->prepare("SELECT slug FROM landing_pages WHERE product_id = ?");
        $slugs->execute([$prod['id']]);
        $pageSlugs = $slugs->fetchAll(PDO::FETCH_COLUMN);
        $prod['page_slugs'] = $pageSlugs;

        if (!empty($pageSlugs)) {
            $placeholders = implode(',', array_fill(0, count($pageSlugs), '?'));
            $prod['total_views'] = db()->prepare("SELECT COUNT(*) FROM tracking_pageviews WHERE page_slug IN ($placeholders)")->execute($pageSlugs) ? db()->prepare("SELECT COUNT(*) FROM tracking_pageviews WHERE page_slug IN ($placeholders)") : 0;

            $stmt = db()->prepare("SELECT COUNT(*) FROM tracking_pageviews WHERE page_slug IN ($placeholders)");
            $stmt->execute($pageSlugs);
            $prod['total_views'] = $stmt->fetchColumn();

            $stmt = db()->prepare("SELECT COUNT(*) FROM tracking_conversions WHERE page_slug IN ($placeholders)");
            $stmt->execute($pageSlugs);
            $prod['total_conversions'] = $stmt->fetchColumn();

            $stmt = db()->prepare("SELECT COUNT(*) FROM tracking_forms WHERE page_slug IN ($placeholders)");
            $stmt->execute($pageSlugs);
            $prod['total_forms'] = $stmt->fetchColumn();
        } else {
            $prod['total_views'] = 0;
            $prod['total_conversions'] = 0;
            $prod['total_forms'] = 0;
        }
    }
    unset($prod);
} catch (PDOException $e) {
    $products = [];
}

// Klanten selecteren voor dropdown
try {
    $allClients = db()->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    $allClients = [];
}
?>

<div class="os-toolbar">
    <button class="os-btn os-btn-primary" onclick="document.getElementById('addProductModal').classList.add('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Product toevoegen
    </button>
</div>

<?php if (empty($products)): ?>
    <div class="os-panel">
        <div class="os-panel-body">
            <p class="os-empty">Nog geen producten. Maak een product aan en koppel er landing pages aan.</p>
        </div>
    </div>
<?php else: ?>
    <div class="os-pages-grid">
        <?php foreach ($products as $prod): ?>
        <div class="os-page-card os-clickable-row" onclick="location.href='<?= $p ?>/products/<?= htmlspecialchars($prod['slug']) ?>'">
            <div class="os-page-card-header">
                <h3><?= htmlspecialchars($prod['name']) ?></h3>
                <span class="os-badge os-badge-<?= $prod['status'] ?>"><?= $prod['status'] ?></span>
            </div>
            <?php if ($prod['description']): ?>
                <p style="font-size:0.8rem;color:var(--os-text-muted);margin:0.5rem 0"><?= htmlspecialchars(mb_strimwidth($prod['description'], 0, 80, '...')) ?></p>
            <?php endif; ?>
            <div class="os-page-card-stats">
                <div class="os-page-stat">
                    <span class="os-page-stat-val"><?= number_format($prod['total_views']) ?></span>
                    <span class="os-page-stat-label">Views</span>
                </div>
                <div class="os-page-stat">
                    <span class="os-page-stat-val"><?= number_format($prod['total_conversions']) ?></span>
                    <span class="os-page-stat-label">Conversies</span>
                </div>
                <div class="os-page-stat">
                    <span class="os-page-stat-val"><?= number_format($prod['lead_count']) ?></span>
                    <span class="os-page-stat-label">Leads</span>
                </div>
                <div class="os-page-stat">
                    <span class="os-page-stat-val"><?= $prod['page_count'] ?></span>
                    <span class="os-page-stat-label">Pagina's</span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Add product modal -->
<div class="os-modal" id="addProductModal">
    <div class="os-modal-backdrop" onclick="this.parentElement.classList.remove('open')"></div>
    <div class="os-modal-content">
        <h2>Product toevoegen</h2>
        <form method="POST" action="<?= $p ?>/products">
            <div class="form-group">
                <label>Naam</label>
                <input type="text" name="name" required placeholder="High Impact Doorbraak">
            </div>
            <div class="form-group">
                <label>Slug</label>
                <input type="text" name="slug" required placeholder="hid">
            </div>
            <div class="form-group">
                <label>Beschrijving</label>
                <textarea name="description" rows="2" placeholder="Kort over dit product..."></textarea>
            </div>
            <div class="os-modal-actions">
                <button type="button" class="os-btn" onclick="this.closest('.os-modal').classList.remove('open')">Annuleren</button>
                <button type="submit" class="os-btn os-btn-primary">Opslaan</button>
            </div>
        </form>
    </div>
</div>


<?php require __DIR__ . '/layout-end.php'; ?>
