<?php
$slug = $routeParam ?? null;
if (!$slug) { http_response_code(404); echo '404'; exit; }

$pageTitle = '/' . htmlspecialchars($slug);
require __DIR__ . '/layout.php';

// Periode filter
require_once dirname(__DIR__) . '/src/period.php';
$P = osPeriod($_GET['period'] ?? '30');
$period = $P['period']; $periodDays = $P['days']; $periodLabel = $P['label'];
$periodSql = $P['sql'];

$filterSql = " AND page_slug = " . db()->quote($slug);

try {
    // Landing page info (als geregistreerd)
    $lpStmt = db()->prepare("SELECT lp.*, pr.name as product_name, pr.slug as product_slug FROM landing_pages lp LEFT JOIN products pr ON pr.id = lp.product_id WHERE lp.slug = ?");
    $lpStmt->execute([$slug]);
    $landingPage = $lpStmt->fetch();

    // Pageviews per dag
    $dailyViews = db()->query("
        SELECT DATE(created_at) as date, COUNT(*) as views
        FROM tracking_pageviews WHERE $periodSql $filterSql
        GROUP BY DATE(created_at) ORDER BY date
    ")->fetchAll();
    $dailyViews = osPadDailySeries($dailyViews, $periodDays);
    $totalViews = array_sum(array_column($dailyViews, 'views'));

    // Totalen
    $totalConversions = db()->query("SELECT COUNT(*) FROM tracking_conversions WHERE $periodSql $filterSql")->fetchColumn();
    $totalForms = db()->query("SELECT COUNT(*) FROM tracking_forms WHERE $periodSql $filterSql")->fetchColumn();
    $conversionRate = $totalViews > 0 ? round(($totalForms / $totalViews) * 100, 1) : 0;
    $ctaRate = $totalViews > 0 ? round(($totalConversions / $totalViews) * 100, 1) : 0;
    $avgTime = db()->query("SELECT AVG(seconds) FROM tracking_time WHERE $periodSql $filterSql")->fetchColumn();

    // Conversie funnel
    $funnelScroll50 = db()->query("SELECT COUNT(DISTINCT visitor_id) FROM tracking_scroll WHERE depth >= 50 AND $periodSql $filterSql")->fetchColumn();
    $funnelVideoPlay = db()->query("SELECT COUNT(DISTINCT visitor_id) FROM tracking_video WHERE event = 'play' AND $periodSql $filterSql")->fetchColumn();
    $funnelVideoHalf = db()->query("SELECT COUNT(DISTINCT visitor_id) FROM tracking_video WHERE duration > 0 AND seconds_watched * 2 >= duration AND $periodSql $filterSql")->fetchColumn();
    $formStarts = db()->query("SELECT COUNT(*) FROM tracking_form_interactions WHERE event = 'start' AND $periodSql $filterSql")->fetchColumn();

    // Leads van deze pagina
    $leadsStmt = db()->prepare("SELECT * FROM clients WHERE source_page = ? ORDER BY created_at DESC");
    $leadsStmt->execute([$slug]);
    $leads = $leadsStmt->fetchAll();

    // Scroll depth
    $scrollData = db()->query("SELECT depth, COUNT(*) as count FROM tracking_scroll WHERE $periodSql $filterSql GROUP BY depth ORDER BY depth")->fetchAll();
    $scrollByDepth = array_column($scrollData, 'count', 'depth');
    $scrollTotal = max(array_sum(array_values($scrollByDepth)), 1);

    // Time on page
    $timeData = db()->query("
        SELECT CASE WHEN seconds < 10 THEN '0-10s' WHEN seconds < 30 THEN '10-30s' WHEN seconds < 60 THEN '30-60s' WHEN seconds < 180 THEN '1-3min' WHEN seconds < 300 THEN '3-5min' ELSE '5min+' END as bracket,
            COUNT(*) as count FROM tracking_time WHERE $periodSql $filterSql GROUP BY bracket ORDER BY MIN(seconds)
    ")->fetchAll();

    // Video stats
    $videoPlays = db()->query("SELECT COUNT(*) FROM tracking_video WHERE event = 'play' AND $periodSql $filterSql")->fetchColumn();
    $videoCompletes = db()->query("SELECT COUNT(*) FROM tracking_video WHERE event = 'complete' AND $periodSql $filterSql")->fetchColumn();
    $videoAvgWatch = db()->query("SELECT AVG(seconds_watched) FROM tracking_video WHERE event IN ('complete', 'progress_50', 'progress_75') AND $periodSql $filterSql")->fetchColumn();
    $videoCompletionRate = $videoPlays > 0 ? round(($videoCompletes / $videoPlays) * 100, 1) : 0;
    $videoProgress = db()->query("SELECT event, COUNT(*) as count FROM tracking_video WHERE event LIKE 'progress_%' AND $periodSql $filterSql GROUP BY event ORDER BY event")->fetchAll();

    // CTA clicks
    $ctaData = db()->query("SELECT action, label, COUNT(*) as count FROM tracking_conversions WHERE $periodSql $filterSql GROUP BY action, label ORDER BY count DESC LIMIT 10")->fetchAll();

    // Verkeersbronnen
    $referrerData = db()->query("
        SELECT CASE WHEN referrer IS NULL OR referrer = '' THEN 'Direct' WHEN referrer LIKE '%google%' THEN 'Google' WHEN referrer LIKE '%facebook%' OR referrer LIKE '%fb.%' THEN 'Facebook' WHEN referrer LIKE '%instagram%' THEN 'Instagram' WHEN referrer LIKE '%linkedin%' THEN 'LinkedIn' WHEN referrer LIKE '%youtube%' THEN 'YouTube'
            ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(referrer, 'https://', ''), 'http://', ''), '/', 1), '?', 1) END as source, COUNT(*) as count
        FROM tracking_pageviews WHERE $periodSql $filterSql GROUP BY source ORDER BY count DESC LIMIT 8
    ")->fetchAll();
    $referrerTotal = max(array_sum(array_column($referrerData, 'count')), 1);

    // Apparaten
    $deviceData = db()->query("
        SELECT CASE WHEN CAST(SUBSTRING_INDEX(viewport, 'x', 1) AS UNSIGNED) <= 480 THEN 'Mobiel' WHEN CAST(SUBSTRING_INDEX(viewport, 'x', 1) AS UNSIGNED) <= 1024 THEN 'Tablet' ELSE 'Desktop' END as device, COUNT(*) as count
        FROM tracking_pageviews WHERE viewport IS NOT NULL AND viewport != '' AND $periodSql $filterSql GROUP BY device ORDER BY count DESC
    ")->fetchAll();
    $deviceTotal = max(array_sum(array_column($deviceData, 'count')), 1);

    // Form interactions
    $formAbandons = db()->query("SELECT COUNT(*) FROM tracking_form_interactions WHERE event = 'abandon' AND $periodSql $filterSql")->fetchColumn();
    $formAbandonRate = $formStarts > 0 ? round(($formAbandons / $formStarts) * 100, 1) : 0;

    // Browser/OS breakdown (via user_agent)
    $uaRows = db()->query("
        SELECT user_agent FROM tracking_pageviews
        WHERE user_agent IS NOT NULL AND user_agent != '' AND $periodSql $filterSql
    ")->fetchAll(PDO::FETCH_COLUMN);
    $browserCounts = [];
    $osCounts = [];
    $uaDeviceCounts = [];
    foreach ($uaRows as $ua) {
        $parsed = parseUserAgent($ua);
        $browserCounts[$parsed['browser']] = ($browserCounts[$parsed['browser']] ?? 0) + 1;
        $osCounts[$parsed['os']] = ($osCounts[$parsed['os']] ?? 0) + 1;
        $uaDeviceCounts[$parsed['device']] = ($uaDeviceCounts[$parsed['device']] ?? 0) + 1;
    }
    arsort($browserCounts);
    arsort($osCounts);
    arsort($uaDeviceCounts);
    $browserTotal = max(array_sum($browserCounts), 1);
    $osTotal = max(array_sum($osCounts), 1);

} catch (PDOException $e) {
    $landingPage = null; $dailyViews = []; $totalViews = 0; $totalConversions = 0; $totalForms = 0;
    $conversionRate = 0; $ctaRate = 0; $avgTime = 0; $funnelScroll50 = 0; $funnelVideoPlay = 0; $funnelVideoHalf = 0; $formStarts = 0;
    $leads = []; $scrollByDepth = []; $scrollTotal = 1; $timeData = [];
    $videoPlays = 0; $videoCompletes = 0; $videoAvgWatch = 0; $videoCompletionRate = 0; $videoProgress = [];
    $ctaData = []; $referrerData = []; $referrerTotal = 1; $deviceData = []; $deviceTotal = 1;
    $formAbandons = 0; $formAbandonRate = 0;
    $browserCounts = []; $osCounts = []; $uaDeviceCounts = []; $browserTotal = 1; $osTotal = 1;
    $utmRows = [];
}

// UTM bronnen voor deze pagina
try {
    $utmRows = db()->query("
        SELECT
            COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(utm_json, '$.source')),'null'),'(direct)') as source,
            COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(utm_json, '$.medium')),'null'),'—') as medium,
            COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(utm_json, '$.campaign')),'null'),'—') as campaign,
            COUNT(*) as views
        FROM tracking_pageviews
        WHERE page_slug = " . db()->quote($slug) . " AND $periodSql
        GROUP BY source, medium, campaign
        ORDER BY views DESC
        LIMIT 15
    ")->fetchAll();
} catch (PDOException $e) {
    $utmRows = [];
}
?>

<!-- Header -->
<div class="os-toolbar" style="justify-content:space-between">
    <div style="display:flex;align-items:center;gap:1rem">
        <a href="<?= $p ?>/pages" class="os-btn os-btn-sm">&larr; Alle pagina's</a>
        <h2 style="font-size:1.1rem;font-weight:700;margin:0">/<?= htmlspecialchars($slug) ?></h2>
        <?php if ($landingPage): ?>
            <?php if (!empty($landingPage['product_name'])): ?>
                <a href="<?= $p ?>/products/<?= htmlspecialchars($landingPage['product_slug']) ?>" class="os-badge os-badge-active"><?= htmlspecialchars($landingPage['product_name']) ?></a>
            <?php endif; ?>
            <?php if ($landingPage['url']): ?>
                <a href="<?= htmlspecialchars($landingPage['url']) ?>" target="_blank" class="os-btn os-btn-sm">Bekijken &nearr;</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="os-period-filter">
        <?php foreach (osPeriodOptions() as $pVal => $pLabel): ?>
            <a href="<?= $p ?>/pages/<?= htmlspecialchars($slug) ?>?period=<?= $pVal ?>"
               class="os-period-btn <?= $period === $pVal ? 'active' : '' ?>"><?= $pLabel ?></a>
        <?php endforeach; ?>
    </div>
</div>

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
                ['label' => 'Video play', 'value' => $funnelVideoPlay, 'color' => '#FF6240'],
                ['label' => 'Video 50%+ bekeken', 'value' => $funnelVideoHalf, 'color' => '#E8A53D'],
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
                <div class="os-bar-track">
                    <div class="os-bar-fill" style="width:<?= $pct ?>%;background:<?= $step['color'] ?>"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Leads -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Leads (<?= count($leads) ?>)</h2></div>
        <div class="os-panel-body">
            <?php if (empty($leads)): ?>
                <p class="os-empty">Nog geen leads van deze pagina.</p>
            <?php else: ?>
                <table class="os-table">
                    <thead><tr><th>Naam</th><th>Email</th><th>Status</th><th>Datum</th></tr></thead>
                    <tbody>
                    <?php foreach ($leads as $lead): ?>
                        <tr class="os-clickable-row" onclick="location.href='<?= $p ?>/clients/<?= $lead['id'] ?>'">
                            <td><strong><?= htmlspecialchars($lead['name']) ?></strong></td>
                            <td><?= htmlspecialchars($lead['email'] ?? '—') ?></td>
                            <td><span class="os-badge os-badge-<?= $lead['status'] ?>"><?= $lead['status'] ?></span></td>
                            <td><?= date('d M Y', strtotime($lead['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="os-grid-2">
    <!-- Video engagement -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Video engagement</h2></div>
        <div class="os-panel-body">
            <?php if ($videoPlays == 0): ?>
                <p class="os-empty">Nog geen video data.</p>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1rem">
                    <div>
                        <div style="font-size:0.7rem;color:var(--os-text-muted);text-transform:uppercase;margin-bottom:0.25rem">Plays</div>
                        <div style="font-size:1.25rem;font-weight:700;color:#e44d4d"><?= number_format($videoPlays) ?></div>
                    </div>
                    <div>
                        <div style="font-size:0.7rem;color:var(--os-text-muted);text-transform:uppercase;margin-bottom:0.25rem">Completion</div>
                        <div style="font-size:1.25rem;font-weight:700;color:#90ed7d"><?= $videoCompletionRate ?>%</div>
                    </div>
                    <div>
                        <div style="font-size:0.7rem;color:var(--os-text-muted);text-transform:uppercase;margin-bottom:0.25rem">Gem. kijktijd</div>
                        <div style="font-size:1.25rem;font-weight:700;color:#7cb5ec"><?= $videoAvgWatch ? gmdate('i:s', (int)$videoAvgWatch) : '—' ?></div>
                    </div>
                </div>
                <?php
                $progressMap = [];
                foreach ($videoProgress as $vp) { $progressMap[$vp['event']] = $vp['count']; }
                foreach ([['25% bekeken', 'progress_25'], ['50% bekeken', 'progress_50'], ['75% bekeken', 'progress_75'], ['100% bekeken', 'progress_100']] as [$vlabel, $vkey]):
                    $count = $progressMap[$vkey] ?? 0;
                    $vpPct = $videoPlays > 0 ? round(($count / $videoPlays) * 100) : 0;
                ?>
                <div class="os-funnel-step">
                    <div class="os-funnel-label">
                        <span><?= $vlabel ?></span>
                        <span class="os-funnel-count"><?= number_format($count) ?> <span class="os-text-muted">(<?= $vpPct ?>%)</span></span>
                    </div>
                    <div class="os-bar-track"><div class="os-bar-fill" style="width:<?= $vpPct ?>%;background:#e44d4d"></div></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- CTA clicks -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>CTA clicks</h2></div>
        <div class="os-panel-body">
            <?php if (empty($ctaData)): ?>
                <p class="os-empty">Nog geen CTA clicks.</p>
            <?php else: ?>
                <table class="os-table">
                    <thead><tr><th>CTA</th><th>Label</th><th>Clicks</th></tr></thead>
                    <tbody>
                    <?php foreach ($ctaData as $cta): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($cta['action']) ?></code></td>
                            <td><?= htmlspecialchars(mb_strimwidth($cta['label'], 0, 30, '...')) ?></td>
                            <td><strong><?= number_format($cta['count']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="os-grid-2">
    <!-- Scroll depth heatmap -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Scroll depth heatmap</h2></div>
        <div class="os-panel-body">
            <div style="display:flex;gap:1rem;align-items:stretch;height:240px">
                <div style="flex:0 0 70px;display:flex;flex-direction:column;justify-content:space-between;font-size:0.7rem;color:var(--os-text-muted);text-align:right;padding:0.25rem 0;font-family:var(--os-font-label)">
                    <span>0% top</span><span>25%</span><span>50%</span><span>75%</span><span>100% einde</span>
                </div>
                <div style="flex:1;display:flex;flex-direction:column;border-radius:6px;overflow:hidden;border:1px solid var(--os-border)">
                    <?php foreach ([25, 50, 75, 100] as $depth):
                        $count = $scrollByDepth[$depth] ?? 0;
                        $pct = round(($count / $scrollTotal) * 100);
                        $hue = round($pct * 1.2);
                        $alpha = max(0.15, $pct / 100);
                    ?>
                    <div style="flex:1;background:hsla(<?= $hue ?>, 60%, 45%, <?= $alpha ?>);display:flex;align-items:center;justify-content:space-between;padding:0 0.75rem;color:#fff;font-weight:600;font-size:0.85rem;text-shadow:0 1px 2px rgba(0,0,0,0.4)">
                        <span>tot <?= $depth ?>%</span>
                        <span><?= $pct ?>% &middot; <?= number_format($count) ?> visitors</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <p style="font-size:0.75rem;color:var(--os-text-muted);margin-top:0.75rem">% van bezoekers dat deze diepte heeft bereikt. Hoe rooder, hoe meer drop-off.</p>
        </div>
    </div>

    <!-- Time on page -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Tijd op pagina</h2></div>
        <div class="os-panel-body">
            <?php if (empty($timeData)): ?>
                <p class="os-empty">Nog geen data.</p>
            <?php else:
                $timeMax = max(array_column($timeData, 'count'));
                foreach ($timeData as $td):
                    $pct = round(($td['count'] / max($timeMax, 1)) * 100);
            ?>
            <div class="os-funnel-step">
                <div class="os-funnel-label">
                    <span><?= $td['bracket'] ?></span>
                    <span class="os-funnel-count"><?= number_format($td['count']) ?></span>
                </div>
                <div class="os-bar-track"><div class="os-bar-fill" style="width:<?= $pct ?>%;background:#7cb5ec"></div></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- UTM bronnen voor deze pagina -->
<div class="os-panel">
    <div class="os-panel-header"><h2>UTM bronnen</h2></div>
    <div class="os-panel-body">
        <?php if (empty($utmRows)): ?>
            <p class="os-empty">Geen UTM-tagged verkeer voor deze pagina.</p>
        <?php else: ?>
            <table class="os-table">
                <thead><tr><th>Source</th><th>Medium</th><th>Campaign</th><th style="text-align:right">Views</th></tr></thead>
                <tbody>
                <?php foreach ($utmRows as $r): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($r['source']) ?></strong></td>
                        <td><?= htmlspecialchars($r['medium']) ?></td>
                        <td><?= htmlspecialchars($r['campaign']) ?></td>
                        <td style="text-align:right"><?= number_format($r['views']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="os-grid-2">
    <!-- Verkeersbronnen -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Verkeersbronnen</h2></div>
        <div class="os-panel-body">
            <?php if (empty($referrerData)): ?>
                <p class="os-empty">Nog geen data.</p>
            <?php else:
                $sourceColors = ['Direct' => 'var(--os-accent)', 'Google' => '#4285f4', 'Facebook' => '#1877f2', 'Instagram' => '#e4405f', 'LinkedIn' => '#0a66c2', 'YouTube' => '#ff0000'];
                foreach ($referrerData as $ref):
                    $pct = round(($ref['count'] / $referrerTotal) * 100);
                    $color = $sourceColors[$ref['source']] ?? '#888';
            ?>
            <div class="os-funnel-step">
                <div class="os-funnel-label">
                    <span><?= htmlspecialchars($ref['source']) ?></span>
                    <span class="os-funnel-count"><?= number_format($ref['count']) ?> <span class="os-text-muted">(<?= $pct ?>%)</span></span>
                </div>
                <div class="os-bar-track"><div class="os-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Apparaten -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Apparaten</h2></div>
        <div class="os-panel-body">
            <?php
            $devSource = !empty($uaDeviceCounts) ? $uaDeviceCounts : array_column($deviceData, 'count', 'device');
            $devTotal = max(array_sum($devSource), 1);
            $deviceColors = ['Desktop' => '#90ed7d', 'Tablet' => '#7cb5ec', 'Mobiel' => '#f7a35c'];
            if (empty($devSource)): ?>
                <p class="os-empty">Nog geen data.</p>
            <?php else:
                foreach ($devSource as $devName => $count):
                    $pct = round(($count / $devTotal) * 100);
            ?>
            <div class="os-funnel-step">
                <div class="os-funnel-label">
                    <span><?= htmlspecialchars($devName) ?></span>
                    <span class="os-funnel-count"><?= number_format($count) ?> <span class="os-text-muted">(<?= $pct ?>%)</span></span>
                </div>
                <div class="os-bar-track"><div class="os-bar-fill" style="width:<?= $pct ?>%;background:<?= $deviceColors[$devName] ?? 'var(--os-accent)' ?>"></div></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<div class="os-grid-2">
    <!-- Browsers -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Browsers</h2></div>
        <div class="os-panel-body">
            <?php if (empty($browserCounts)): ?>
                <p class="os-empty">Nog geen browser data.</p>
            <?php else:
                $browserColors = ['Chrome' => '#4285f4', 'Safari' => '#007aff', 'Firefox' => '#ff7139', 'Edge' => '#0078d7', 'Opera' => '#ff1b2d', 'Overig' => '#888'];
                foreach (array_slice($browserCounts, 0, 6, true) as $bName => $count):
                    $pct = round(($count / $browserTotal) * 100);
            ?>
            <div class="os-funnel-step">
                <div class="os-funnel-label">
                    <span><?= htmlspecialchars($bName) ?></span>
                    <span class="os-funnel-count"><?= number_format($count) ?> <span class="os-text-muted">(<?= $pct ?>%)</span></span>
                </div>
                <div class="os-bar-track"><div class="os-bar-fill" style="width:<?= $pct ?>%;background:<?= $browserColors[$bName] ?? '#888' ?>"></div></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Besturingssystemen -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Besturingssystemen</h2></div>
        <div class="os-panel-body">
            <?php if (empty($osCounts)): ?>
                <p class="os-empty">Nog geen OS data.</p>
            <?php else:
                $osColors = ['Windows' => '#0078d7', 'macOS' => '#555', 'iOS' => '#007aff', 'iPadOS' => '#5856d6', 'Android' => '#3ddc84', 'Linux' => '#f7a35c', 'Overig' => '#888'];
                foreach (array_slice($osCounts, 0, 6, true) as $osName => $count):
                    $pct = round(($count / $osTotal) * 100);
            ?>
            <div class="os-funnel-step">
                <div class="os-funnel-label">
                    <span><?= htmlspecialchars($osName) ?></span>
                    <span class="os-funnel-count"><?= number_format($count) ?> <span class="os-text-muted">(<?= $pct ?>%)</span></span>
                </div>
                <div class="os-bar-track"><div class="os-bar-fill" style="width:<?= $pct ?>%;background:<?= $osColors[$osName] ?? '#888' ?>"></div></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<script>
const data = <?= json_encode($dailyViews) ?>;
const canvas = document.getElementById('viewsChart');
if (canvas && data.length > 0) {
    const ctx = canvas.getContext('2d');
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * 2;
    canvas.height = 400;
    ctx.scale(2, 2);
    const w = rect.width, h = 200;
    const pad = { top: 20, right: 20, bottom: 30, left: 50 };
    const chartW = w - pad.left - pad.right;
    const chartH = h - pad.top - pad.bottom;
    const maxVal = Math.max(...data.map(d => d.views), 1);
    ctx.strokeStyle = '#222'; ctx.lineWidth = 0.5;
    for (let i = 0; i <= 4; i++) { const y = pad.top + (chartH / 4) * i; ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(w - pad.right, y); ctx.stroke(); }
    const barW = Math.max(2, (chartW / data.length) - 2);
    data.forEach((d, i) => {
        const x = pad.left + (chartW / data.length) * i + 1;
        const barH = (d.views / maxVal) * chartH;
        ctx.fillStyle = '#C9A84C';
        ctx.beginPath(); ctx.roundRect(x, pad.top + chartH - barH, barW, barH, 2); ctx.fill();
    });
    ctx.fillStyle = '#666'; ctx.font = '10px Inter, sans-serif'; ctx.textAlign = 'right';
    for (let i = 0; i <= 4; i++) { ctx.fillText(Math.round((maxVal / 4) * (4 - i)), pad.left - 8, pad.top + (chartH / 4) * i + 4); }
    ctx.textAlign = 'center';
    const step = Math.max(1, Math.floor(data.length / 6));
    data.forEach((d, i) => { if (i % step === 0) { const x = pad.left + (chartW / data.length) * i + barW / 2; ctx.fillText(d.date.slice(5), x, h - 5); } });
}
</script>

<?php require __DIR__ . '/layout-end.php'; ?>
