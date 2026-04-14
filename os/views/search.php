<?php
$q = trim($_GET['q'] ?? '');
$pageTitle = $q ? 'Zoek: ' . htmlspecialchars($q) : 'Zoeken';
require __DIR__ . '/layout.php';

$clients = $products = $pages = $ctas = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    try {
        $stmt = db()->prepare("SELECT id, name, email, telefoon, source_page, status FROM clients WHERE name LIKE ? OR email LIKE ? OR telefoon LIKE ? ORDER BY created_at DESC LIMIT 25");
        $stmt->execute([$like, $like, $like]);
        $clients = $stmt->fetchAll();

        $stmt = db()->prepare("SELECT id, name, slug, status FROM products WHERE name LIKE ? OR slug LIKE ? OR description LIKE ? LIMIT 25");
        $stmt->execute([$like, $like, $like]);
        $products = $stmt->fetchAll();

        $stmt = db()->prepare("SELECT slug, title, status FROM landing_pages WHERE slug LIKE ? OR title LIKE ? LIMIT 25");
        $stmt->execute([$like, $like]);
        $pages = $stmt->fetchAll();

        $stmt = db()->prepare("SELECT page_slug, action, label, COUNT(*) as n FROM tracking_conversions WHERE action LIKE ? OR label LIKE ? GROUP BY page_slug, action, label ORDER BY n DESC LIMIT 15");
        $stmt->execute([$like, $like]);
        $ctas = $stmt->fetchAll();
    } catch (PDOException $e) { /* ignore */ }
}
$total = count($clients) + count($products) + count($pages) + count($ctas);
?>

<?php if ($q === ''): ?>
    <div class="os-panel"><div class="os-panel-body"><p class="os-empty">Typ in de zoekbalk om leads, producten, pagina's en CTA-labels te doorzoeken.</p></div></div>
<?php elseif ($total === 0): ?>
    <div class="os-panel"><div class="os-panel-body"><p class="os-empty">Geen resultaten voor &ldquo;<?= htmlspecialchars($q) ?>&rdquo;.</p></div></div>
<?php else: ?>

<?php if (!empty($clients)): ?>
<div class="os-panel">
    <div class="os-panel-header"><h2>Leads (<?= count($clients) ?>)</h2></div>
    <div class="os-panel-body">
        <table class="os-table">
            <thead><tr><th>Naam</th><th>Email</th><th>Telefoon</th><th>Bron</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($clients as $c): ?>
                <tr class="os-clickable-row" onclick="location.href='<?= $p ?>/clients/<?= $c['id'] ?>'">
                    <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                    <td><?= htmlspecialchars($c['email'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($c['telefoon'] ?? '—') ?></td>
                    <td><?php if ($c['source_page']): ?><code><?= htmlspecialchars($c['source_page']) ?></code><?php else: ?>—<?php endif; ?></td>
                    <td><span class="os-badge os-badge-<?= htmlspecialchars($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($products)): ?>
<div class="os-panel">
    <div class="os-panel-header"><h2>Producten (<?= count($products) ?>)</h2></div>
    <div class="os-panel-body">
        <table class="os-table">
            <thead><tr><th>Naam</th><th>Slug</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($products as $pr): ?>
                <tr class="os-clickable-row" onclick="location.href='<?= $p ?>/products/<?= htmlspecialchars($pr['slug']) ?>'">
                    <td><strong><?= htmlspecialchars($pr['name']) ?></strong></td>
                    <td><code><?= htmlspecialchars($pr['slug']) ?></code></td>
                    <td><span class="os-badge os-badge-<?= htmlspecialchars($pr['status']) ?>"><?= htmlspecialchars($pr['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($pages)): ?>
<div class="os-panel">
    <div class="os-panel-header"><h2>Landing pages (<?= count($pages) ?>)</h2></div>
    <div class="os-panel-body">
        <table class="os-table">
            <thead><tr><th>Titel</th><th>Slug</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($pages as $pg): ?>
                <tr class="os-clickable-row" onclick="location.href='<?= $p ?>/pages/<?= htmlspecialchars($pg['slug']) ?>'">
                    <td><strong><?= htmlspecialchars($pg['title'] ?? '—') ?></strong></td>
                    <td><code>/<?= htmlspecialchars($pg['slug']) ?></code></td>
                    <td><span class="os-badge os-badge-<?= htmlspecialchars($pg['status']) ?>"><?= htmlspecialchars($pg['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($ctas)): ?>
<div class="os-panel">
    <div class="os-panel-header"><h2>CTA labels (<?= count($ctas) ?>)</h2></div>
    <div class="os-panel-body">
        <table class="os-table">
            <thead><tr><th>Pagina</th><th>Action</th><th>Label</th><th style="text-align:right">Kliks</th></tr></thead>
            <tbody>
            <?php foreach ($ctas as $cta): ?>
                <tr class="os-clickable-row" onclick="location.href='<?= $p ?>/pages/<?= htmlspecialchars($cta['page_slug']) ?>'">
                    <td><code>/<?= htmlspecialchars($cta['page_slug']) ?></code></td>
                    <td><code style="font-size:0.75rem"><?= htmlspecialchars($cta['action'] ?? '—') ?></code></td>
                    <td><?= htmlspecialchars($cta['label'] ?? '—') ?></td>
                    <td style="text-align:right"><strong><?= number_format($cta['n']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/layout-end.php'; ?>
