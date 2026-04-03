<?php
$pageTitle = 'Dashboard';
require __DIR__ . '/layout.php';

try {
    // Vandaag stats
    $stats = [
        'pageviews_today' => db()->query("SELECT COUNT(*) FROM tracking_pageviews WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
        'conversions_today' => db()->query("SELECT COUNT(*) FROM tracking_conversions WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
        'forms_today' => db()->query("SELECT COUNT(*) FROM tracking_forms WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
        'total_clients' => db()->query("SELECT COUNT(*) FROM clients")->fetchColumn(),
    ];

    // Gisteren vergelijking
    $yesterdayViews = db()->query("SELECT COUNT(*) FROM tracking_pageviews WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetchColumn();
    $viewsTrend = $yesterdayViews > 0 ? round((($stats['pageviews_today'] - $yesterdayViews) / $yesterdayViews) * 100) : 0;

    // Gem. scroll depth vandaag
    $avgScroll = db()->query("SELECT AVG(depth) FROM tracking_scroll WHERE DATE(created_at) = CURDATE()")->fetchColumn();

    // Gem. tijd vandaag
    $avgTime = db()->query("SELECT AVG(seconds) FROM tracking_time WHERE DATE(created_at) = CURDATE()")->fetchColumn();

    // Mini-funnel (vandaag)
    $fViews = $stats['pageviews_today'];
    $fScroll = db()->query("SELECT COUNT(DISTINCT visitor_id) FROM tracking_scroll WHERE depth >= 50 AND DATE(created_at) = CURDATE()")->fetchColumn();
    $fCta = $stats['conversions_today'];
    $fForm = $stats['forms_today'];

    // Sparkline data (laatste 7 dagen pageviews)
    $sparkline = db()->query("
        SELECT DATE(created_at) as date, COUNT(*) as views
        FROM tracking_pageviews
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at) ORDER BY date
    ")->fetchAll();

    // Recente activiteit (mix van alles)
    $recentActivity = db()->query("
        (SELECT 'pageview' as type, page_slug, visitor_id, url as detail, created_at FROM tracking_pageviews ORDER BY created_at DESC LIMIT 5)
        UNION ALL
        (SELECT 'conversion' as type, page_slug, visitor_id, CONCAT(action, ': ', label) as detail, created_at FROM tracking_conversions ORDER BY created_at DESC LIMIT 5)
        UNION ALL
        (SELECT 'form' as type, page_slug, visitor_id, form_id as detail, created_at FROM tracking_forms ORDER BY created_at DESC LIMIT 5)
        ORDER BY created_at DESC LIMIT 10
    ")->fetchAll();

    // Recente formulieren
    $recentForms = db()->query("
        SELECT tf.*, lp.title as page_title
        FROM tracking_forms tf
        LEFT JOIN landing_pages lp ON tf.page_slug = lp.slug
        ORDER BY tf.created_at DESC LIMIT 5
    ")->fetchAll();

} catch (PDOException $e) {
    $stats = ['pageviews_today' => 0, 'conversions_today' => 0, 'forms_today' => 0, 'total_clients' => 0];
    $viewsTrend = 0; $avgScroll = 0; $avgTime = 0;
    $fViews = 0; $fScroll = 0; $fCta = 0; $fForm = 0;
    $sparkline = []; $recentActivity = []; $recentForms = [];
}
?>

<!-- Stat cards -->
<div class="os-stats-grid os-stats-6">
    <div class="os-stat-card">
        <div class="os-stat-label">Pageviews</div>
        <div class="os-stat-value"><?= number_format($stats['pageviews_today']) ?></div>
        <div class="os-stat-sub">
            vandaag
            <?php if ($viewsTrend !== 0): ?>
                <span class="os-trend os-trend-<?= $viewsTrend >= 0 ? 'up' : 'down' ?>"><?= $viewsTrend > 0 ? '+' : '' ?><?= $viewsTrend ?>%</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Conversies</div>
        <div class="os-stat-value"><?= number_format($stats['conversions_today']) ?></div>
        <div class="os-stat-sub">CTA clicks vandaag</div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Leads</div>
        <div class="os-stat-value"><?= number_format($stats['forms_today']) ?></div>
        <div class="os-stat-sub">Formulieren vandaag</div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Gem. scroll</div>
        <div class="os-stat-value"><?= $avgScroll ? round($avgScroll) . '%' : '—' ?></div>
        <div class="os-stat-sub">Depth vandaag</div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Gem. tijd</div>
        <div class="os-stat-value"><?= $avgTime ? gmdate('i:s', (int)$avgTime) : '—' ?></div>
        <div class="os-stat-sub">Op pagina vandaag</div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Klanten</div>
        <div class="os-stat-value"><?= number_format($stats['total_clients']) ?></div>
        <div class="os-stat-sub">Totaal</div>
    </div>
</div>

<div class="os-grid-2">
    <!-- Mini conversie funnel -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Funnel vandaag</h2></div>
        <div class="os-panel-body">
            <?php
            $funnelMax = max($fViews, 1);
            $funnelSteps = [
                ['label' => 'Pageviews', 'value' => $fViews, 'color' => 'var(--os-accent)'],
                ['label' => 'Scroll 50%+', 'value' => $fScroll, 'color' => '#7cb5ec'],
                ['label' => 'CTA click', 'value' => $fCta, 'color' => '#90ed7d'],
                ['label' => 'Formulier', 'value' => $fForm, 'color' => '#f7a35c'],
            ];
            foreach ($funnelSteps as $step):
                $pct = round(($step['value'] / $funnelMax) * 100);
            ?>
            <div class="os-funnel-step">
                <div class="os-funnel-label">
                    <span><?= $step['label'] ?></span>
                    <span class="os-funnel-count"><?= number_format($step['value']) ?></span>
                </div>
                <div class="os-bar-track">
                    <div class="os-bar-fill" style="width:<?= $pct ?>%;background:<?= $step['color'] ?>"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Recente activiteit feed -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Live activiteit</h2></div>
        <div class="os-panel-body">
            <?php if (empty($recentActivity)): ?>
                <p class="os-empty">Nog geen activiteit.</p>
            <?php else: ?>
                <div class="os-activity-feed">
                <?php foreach ($recentActivity as $act):
                    $typeMap = ['pageview' => ['icon' => 'eye', 'label' => 'View', 'color' => 'var(--os-accent)'], 'conversion' => ['icon' => 'zap', 'label' => 'CTA', 'color' => '#90ed7d'], 'form' => ['icon' => 'mail', 'label' => 'Lead', 'color' => '#f7a35c']];
                    $t = $typeMap[$act['type']] ?? $typeMap['pageview'];
                    $ago = time() - strtotime($act['created_at']);
                    if ($ago < 60) $agoText = $ago . 's geleden';
                    elseif ($ago < 3600) $agoText = floor($ago/60) . 'min geleden';
                    elseif ($ago < 86400) $agoText = floor($ago/3600) . 'u geleden';
                    else $agoText = date('d M H:i', strtotime($act['created_at']));
                ?>
                    <div class="os-activity-item">
                        <div class="os-activity-dot" style="background:<?= $t['color'] ?>"></div>
                        <div class="os-activity-content">
                            <span class="os-activity-type"><?= $t['label'] ?></span>
                            <span class="os-activity-detail">/<?= htmlspecialchars($act['page_slug']) ?></span>
                            <span class="os-activity-time"><?= $agoText ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Sparkline + recente aanmeldingen -->
<div class="os-grid-2">
    <div class="os-panel">
        <div class="os-panel-header"><h2>Pageviews (7 dagen)</h2></div>
        <div class="os-panel-body">
            <canvas id="sparkChart" height="100"></canvas>
        </div>
    </div>

    <div class="os-panel">
        <div class="os-panel-header"><h2>Recente aanmeldingen</h2></div>
        <div class="os-panel-body">
            <?php if (empty($recentForms)): ?>
                <p class="os-empty">Nog geen aanmeldingen ontvangen.</p>
            <?php else: ?>
                <table class="os-table">
                    <thead><tr><th>Wanneer</th><th>Pagina</th><th>Naam</th><th>Email</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentForms as $form):
                        $fields = json_decode($form['fields_json'], true) ?? [];
                    ?>
                        <tr>
                            <td><?= date('d M H:i', strtotime($form['created_at'])) ?></td>
                            <td><?= htmlspecialchars($form['page_title'] ?? $form['page_slug']) ?></td>
                            <td><?= htmlspecialchars($fields['naam'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($fields['email'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Sparkline chart
const spark = <?= json_encode($sparkline) ?>;
const sc = document.getElementById('sparkChart');
if (sc && spark.length > 0) {
    const ctx = sc.getContext('2d');
    const rect = sc.getBoundingClientRect();
    sc.width = rect.width * 2; sc.height = 200; ctx.scale(2, 2);
    const w = rect.width, h = 100;
    const pad = { top: 10, right: 10, bottom: 20, left: 40 };
    const cW = w - pad.left - pad.right, cH = h - pad.top - pad.bottom;
    const max = Math.max(...spark.map(d => d.views), 1);

    // Area
    ctx.beginPath();
    ctx.moveTo(pad.left, pad.top + cH);
    spark.forEach((d, i) => {
        const x = pad.left + (cW / (spark.length - 1 || 1)) * i;
        const y = pad.top + cH - (d.views / max) * cH;
        ctx.lineTo(x, y);
    });
    ctx.lineTo(pad.left + cW, pad.top + cH);
    ctx.closePath();
    ctx.fillStyle = 'rgba(200, 165, 92, 0.15)';
    ctx.fill();

    // Line
    ctx.beginPath();
    spark.forEach((d, i) => {
        const x = pad.left + (cW / (spark.length - 1 || 1)) * i;
        const y = pad.top + cH - (d.views / max) * cH;
        i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
    });
    ctx.strokeStyle = '#C9A84C'; ctx.lineWidth = 2; ctx.stroke();

    // Dots
    spark.forEach((d, i) => {
        const x = pad.left + (cW / (spark.length - 1 || 1)) * i;
        const y = pad.top + cH - (d.views / max) * cH;
        ctx.beginPath(); ctx.arc(x, y, 3, 0, Math.PI * 2);
        ctx.fillStyle = '#C9A84C'; ctx.fill();
    });

    // Labels
    ctx.fillStyle = '#666'; ctx.font = '10px Inter'; ctx.textAlign = 'center';
    spark.forEach((d, i) => {
        const x = pad.left + (cW / (spark.length - 1 || 1)) * i;
        ctx.fillText(d.date.slice(5), x, h - 3);
    });
}
</script>

<?php require __DIR__ . '/layout-end.php'; ?>
