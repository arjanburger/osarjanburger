<?php
$pageTitle = 'Landing Pages';
require __DIR__ . '/layout.php';

try {
    $pages = db()->query("
        SELECT lp.*,
            (SELECT COUNT(*) FROM tracking_pageviews tp WHERE tp.page_slug = lp.slug) as total_views,
            (SELECT COUNT(*) FROM tracking_conversions tc WHERE tc.page_slug = lp.slug) as total_conversions,
            (SELECT COUNT(*) FROM tracking_forms tf WHERE tf.page_slug = lp.slug) as total_forms
        FROM landing_pages lp
        ORDER BY lp.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $pages = [];
}
?>

<div class="os-toolbar">
    <button class="os-btn os-btn-primary" onclick="document.getElementById('addPageModal').classList.add('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Pagina toevoegen
    </button>
</div>

<?php if (empty($pages)): ?>
    <div class="os-panel">
        <div class="os-panel-body">
            <p class="os-empty">Nog geen landing pages geregistreerd.</p>
        </div>
    </div>
<?php else: ?>
    <div class="os-pages-grid">
        <?php foreach ($pages as $page): ?>
        <div class="os-page-card">
            <div class="os-page-card-header">
                <h3><?= htmlspecialchars($page['title']) ?></h3>
                <span class="os-badge os-badge-<?= $page['status'] ?>"><?= $page['status'] ?></span>
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
                    <span class="os-page-stat-val"><?= number_format($page['total_forms']) ?></span>
                    <span class="os-page-stat-label">Leads</span>
                </div>
            </div>
            <div class="os-page-card-actions">
                <a href="<?= htmlspecialchars($page['url']) ?>" target="_blank" class="os-btn os-btn-sm">Bekijken</a>
                <a href="<?= $p ?>/analytics?page=<?= urlencode($page['slug']) ?>" class="os-btn os-btn-sm">Analytics</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Add page modal -->
<div class="os-modal" id="addPageModal">
    <div class="os-modal-backdrop" onclick="this.parentElement.classList.remove('open')"></div>
    <div class="os-modal-content">
        <h2>Landing page registreren</h2>
        <form method="POST" action="/api/pages/create">
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
                <label>Klant (optioneel)</label>
                <select name="client_id">
                    <option value="">— Geen klant —</option>
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
