<?php
require_once dirname(__DIR__) . '/src/config.php';

$productSlug = $routeParam ?? null;
if (!$productSlug) { http_response_code(404); echo '404'; exit; }

try {
    $stmt = db()->prepare("SELECT * FROM products WHERE slug = ?");
    $stmt->execute([$productSlug]);
    $product = $stmt->fetch();
    if (!$product) { http_response_code(404); echo 'Product niet gevonden'; exit; }
} catch (PDOException $e) {
    http_response_code(500); echo 'Database error'; exit;
}

// ── POST: pagina koppelen / ontkoppelen ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_csrf'] ?? '')) {
        http_response_code(403); echo 'CSRF mismatch'; exit;
    }
    $action = $_POST['action'];
    $pageSlug = strtolower(trim($_POST['page_slug'] ?? '', "/ \t\n\r\0\x0B"));
    $p = defined('OS_URL_PREFIX') ? OS_URL_PREFIX : '';

    if ($action === 'link_page' && $pageSlug !== '') {
        $lp = db()->prepare("SELECT id FROM landing_pages WHERE slug = ?");
        $lp->execute([$pageSlug]);
        $lpId = $lp->fetchColumn();
        if ($lpId) {
            db()->prepare("UPDATE landing_pages SET product_id = ? WHERE id = ?")->execute([$product['id'], $lpId]);
        } else {
            db()->prepare("INSERT INTO landing_pages (title, slug, product_id, status) VALUES (?, ?, ?, 'live')")
                ->execute([$pageSlug, $pageSlug, $product['id']]);
        }
        db()->prepare("UPDATE clients SET product_id = ? WHERE source_page = ? AND (product_id IS NULL OR product_id != ?)")
            ->execute([$product['id'], $pageSlug, $product['id']]);
    }
    if ($action === 'unlink_page' && $pageSlug !== '') {
        db()->prepare("UPDATE landing_pages SET product_id = NULL WHERE slug = ? AND product_id = ?")
            ->execute([$pageSlug, $product['id']]);
    }
    header('Location: ' . $p . '/products/' . rawurlencode($productSlug)); exit;
}

// Beschikbare slugs om te koppelen (ongekoppelde pages + tracking slugs)
try {
    $available = db()->query("
        SELECT slug FROM landing_pages WHERE product_id IS NULL
        UNION
        SELECT DISTINCT page_slug as slug FROM tracking_pageviews
        WHERE page_slug != '' AND page_slug NOT IN (SELECT slug FROM landing_pages)
        ORDER BY slug
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $available = [];
}

$pageTitle = htmlspecialchars($product['name']);
require __DIR__ . '/layout.php';

require_once dirname(__DIR__) . '/src/period.php';
$P = osPeriod($_GET['period'] ?? '30');
$period = $P['period']; $periodDays = $P['days']; $periodLabel = $P['label'];
$periodSql = $P['sql'];

try {
    // Landing pages van dit product
    $lpStmt = db()->prepare("SELECT * FROM landing_pages WHERE product_id = ? ORDER BY created_at DESC");
    $lpStmt->execute([$product['id']]);
    $pages = $lpStmt->fetchAll();
    $pageSlugs = array_column($pages, 'slug');

    // Stats per landing page
    foreach ($pages as &$pg) {
        $s = db()->quote($pg['slug']);
        $pg['views'] = db()->query("SELECT COUNT(*) FROM tracking_pageviews WHERE page_slug = $s AND $periodSql")->fetchColumn();
        $pg['conversions'] = db()->query("SELECT COUNT(*) FROM tracking_conversions WHERE page_slug = $s AND $periodSql")->fetchColumn();
        $pg['forms'] = db()->query("SELECT COUNT(*) FROM tracking_forms WHERE page_slug = $s AND $periodSql")->fetchColumn();
    }
    unset($pg);

    // Gecombineerde stats voor het product
    if (!empty($pageSlugs)) {
        $placeholders = implode(',', array_map(fn($s) => db()->quote($s), $pageSlugs));
        $filterSql = " AND page_slug IN ($placeholders)";
    } else {
        $filterSql = " AND 1=0"; // geen pages → geen data
    }

    $totalViews = db()->query("SELECT COUNT(*) FROM tracking_pageviews WHERE $periodSql $filterSql")->fetchColumn();
    $totalConversions = db()->query("SELECT COUNT(*) FROM tracking_conversions WHERE $periodSql $filterSql")->fetchColumn();
    $totalForms = db()->query("SELECT COUNT(*) FROM tracking_forms WHERE $periodSql $filterSql")->fetchColumn();
    // Conversie = form submit (lead). CTA-CTR = cta click-through-rate.
    $conversionRate = $totalViews > 0 ? round(($totalForms / $totalViews) * 100, 1) : 0;
    $ctaRate = $totalViews > 0 ? round(($totalConversions / $totalViews) * 100, 1) : 0;
    $avgTime = db()->query("SELECT AVG(seconds) FROM tracking_time WHERE $periodSql $filterSql")->fetchColumn();

    // Funnel
    $funnelScroll50 = db()->query("SELECT COUNT(DISTINCT visitor_id) FROM tracking_scroll WHERE depth >= 50 AND $periodSql $filterSql")->fetchColumn();
    $funnelVideoPlay = db()->query("SELECT COUNT(DISTINCT visitor_id) FROM tracking_video WHERE event = 'play' AND $periodSql $filterSql")->fetchColumn();
    $formStarts = db()->query("SELECT COUNT(*) FROM tracking_form_interactions WHERE event = 'start' AND $periodSql $filterSql")->fetchColumn();

    // Leads van dit product
    $leadsStmt = db()->prepare("SELECT * FROM clients WHERE product_id = ? ORDER BY created_at DESC");
    $leadsStmt->execute([$product['id']]);
    $leads = $leadsStmt->fetchAll();

    // Daily views
    $dailyViews = db()->query("SELECT DATE(created_at) as date, COUNT(*) as views FROM tracking_pageviews WHERE $periodSql $filterSql GROUP BY DATE(created_at) ORDER BY date")->fetchAll();
    $dailyViews = osPadDailySeries($dailyViews, $periodDays);

    // CTA performance: per (action, label) over alle gekoppelde pages
    $ctaData = db()->query("
        SELECT action, label, COUNT(*) as count, COUNT(DISTINCT visitor_id) as unique_clicks
        FROM tracking_conversions
        WHERE $periodSql $filterSql
        GROUP BY action, label
        ORDER BY count DESC
        LIMIT 20
    ")->fetchAll();
    $ctaTotal = max(array_sum(array_column($ctaData, 'count')), 1);

    // Scroll depth voor heatmap
    $scrollData = db()->query("SELECT depth, COUNT(DISTINCT visitor_id) as count FROM tracking_scroll WHERE $periodSql $filterSql GROUP BY depth")->fetchAll();
    $scrollByDepth = array_column($scrollData, 'count', 'depth');
    $scrollTotal = max((int) db()->query("SELECT COUNT(DISTINCT visitor_id) FROM tracking_pageviews WHERE $periodSql $filterSql")->fetchColumn(), 1);

} catch (PDOException $e) {
    $pages = []; $totalViews = 0; $totalConversions = 0; $totalForms = 0; $conversionRate = 0; $ctaRate = 0; $avgTime = 0;
    $ctaData = []; $ctaTotal = 1; $scrollByDepth = []; $scrollTotal = 1;
    $funnelScroll50 = 0; $funnelVideoPlay = 0; $formStarts = 0; $leads = []; $dailyViews = [];
}
?>

<!-- Header -->
<div class="os-toolbar" style="justify-content:space-between">
    <div style="display:flex;align-items:center;gap:1rem">
        <a href="<?= $p ?>/products" class="os-btn os-btn-sm">&larr; Alle producten</a>
        <h2 style="font-size:1.1rem;font-weight:700;margin:0"><?= htmlspecialchars($product['name']) ?></h2>
        <span class="os-badge os-badge-<?= htmlspecialchars($product['status']) ?>"><?= htmlspecialchars($product['status']) ?></span>
    </div>
    <div class="os-period-filter">
        <?php foreach (osPeriodOptions() as $pVal => $pLabel): ?>
            <a href="<?= $p ?>/products/<?= htmlspecialchars($productSlug) ?>?period=<?= $pVal ?>"
               class="os-period-btn <?= $period === $pVal ? 'active' : '' ?>"><?= $pLabel ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($product['description']): ?>
<p style="color:var(--os-text-muted);font-size:0.9rem;margin-bottom:1rem"><?= htmlspecialchars($product['description']) ?></p>
<?php endif; ?>

<!-- KPI Stats -->
<div class="os-stats-grid os-stats-6">
    <div class="os-stat-card">
        <div class="os-stat-label">Views</div>
        <div class="os-stat-value"><?= number_format($totalViews) ?></div>
        <div class="os-stat-sub"><?= $periodLabel ?></div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">CTA kliks</div>
        <div class="os-stat-value"><?= number_format($totalConversions) ?></div>
        <div class="os-stat-sub">CTA-CTR <?= $ctaRate ?>%</div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Form submits</div>
        <div class="os-stat-value"><?= number_format($totalForms) ?></div>
        <div class="os-stat-sub">Leads via form</div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Leads</div>
        <div class="os-stat-value"><?= number_format(count($leads)) ?></div>
        <div class="os-stat-sub">Totaal in CRM</div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Conversieratio</div>
        <div class="os-stat-value"><?= $conversionRate ?>%</div>
        <div class="os-stat-sub">Views &rarr; lead</div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Gem. tijd</div>
        <div class="os-stat-value"><?= $avgTime ? gmdate('i:s', (int)$avgTime) : '—' ?></div>
        <div class="os-stat-sub">Op pagina</div>
    </div>
</div>

<!-- Pageviews chart -->
<div class="os-panel">
    <div class="os-panel-header"><h2>Pageviews (<?= $periodLabel ?>)</h2></div>
    <div class="os-panel-body">
        <canvas id="viewsChart" height="200"></canvas>
    </div>
</div>

<div class="os-grid-2">
    <!-- Conversie funnel -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Conversie funnel</h2></div>
        <div class="os-panel-body">
            <?php
            $funnelSteps = [
                ['label' => 'Pageviews', 'value' => $totalViews, 'color' => 'var(--os-accent)'],
                ['label' => 'Scroll 50%+', 'value' => $funnelScroll50, 'color' => '#7cb5ec'],
                ['label' => 'Video play', 'value' => $funnelVideoPlay, 'color' => '#e44d4d'],
                ['label' => 'CTA click', 'value' => $totalConversions, 'color' => '#90ed7d'],
                ['label' => 'Form gestart', 'value' => $formStarts, 'color' => '#f7a35c'],
                ['label' => 'Formulier verstuurd', 'value' => $totalForms, 'color' => '#8085e9'],
            ];
            $funnelMax = max($totalViews, 1);
            foreach ($funnelSteps as $i => $step):
                $pct = round(($step['value'] / $funnelMax) * 100);
                $dropoff = $i > 0 && $funnelSteps[$i-1]['value'] > 0
                    ? round((1 - $step['value'] / $funnelSteps[$i-1]['value']) * 100) : 0;
            ?>
            <div class="os-funnel-step">
                <div class="os-funnel-label">
                    <span><?= $step['label'] ?></span>
                    <span class="os-funnel-count"><?= number_format($step['value']) ?><?php if ($i > 0 && $dropoff > 0): ?> <span class="os-funnel-drop">-<?= $dropoff ?>%</span><?php endif; ?></span>
                </div>
                <div class="os-bar-track"><div class="os-bar-fill" style="width:<?= $pct ?>%;background:<?= $step['color'] ?>"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Landing pages -->
    <div class="os-panel">
        <div class="os-panel-header" style="display:flex;justify-content:space-between;align-items:center">
            <h2>Landing pages (<?= count($pages) ?>)</h2>
            <form method="POST" action="<?= $p ?>/products/<?= htmlspecialchars($product['slug']) ?>" style="display:flex;gap:0.4rem;align-items:center">
                <input type="hidden" name="_csrf" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="link_page">
                <input type="text" name="page_slug" list="available-slugs" placeholder="slug koppelen (bv. doorbraakexclusive)" autocomplete="off" style="padding:0.4rem 0.6rem;font-size:0.85rem;border-radius:4px;border:1px solid var(--os-border);background:var(--os-surface);color:var(--os-text);min-width:260px">
                <datalist id="available-slugs">
                    <?php foreach ($available as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <button type="submit" class="os-btn os-btn-sm os-btn-primary">Koppelen</button>
            </form>
        </div>
        <div class="os-panel-body">
            <?php if (empty($pages)): ?>
                <p class="os-empty">Geen landing pages gekoppeld. Gebruik het koppelen-veld hierboven.</p>
            <?php else: ?>
                <table class="os-table">
                    <thead><tr><th>Pagina</th><th>Views</th><th>Conversies</th><th>Leads</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($pages as $pg): ?>
                        <tr>
                            <td class="os-clickable-row" onclick="location.href='<?= $p ?>/pages/<?= htmlspecialchars($pg['slug']) ?>'" style="cursor:pointer"><strong>/<?= htmlspecialchars($pg['slug']) ?></strong></td>
                            <td><?= number_format($pg['views']) ?></td>
                            <td><?= number_format($pg['conversions']) ?></td>
                            <td><?= number_format($pg['forms']) ?></td>
                            <td style="text-align:right">
                                <form method="POST" action="<?= $p ?>/products/<?= htmlspecialchars($product['slug']) ?>" onsubmit="return confirm('Pagina /<?= htmlspecialchars(addslashes($pg['slug'])) ?> ontkoppelen van dit product?')" style="display:inline">
                                    <input type="hidden" name="_csrf" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="unlink_page">
                                    <input type="hidden" name="page_slug" value="<?= htmlspecialchars($pg['slug']) ?>">
                                    <button type="submit" class="os-btn os-btn-sm" title="Ontkoppel deze pagina">Ontkoppel</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="os-grid-2">
    <!-- CTA performance -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>CTA performance</h2></div>
        <div class="os-panel-body">
            <?php if (empty($ctaData)): ?>
                <p class="os-empty">Nog geen CTA-kliks gemeten.</p>
            <?php else: ?>
                <table class="os-table">
                    <thead><tr><th>Action</th><th>Label</th><th style="text-align:right">Kliks</th><th style="text-align:right">Uniek</th><th style="text-align:right">% van tot.</th></tr></thead>
                    <tbody>
                    <?php foreach ($ctaData as $cta):
                        $pct = round(($cta['count'] / $ctaTotal) * 100, 1);
                    ?>
                        <tr>
                            <td><code style="font-size:0.75rem"><?= htmlspecialchars($cta['action'] ?? '—') ?></code></td>
                            <td><?= htmlspecialchars($cta['label'] ?? '—') ?></td>
                            <td style="text-align:right"><strong><?= number_format($cta['count']) ?></strong></td>
                            <td style="text-align:right"><?= number_format($cta['unique_clicks']) ?></td>
                            <td style="text-align:right"><?= $pct ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scroll depth heatmap -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Scroll depth heatmap</h2></div>
        <div class="os-panel-body">
            <?php
            $heat = [];
            foreach ([25, 50, 75, 100] as $d) {
                $count = $scrollByDepth[$d] ?? 0;
                $heat[$d] = ['count' => $count, 'pct' => $scrollTotal > 0 ? round(($count / $scrollTotal) * 100) : 0];
            }
            ?>
            <div style="display:flex;gap:1rem;align-items:stretch;height:240px">
                <div style="flex:0 0 70px;display:flex;flex-direction:column;justify-content:space-between;font-size:0.7rem;color:var(--os-text-muted);text-align:right;padding:0.25rem 0;font-family:var(--os-font-label)">
                    <span>0% top</span>
                    <span>25%</span>
                    <span>50%</span>
                    <span>75%</span>
                    <span>100% einde</span>
                </div>
                <div style="flex:1;display:flex;flex-direction:column;border-radius:6px;overflow:hidden;border:1px solid var(--os-border)">
                    <?php foreach ([25, 50, 75, 100] as $d):
                        $pct = $heat[$d]['pct'];
                        $hue = round($pct * 1.2); // 0=red, 120=green
                        $alpha = max(0.15, $pct / 100);
                    ?>
                    <div style="flex:1;background:hsla(<?= $hue ?>, 60%, 45%, <?= $alpha ?>);display:flex;align-items:center;justify-content:space-between;padding:0 0.75rem;color:#fff;font-weight:600;font-size:0.85rem;text-shadow:0 1px 2px rgba(0,0,0,0.4)">
                        <span>tot <?= $d ?>%</span>
                        <span><?= $pct ?>% &middot; <?= number_format($heat[$d]['count']) ?> visitors</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <p style="font-size:0.75rem;color:var(--os-text-muted);margin-top:0.75rem">% van unieke pagebezoekers die deze diepte heeft bereikt. Hoe rooder, hoe meer drop-off.</p>
        </div>
    </div>
</div>

<!-- Leads -->
<div class="os-panel">
    <div class="os-panel-header"><h2>Leads (<?= count($leads) ?>)</h2></div>
    <div class="os-panel-body">
        <?php if (empty($leads)): ?>
            <p class="os-empty">Nog geen leads voor dit product.</p>
        <?php else: ?>
            <table class="os-table">
                <thead><tr><th>Naam</th><th>Email</th><th>Bron</th><th>Status</th><th>Datum</th></tr></thead>
                <tbody>
                <?php foreach ($leads as $lead): ?>
                    <tr class="os-clickable-row" onclick="location.href='<?= $p ?>/clients/<?= $lead['id'] ?>'">
                        <td><strong><?= htmlspecialchars($lead['name']) ?></strong></td>
                        <td><?= htmlspecialchars($lead['email'] ?? '—') ?></td>
                        <td><?php if ($lead['source_page']): ?><code><?= htmlspecialchars($lead['source_page']) ?></code><?php else: ?>—<?php endif; ?></td>
                        <td><span class="os-badge os-badge-<?= htmlspecialchars($lead['status']) ?>"><?= htmlspecialchars($lead['status']) ?></span></td>
                        <td><?= date('d M Y', strtotime($lead['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
const data = <?= json_encode($dailyViews) ?>;
const canvas = document.getElementById('viewsChart');
if (canvas && data.length > 0) {
    const ctx = canvas.getContext('2d');
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * 2; canvas.height = 400; ctx.scale(2, 2);
    const w = rect.width, h = 200;
    const pad = { top: 20, right: 20, bottom: 30, left: 50 };
    const chartW = w - pad.left - pad.right, chartH = h - pad.top - pad.bottom;
    const maxVal = Math.max(...data.map(d => d.views), 1);
    ctx.strokeStyle = '#222'; ctx.lineWidth = 0.5;
    for (let i = 0; i <= 4; i++) { const y = pad.top + (chartH / 4) * i; ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(w - pad.right, y); ctx.stroke(); }
    const barW = Math.max(2, (chartW / data.length) - 2);
    data.forEach((d, i) => { const x = pad.left + (chartW / data.length) * i + 1; const barH = (d.views / maxVal) * chartH; ctx.fillStyle = '#C9A84C'; ctx.beginPath(); ctx.roundRect(x, pad.top + chartH - barH, barW, barH, 2); ctx.fill(); });
    ctx.fillStyle = '#666'; ctx.font = '10px Inter, sans-serif'; ctx.textAlign = 'right';
    for (let i = 0; i <= 4; i++) { ctx.fillText(Math.round((maxVal / 4) * (4 - i)), pad.left - 8, pad.top + (chartH / 4) * i + 4); }
    ctx.textAlign = 'center';
    const step = Math.max(1, Math.floor(data.length / 6));
    data.forEach((d, i) => { if (i % step === 0) { const x = pad.left + (chartW / data.length) * i + barW / 2; ctx.fillText(d.date.slice(5), x, h - 5); } });
}
</script>

<?php require __DIR__ . '/layout-end.php'; ?>
