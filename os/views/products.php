<?php
require_once dirname(__DIR__) . '/src/config.php';

/** slug strip: leading/trailing slashes + whitespace weg, lowercase */
function normalizeSlug(string $s): string {
    return strtolower(trim($s, "/ \t\n\r\0\x0B"));
}
/** kebab-case uit een willekeurige naam */
function slugifyName(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

// ── POST: product aanmaken ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_csrf'] ?? '')) {
        http_response_code(403); echo 'CSRF token mismatch'; exit;
    }
    $name = trim($_POST['name'] ?? '');
    $slug = normalizeSlug($_POST['slug'] ?? '') ?: slugifyName($name);
    $description = trim($_POST['description'] ?? '') ?: null;
    $linkSlugs = array_values(array_filter(array_map('normalizeSlug', (array)($_POST['link_slugs'] ?? []))));

    if ($name !== '' && $slug !== '') {
        try {
            db()->beginTransaction();
            $stmt = db()->prepare("INSERT INTO products (name, slug, description) VALUES (?, ?, ?)");
            $stmt->execute([$name, $slug, $description]);
            $productId = (int) db()->lastInsertId();

            foreach ($linkSlugs as $pageSlug) {
                // Bestaat deze landing_page al? Zo niet, aanmaken.
                $exists = db()->prepare("SELECT id FROM landing_pages WHERE slug = ?");
                $exists->execute([$pageSlug]);
                $lpId = $exists->fetchColumn();
                if ($lpId) {
                    db()->prepare("UPDATE landing_pages SET product_id = ? WHERE id = ?")->execute([$productId, $lpId]);
                } else {
                    db()->prepare("INSERT INTO landing_pages (title, slug, product_id, status) VALUES (?, ?, ?, 'live')")
                        ->execute([$pageSlug, $pageSlug, $productId]);
                }
                // Backfill: clients met deze source_page krijgen nu product_id
                db()->prepare("UPDATE clients SET product_id = ? WHERE source_page = ? AND product_id IS NULL")
                    ->execute([$productId, $pageSlug]);
            }
            db()->commit();
        } catch (PDOException $e) {
            db()->rollBack();
        }
    }
    $p = defined('OS_URL_PREFIX') ? OS_URL_PREFIX : '';
    header('Location: ' . $p . '/products'); exit;
}

// ── POST: product verwijderen + data wissen ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_csrf'] ?? '')) {
        http_response_code(403); echo 'CSRF token mismatch'; exit;
    }
    $productId = (int) ($_POST['product_id'] ?? 0);
    if ($productId > 0) {
        try {
            db()->beginTransaction();
            $lp = db()->prepare("SELECT slug FROM landing_pages WHERE product_id = ?");
            $lp->execute([$productId]);
            $slugs = $lp->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($slugs)) {
                $ph = implode(',', array_fill(0, count($slugs), '?'));
                foreach ([
                    'tracking_pageviews', 'tracking_conversions', 'tracking_forms',
                    'tracking_scroll', 'tracking_time', 'tracking_video', 'tracking_form_interactions'
                ] as $t) {
                    db()->prepare("DELETE FROM `$t` WHERE page_slug IN ($ph)")->execute($slugs);
                }
                // Clients die via deze pages binnenkwamen OF aan dit product hangen
                db()->prepare("DELETE FROM clients WHERE product_id = ? OR source_page IN ($ph)")
                    ->execute(array_merge([$productId], $slugs));
                // Landing pages loskoppelen (niet verwijderen) — kunnen weer door ander product worden opgepakt
                db()->prepare("UPDATE landing_pages SET product_id = NULL WHERE product_id = ?")->execute([$productId]);
            } else {
                db()->prepare("DELETE FROM clients WHERE product_id = ?")->execute([$productId]);
            }
            db()->prepare("DELETE FROM products WHERE id = ?")->execute([$productId]);
            db()->commit();
        } catch (PDOException $e) {
            db()->rollBack();
        }
    }
    $p = defined('OS_URL_PREFIX') ? OS_URL_PREFIX : '';
    header('Location: ' . $p . '/products'); exit;
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

    foreach ($products as &$prod) {
        $slugs = db()->prepare("SELECT slug FROM landing_pages WHERE product_id = ?");
        $slugs->execute([$prod['id']]);
        $pageSlugs = $slugs->fetchAll(PDO::FETCH_COLUMN);
        $prod['page_slugs'] = $pageSlugs;

        if (!empty($pageSlugs)) {
            $placeholders = implode(',', array_fill(0, count($pageSlugs), '?'));
            $stmt = db()->prepare("SELECT COUNT(*) FROM tracking_pageviews WHERE page_slug IN ($placeholders)");
            $stmt->execute($pageSlugs);
            $prod['total_views'] = (int) $stmt->fetchColumn();

            $stmt = db()->prepare("SELECT COUNT(*) FROM tracking_conversions WHERE page_slug IN ($placeholders)");
            $stmt->execute($pageSlugs);
            $prod['total_conversions'] = (int) $stmt->fetchColumn();

            $stmt = db()->prepare("SELECT COUNT(*) FROM tracking_forms WHERE page_slug IN ($placeholders)");
            $stmt->execute($pageSlugs);
            $prod['total_forms'] = (int) $stmt->fetchColumn();
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

// Beschikbare slugs voor "pagina's koppelen": landing_pages zonder product + uit tracking_pageviews
try {
    $existingUnassigned = db()->query("SELECT slug, title FROM landing_pages WHERE product_id IS NULL ORDER BY slug")->fetchAll();
    $trackedSlugs = db()->query("
        SELECT DISTINCT page_slug as slug FROM tracking_pageviews
        WHERE page_slug != '' AND page_slug NOT IN (SELECT slug FROM landing_pages)
        ORDER BY page_slug
    ")->fetchAll();
    $availableSlugs = [];
    foreach ($existingUnassigned as $r) $availableSlugs[$r['slug']] = $r['title'] ?: $r['slug'];
    foreach ($trackedSlugs as $r) if (!isset($availableSlugs[$r['slug']])) $availableSlugs[$r['slug']] = $r['slug'] . ' (auto-detect)';
} catch (PDOException $e) {
    $availableSlugs = [];
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
        <div class="os-page-card">
            <div class="os-page-card-header">
                <h3 class="os-clickable-row" style="cursor:pointer;flex:1" onclick="location.href='<?= $p ?>/products/<?= htmlspecialchars($prod['slug']) ?>'"><?= htmlspecialchars($prod['name']) ?></h3>
                <span class="os-badge os-badge-<?= htmlspecialchars($prod['status']) ?>"><?= htmlspecialchars($prod['status']) ?></span>
                <button type="button" class="os-btn os-btn-icon os-btn-danger" title="Product verwijderen" onclick='openDeleteModal(<?= json_encode([
                    "id" => (int)$prod["id"],
                    "name" => $prod["name"],
                    "views" => (int)$prod["total_views"],
                    "conversions" => (int)$prod["total_conversions"],
                    "forms" => (int)$prod["total_forms"],
                    "leads" => (int)$prod["lead_count"],
                    "pages" => $prod["page_slugs"],
                ]) ?>)'>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                </button>
            </div>
            <div class="os-clickable-row" style="cursor:pointer" onclick="location.href='<?= $p ?>/products/<?= htmlspecialchars($prod['slug']) ?>'">
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
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Add product modal -->
<div class="os-modal" id="addProductModal">
    <div class="os-modal-backdrop" onclick="this.parentElement.classList.remove('open')"></div>
    <div class="os-modal-content">
        <h2>Product toevoegen</h2>
        <form method="POST" action="<?= $p ?>/products" id="addProductForm">
            <input type="hidden" name="_csrf" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label>Naam</label>
                <input type="text" name="name" id="productName" required placeholder="High Impact Doorbraak" autocomplete="off">
            </div>
            <div class="form-group">
                <label>Beschrijving</label>
                <textarea name="description" rows="2" placeholder="Kort over dit product..."></textarea>
            </div>
            <div class="form-group">
                <label>Pagina's koppelen</label>
                <p style="font-size:0.75rem;color:var(--os-text-muted);margin:0 0 0.5rem">Vink bestaande slugs aan, of typ een nieuwe (ook met `/` — wordt genormaliseerd).</p>
                <?php if (!empty($availableSlugs)): ?>
                    <div style="display:flex;flex-direction:column;gap:0.3rem;max-height:180px;overflow-y:auto;border:1px solid var(--os-border);padding:0.5rem;border-radius:4px;margin-bottom:0.5rem">
                        <?php foreach ($availableSlugs as $slug => $label): ?>
                            <label style="display:flex;gap:0.5rem;align-items:center;cursor:pointer;font-size:0.85rem">
                                <input type="checkbox" name="link_slugs[]" value="<?= htmlspecialchars($slug) ?>">
                                <span><code><?= htmlspecialchars($slug) ?></code> <span style="color:var(--os-text-muted)">— <?= htmlspecialchars($label) ?></span></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <input type="text" name="link_slugs[]" placeholder="Nieuwe slug (optioneel, bv. /doorbraakexclusive)" autocomplete="off">
            </div>
            <details style="margin-bottom:1rem;font-size:0.8rem;color:var(--os-text-muted)">
                <summary style="cursor:pointer">Geavanceerd — product-slug (voor URL)</summary>
                <div class="form-group" style="margin-top:0.5rem">
                    <input type="text" name="slug" id="productSlug" placeholder="auto uit naam" autocomplete="off">
                </div>
            </details>
            <div class="os-modal-actions">
                <button type="button" class="os-btn" onclick="this.closest('.os-modal').classList.remove('open')">Annuleren</button>
                <button type="submit" class="os-btn os-btn-primary">Opslaan</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete product modal -->
<div class="os-modal" id="deleteProductModal">
    <div class="os-modal-backdrop" onclick="this.parentElement.classList.remove('open')"></div>
    <div class="os-modal-content">
        <h2 style="color:#e74c3c">Product + data verwijderen</h2>
        <p>Je staat op het punt <strong id="delProdName"></strong> te verwijderen. Dit wist óók alle bijbehorende data:</p>
        <ul id="delProdStats" style="list-style:none;padding:0;margin:1rem 0;display:grid;grid-template-columns:1fr 1fr;gap:0.5rem"></ul>
        <p style="font-size:0.85rem;color:var(--os-text-muted)">Pagina's blijven bestaan maar worden losgekoppeld. Typ de productnaam om te bevestigen.</p>
        <form method="POST" action="<?= $p ?>/products" id="deleteProductForm" style="margin-top:0.75rem">
            <input type="hidden" name="_csrf" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="product_id" id="delProdId">
            <div class="form-group">
                <input type="text" id="delProdConfirm" placeholder="Typ de naam" autocomplete="off" oninput="checkDelConfirm()">
            </div>
            <div class="os-modal-actions">
                <button type="button" class="os-btn" onclick="this.closest('.os-modal').classList.remove('open')">Annuleren</button>
                <button type="submit" class="os-btn os-btn-danger" id="delProdSubmit" disabled>Definitief verwijderen</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    // Auto-slug voor product
    const nameEl = document.getElementById('productName');
    const slugEl = document.getElementById('productSlug');
    let slugTouched = false;
    if (slugEl) slugEl.addEventListener('input', () => { slugTouched = true; });
    if (nameEl) nameEl.addEventListener('input', () => {
        if (slugTouched) return;
        slugEl.value = nameEl.value.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
    });
})();

function openDeleteModal(data) {
    document.getElementById('delProdName').textContent = data.name;
    document.getElementById('delProdId').value = data.id;
    const stats = document.getElementById('delProdStats');
    const rows = [
        ['Views', data.views],
        ['Conversies', data.conversions],
        ['Form submits', data.forms],
        ['Leads', data.leads],
    ];
    stats.innerHTML = rows.map(([k,v]) => `<li style="display:flex;justify-content:space-between;padding:0.4rem 0.6rem;background:var(--os-bg-2);border-radius:4px"><span style="color:var(--os-text-muted)">${k}</span><strong>${v.toLocaleString('nl-NL')}</strong></li>`).join('');
    document.getElementById('delProdConfirm').value = '';
    document.getElementById('delProdSubmit').disabled = true;
    document.getElementById('delProdConfirm').dataset.expected = data.name;
    document.getElementById('deleteProductModal').classList.add('open');
}

function checkDelConfirm() {
    const input = document.getElementById('delProdConfirm');
    document.getElementById('delProdSubmit').disabled = input.value.trim() !== input.dataset.expected;
}
</script>

<?php require __DIR__ . '/layout-end.php'; ?>
