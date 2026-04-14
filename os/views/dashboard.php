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

    // Mini-funnel (vandaag) — visitors per stap (uniek)
    $fViews = (int) db()->query("SELECT COUNT(DISTINCT visitor_id) FROM tracking_pageviews WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $fScroll = (int) db()->query("SELECT COUNT(DISTINCT visitor_id) FROM tracking_scroll WHERE depth >= 50 AND DATE(created_at) = CURDATE()")->fetchColumn();
    $fVideoPlay = (int) db()->query("SELECT COUNT(DISTINCT visitor_id) FROM tracking_video WHERE event = 'play' AND DATE(created_at) = CURDATE()")->fetchColumn();
    $fVideoHalf = (int) db()->query("SELECT COUNT(DISTINCT visitor_id) FROM tracking_video WHERE duration > 0 AND seconds_watched * 2 >= duration AND DATE(created_at) = CURDATE()")->fetchColumn();
    $fCta = (int) db()->query("SELECT COUNT(DISTINCT visitor_id) FROM tracking_conversions WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $fForm = (int) db()->query("SELECT COUNT(DISTINCT visitor_id) FROM tracking_forms WHERE DATE(created_at) = CURDATE()")->fetchColumn();

    // Sparklines (laatste 7 dagen per metric, gevuld zodat gaten 0 worden)
    $fillDays = function(array $rows) {
        $byDate = array_column($rows, 'n', 'date');
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i day"));
            $out[] = (int) ($byDate[$d] ?? 0);
        }
        return $out;
    };
    $sparkViews = $fillDays(db()->query("SELECT DATE(created_at) as date, COUNT(*) as n FROM tracking_pageviews WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at)")->fetchAll());
    $sparkCta   = $fillDays(db()->query("SELECT DATE(created_at) as date, COUNT(*) as n FROM tracking_conversions WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at)")->fetchAll());
    $sparkForms = $fillDays(db()->query("SELECT DATE(created_at) as date, COUNT(*) as n FROM tracking_forms WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at)")->fetchAll());
    // 7d chart op dashboard verwacht {date, views} objects
    require_once dirname(__DIR__) . '/src/period.php';
    $sparkline = osPadDailySeries(
        db()->query("SELECT DATE(created_at) as date, COUNT(*) as views FROM tracking_pageviews WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at)")->fetchAll(),
        7
    );

    // Recente activiteit (mix van alle event-types)
    $recentActivity = db()->query("
        (SELECT 'pageview' as type, page_slug, visitor_id, url as detail, created_at FROM tracking_pageviews ORDER BY created_at DESC LIMIT 8)
        UNION ALL
        (SELECT 'conversion' as type, page_slug, visitor_id, CONCAT(COALESCE(action,'cta'), ': ', COALESCE(label,'-')) as detail, created_at FROM tracking_conversions ORDER BY created_at DESC LIMIT 8)
        UNION ALL
        (SELECT 'form' as type, page_slug, visitor_id, COALESCE(form_id,'form') as detail, created_at FROM tracking_forms ORDER BY created_at DESC LIMIT 8)
        UNION ALL
        (SELECT 'scroll' as type, page_slug, visitor_id, CONCAT('depth ', depth, '%') as detail, created_at FROM tracking_scroll ORDER BY created_at DESC LIMIT 8)
        UNION ALL
        (SELECT 'form_int' as type, page_slug, visitor_id, CONCAT(event, ' (', field_count, ' velden)') as detail, created_at FROM tracking_form_interactions ORDER BY created_at DESC LIMIT 8)
        UNION ALL
        (SELECT 'video' as type, page_slug, visitor_id, CONCAT(event, ' @', COALESCE(seconds_watched,0), 's') as detail, created_at FROM tracking_video ORDER BY created_at DESC LIMIT 8)
        ORDER BY created_at DESC LIMIT 15
    ")->fetchAll();

    // Recente formulieren — koppel ook aan client (via visitor_id, eventueel via aliases)
    $recentForms = db()->query("
        SELECT tf.*, lp.title as page_title,
            (SELECT c.id FROM clients c WHERE c.visitor_id = tf.visitor_id
                OR c.visitor_id IN (SELECT alias_id FROM visitor_aliases WHERE canonical_id = tf.visitor_id)
                OR c.visitor_id IN (SELECT canonical_id FROM visitor_aliases WHERE alias_id = tf.visitor_id)
                LIMIT 1) as client_id
        FROM tracking_forms tf
        LEFT JOIN landing_pages lp ON tf.page_slug = lp.slug
        ORDER BY tf.created_at DESC LIMIT 5
    ")->fetchAll();

} catch (PDOException $e) {
    $stats = ['pageviews_today' => 0, 'conversions_today' => 0, 'forms_today' => 0, 'total_clients' => 0];
    $viewsTrend = 0; $avgScroll = 0; $avgTime = 0;
    $fViews = 0; $fScroll = 0; $fVideoPlay = 0; $fVideoHalf = 0; $fCta = 0; $fForm = 0;
    $sparkline = []; $sparkViews = []; $sparkCta = []; $sparkForms = []; $recentActivity = []; $recentForms = [];
}

// Inline SVG sparkline helper (7 punten → polyline)
function spark(array $data, string $color = 'var(--os-accent)'): string {
    if (empty($data) || max($data) == 0) return '';
    $max = max($data); $min = 0;
    $w = 60; $h = 18;
    $pts = [];
    foreach ($data as $i => $v) {
        $x = ($i / (count($data) - 1)) * $w;
        $y = $h - (($v - $min) / ($max - $min ?: 1)) * $h;
        $pts[] = round($x, 1) . ',' . round($y, 1);
    }
    $poly = implode(' ', $pts);
    return '<svg class="os-spark" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" style="vertical-align:middle;margin-left:0.4rem"><polyline fill="none" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round" points="' . $poly . '"/></svg>';
}
?>

<!-- Stat cards -->
<div class="os-stats-grid os-stats-6">
    <div class="os-stat-card">
        <div class="os-stat-label">Pageviews<?= spark($sparkViews) ?></div>
        <div class="os-stat-value"><?= number_format($stats['pageviews_today']) ?></div>
        <div class="os-stat-sub">
            vandaag
            <?php if ($viewsTrend !== 0): ?>
                <span class="os-trend os-trend-<?= $viewsTrend >= 0 ? 'up' : 'down' ?>" title="Gisteren: <?= number_format($yesterdayViews) ?> views"><?= $viewsTrend > 0 ? '+' : '' ?><?= $viewsTrend ?>% vs gisteren</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">CTA kliks<?= spark($sparkCta, '#90ed7d') ?></div>
        <div class="os-stat-value"><?= number_format($stats['conversions_today']) ?></div>
        <div class="os-stat-sub">vandaag</div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Leads<?= spark($sparkForms, '#f7a35c') ?></div>
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
    <!-- Visuele conversie funnel -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Funnel vandaag</h2></div>
        <div class="os-panel-body">
            <?php
            $funnelSteps = [
                ['label' => 'Pageviews', 'value' => $fViews, 'fill' => '#C9A84C', 'fill2' => '#A8893A'],
                ['label' => 'Scroll 50%+', 'value' => $fScroll, 'fill' => '#5B9FCC', 'fill2' => '#4A86AD'],
                ['label' => 'Video play', 'value' => $fVideoPlay, 'fill' => '#FF6240', 'fill2' => '#D14E32'],
                ['label' => 'Video 50%+ bekeken', 'value' => $fVideoHalf, 'fill' => '#E8A53D', 'fill2' => '#C28930'],
                ['label' => 'CTA click', 'value' => $fCta, 'fill' => '#6AB06A', 'fill2' => '#569156'],
                ['label' => 'Formulier', 'value' => $fForm, 'fill' => '#D4845C', 'fill2' => '#B8704D'],
            ];
            $n = count($funnelSteps);
            $svgW = 520;
            $svgH = 90 * $n;   // 90px per tier ipv 50 → groter
            $tierH = $svgH / $n;
            // Eindigt in een echt punt: width(0)=svgW, width(n)=0
            $widths = [];
            for ($i = 0; $i <= $n; $i++) {
                $widths[] = $svgW * (1 - $i / $n);
            }
            ?>
            <svg class="os-funnel-svg" viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" preserveAspectRatio="xMidYMid meet">
                <defs>
                    <?php foreach ($funnelSteps as $i => $step): ?>
                    <linearGradient id="fg<?= $i ?>" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="<?= $step['fill'] ?>"/>
                        <stop offset="100%" stop-color="<?= $step['fill2'] ?>"/>
                    </linearGradient>
                    <?php endforeach; ?>
                </defs>
                <?php foreach ($funnelSteps as $i => $step):
                    $y1 = $i * $tierH;
                    $y2 = ($i + 1) * $tierH;
                    $w1 = $widths[$i];
                    $w2 = $widths[$i + 1];
                    $x1L = ($svgW - $w1) / 2;
                    $x1R = $x1L + $w1;
                    $x2L = ($svgW - $w2) / 2;
                    $x2R = $x2L + $w2;
                    $cy = $y1 + $tierH / 2;
                    $prevValue = $i > 0 ? $funnelSteps[$i - 1]['value'] : null;
                    $dropPct = ($prevValue && $prevValue > 0) ? round((1 - $step['value'] / $prevValue) * 100) : null;
                ?>
                <polygon points="<?= "$x1L,$y1 $x1R,$y1 $x2R,$y2 $x2L,$y2" ?>" fill="url(#fg<?= $i ?>)" class="os-funnel-poly"/>
                <text x="<?= $svgW / 2 ?>" y="<?= $cy - 6 ?>" text-anchor="middle" class="os-funnel-svg-label"><?= $step['label'] ?></text>
                <text x="<?= $svgW / 2 ?>" y="<?= $cy + 14 ?>" text-anchor="middle" class="os-funnel-svg-value"><?= number_format($step['value']) ?><?php if ($dropPct !== null && $dropPct > 0): ?><tspan class="os-funnel-svg-drop"> -<?= $dropPct ?>%</tspan><?php endif; ?></text>
                <?php endforeach; ?>
            </svg>
        </div>
    </div>

    <!-- Recente aanmeldingen -->
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
                        $clickAttr = !empty($form['client_id']) ? "onclick=\"location.href='{$p}/clients/{$form['client_id']}'\" style=\"cursor:pointer\"" : '';
                        $rowClass = !empty($form['client_id']) ? 'os-clickable-row' : '';
                    ?>
                        <tr class="<?= $rowClass ?>" <?= $clickAttr ?>>
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

<!-- Sparkline + live activiteit -->
<div class="os-grid-2">
    <div class="os-panel">
        <div class="os-panel-header"><h2>Pageviews (7 dagen)</h2></div>
        <div class="os-panel-body">
            <canvas id="sparkChart" height="100"></canvas>
        </div>
    </div>

    <div class="os-panel">
        <div class="os-panel-header"><h2>Live activiteit</h2></div>
        <div class="os-panel-body">
            <?php if (empty($recentActivity)): ?>
                <p class="os-empty">Nog geen activiteit.</p>
            <?php else: ?>
                <div class="os-activity-feed">
                <?php foreach ($recentActivity as $act):
                    $typeMap = [
                        'pageview' => ['label' => 'View', 'color' => 'var(--os-accent)'],
                        'conversion' => ['label' => 'CTA', 'color' => '#90ed7d'],
                        'form' => ['label' => 'Lead', 'color' => '#f7a35c'],
                        'scroll' => ['label' => 'Scroll', 'color' => '#5b9fcc'],
                        'form_int' => ['label' => 'Form', 'color' => '#b07ad4'],
                        'video' => ['label' => 'Video', 'color' => '#ff6240'],
                    ];
                    $t = $typeMap[$act['type']] ?? $typeMap['pageview'];
                    $ago = time() - strtotime($act['created_at']);
                    if ($ago < 60) $agoText = $ago . 's geleden';
                    elseif ($ago < 3600) $agoText = floor($ago/60) . 'min geleden';
                    elseif ($ago < 86400) $agoText = floor($ago/3600) . 'u geleden';
                    else $agoText = date('d M H:i', strtotime($act['created_at']));
                ?>
                    <?php
                        // Vind client voor deze visitor (incl aliases)
                        $cid = null;
                        if (!empty($act['visitor_id'])) {
                            $cs = db()->prepare("SELECT id FROM clients WHERE visitor_id = ? OR visitor_id IN (SELECT alias_id FROM visitor_aliases WHERE canonical_id = ?) OR visitor_id IN (SELECT canonical_id FROM visitor_aliases WHERE alias_id = ?) LIMIT 1");
                            $cs->execute([$act['visitor_id'], $act['visitor_id'], $act['visitor_id']]);
                            $cid = $cs->fetchColumn() ?: null;
                        }
                        $href = $cid ? "{$p}/clients/{$cid}" : "{$p}/pages/" . urlencode($act['page_slug']);
                    ?>
                    <a class="os-activity-item" href="<?= htmlspecialchars($href) ?>" style="text-decoration:none;color:inherit;display:flex">
                        <div class="os-activity-dot" style="background:<?= $t['color'] ?>"></div>
                        <div class="os-activity-content">
                            <span class="os-activity-type"><?= $t['label'] ?></span>
                            <span class="os-activity-detail">/<?= htmlspecialchars($act['page_slug']) ?>
                                <?php if ($cid): ?><span style="color:var(--os-accent);font-size:0.7rem;margin-left:0.3rem">→ lead</span><?php endif; ?>
                                <?php if (!empty($act['detail']) && $act['type'] !== 'pageview'): ?>
                                    <span style="color:var(--os-text-muted);font-size:0.78rem">— <?= htmlspecialchars(mb_strimwidth((string)$act['detail'], 0, 60, '...')) ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="os-activity-time"><?= $agoText ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
                </div>
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
