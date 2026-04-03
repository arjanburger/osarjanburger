<?php
$pageTitle = 'Analytics';
require __DIR__ . '/layout.php';

$pageFilter = $_GET['page'] ?? null;
$filterSql = $pageFilter ? " AND page_slug = " . db()->quote($pageFilter) : '';

// Periode filter
$period = $_GET['period'] ?? '30';
$periodDays = match($period) {
    '1' => 1,
    '7' => 7,
    '90' => 90,
    default => 30,
};
$periodSql = "created_at >= DATE_SUB(CURDATE(), INTERVAL $periodDays DAY)";
$periodLabel = match($period) {
    '1' => 'Vandaag',
    '7' => '7 dagen',
    '90' => '90 dagen',
    default => '30 dagen',
};

// Query params behouden
$qp = '?period=' . $period;
if ($pageFilter) $qp .= '&page=' . urlencode($pageFilter);

try {
    // Pageviews per dag
    $dailyViews = db()->query("
        SELECT DATE(created_at) as date, COUNT(*) as views
        FROM tracking_pageviews
        WHERE $periodSql $filterSql
        GROUP BY DATE(created_at) ORDER BY date
    ")->fetchAll();

    // Totalen
    $totalViews = array_sum(array_column($dailyViews, 'views'));
    $totalConversions = db()->query("SELECT COUNT(*) FROM tracking_conversions WHERE $periodSql $filterSql")->fetchColumn();
    $totalForms = db()->query("SELECT COUNT(*) FROM tracking_forms WHERE $periodSql $filterSql")->fetchColumn();
    $conversionRate = $totalViews > 0 ? round(($totalConversions / $totalViews) * 100, 1) : 0;

    // Top pagina's
    $topPages = db()->query("
        SELECT page_slug, COUNT(*) as views
        FROM tracking_pageviews
        WHERE $periodSql $filterSql
        GROUP BY page_slug ORDER BY views DESC LIMIT 10
    ")->fetchAll();

    // Scroll depth verdeling
    $scrollData = db()->query("
        SELECT depth, COUNT(*) as count
        FROM tracking_scroll
        WHERE $periodSql $filterSql
        GROUP BY depth ORDER BY depth
    ")->fetchAll();
    $scrollByDepth = array_column($scrollData, 'count', 'depth');
    $scrollTotal = max(array_sum(array_values($scrollByDepth)), 1);

    // Time on page verdeling
    $timeData = db()->query("
        SELECT
            CASE
                WHEN seconds < 10 THEN '0-10s'
                WHEN seconds < 30 THEN '10-30s'
                WHEN seconds < 60 THEN '30-60s'
                WHEN seconds < 180 THEN '1-3min'
                WHEN seconds < 300 THEN '3-5min'
                ELSE '5min+'
            END as bracket,
            COUNT(*) as count,
            AVG(seconds) as avg_sec
        FROM tracking_time
        WHERE $periodSql $filterSql
        GROUP BY bracket
        ORDER BY MIN(seconds)
    ")->fetchAll();
    $avgTime = db()->query("SELECT AVG(seconds) FROM tracking_time WHERE $periodSql $filterSql")->fetchColumn();

    // Referrer bronnen
    $referrerData = db()->query("
        SELECT
            CASE
                WHEN referrer IS NULL OR referrer = '' THEN 'Direct'
                WHEN referrer LIKE '%google%' THEN 'Google'
                WHEN referrer LIKE '%facebook%' OR referrer LIKE '%fb.%' THEN 'Facebook'
                WHEN referrer LIKE '%instagram%' THEN 'Instagram'
                WHEN referrer LIKE '%linkedin%' THEN 'LinkedIn'
                WHEN referrer LIKE '%youtube%' THEN 'YouTube'
                ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(referrer, 'https://', ''), 'http://', ''), '/', 1), '?', 1)
            END as source,
            COUNT(*) as count
        FROM tracking_pageviews
        WHERE $periodSql $filterSql
        GROUP BY source ORDER BY count DESC LIMIT 8
    ")->fetchAll();
    $referrerTotal = max(array_sum(array_column($referrerData, 'count')), 1);

    // UTM campagnes
    $utmData = db()->query("
        SELECT utm_json
        FROM tracking_pageviews
        WHERE utm_json IS NOT NULL AND $periodSql $filterSql
        ORDER BY created_at DESC LIMIT 200
    ")->fetchAll();
    $campaigns = [];
    foreach ($utmData as $row) {
        $utm = json_decode($row['utm_json'], true);
        if (!empty($utm['utm_campaign'])) {
            $key = $utm['utm_campaign'];
            $campaigns[$key] = ($campaigns[$key] ?? 0) + 1;
        }
    }
    arsort($campaigns);
    $campaigns = array_slice($campaigns, 0, 5, true);

    // Device/viewport verdeling
    $deviceData = db()->query("
        SELECT
            CASE
                WHEN CAST(SUBSTRING_INDEX(viewport, 'x', 1) AS UNSIGNED) <= 480 THEN 'Mobiel'
                WHEN CAST(SUBSTRING_INDEX(viewport, 'x', 1) AS UNSIGNED) <= 1024 THEN 'Tablet'
                ELSE 'Desktop'
            END as device,
            COUNT(*) as count
        FROM tracking_pageviews
        WHERE viewport IS NOT NULL AND viewport != '' AND $periodSql $filterSql
        GROUP BY device ORDER BY count DESC
    ")->fetchAll();
    $deviceTotal = max(array_sum(array_column($deviceData, 'count')), 1);

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

    // Conversie funnel (inclusief video)
    $funnelViews = $totalViews;
    $funnelScroll50 = db()->query("SELECT COUNT(DISTINCT visitor_id) FROM tracking_scroll WHERE depth >= 50 AND $periodSql $filterSql")->fetchColumn();
    $funnelVideoPlay = db()->query("SELECT COUNT(DISTINCT visitor_id) FROM tracking_video WHERE event = 'play' AND $periodSql $filterSql")->fetchColumn();
    $funnelCta = $totalConversions;
    $funnelForm = $totalForms;

    // CTA breakdown
    $ctaData = db()->query("
        SELECT action, label, COUNT(*) as count
        FROM tracking_conversions
        WHERE $periodSql $filterSql
        GROUP BY action, label ORDER BY count DESC LIMIT 10
    ")->fetchAll();

    // Video stats
    $videoPlays = db()->query("SELECT COUNT(*) FROM tracking_video WHERE event = 'play' AND $periodSql $filterSql")->fetchColumn();
    $videoCompletes = db()->query("SELECT COUNT(*) FROM tracking_video WHERE event = 'complete' AND $periodSql $filterSql")->fetchColumn();
    $videoAvgWatch = db()->query("SELECT AVG(seconds_watched) FROM tracking_video WHERE event IN ('complete', 'progress_50', 'progress_75') AND $periodSql $filterSql")->fetchColumn();
    $videoCompletionRate = $videoPlays > 0 ? round(($videoCompletes / $videoPlays) * 100, 1) : 0;

    // Video progress breakdown
    $videoProgress = db()->query("
        SELECT event, COUNT(*) as count
        FROM tracking_video
        WHERE event LIKE 'progress_%' AND $periodSql $filterSql
        GROUP BY event ORDER BY event
    ")->fetchAll();

    // Form interaction stats
    $formStarts = db()->query("SELECT COUNT(*) FROM tracking_form_interactions WHERE event = 'start' AND $periodSql $filterSql")->fetchColumn();
    $formAbandons = db()->query("SELECT COUNT(*) FROM tracking_form_interactions WHERE event = 'abandon' AND $periodSql $filterSql")->fetchColumn();
    $formAbandonRate = $formStarts > 0 ? round(($formAbandons / $formStarts) * 100, 1) : 0;

    // Recente abandoned forms (met ingevulde data)
    $abandonedForms = db()->query("
        SELECT fi.visitor_id, fi.form_id, fi.fields_json, fi.field_count, fi.time_spent, fi.page_slug, fi.created_at
        FROM tracking_form_interactions fi
        WHERE fi.event = 'abandon' AND fi.field_count > 0 AND $periodSql $filterSql
        ORDER BY fi.created_at DESC LIMIT 10
    ")->fetchAll();

} catch (PDOException $e) {
    $dailyViews = []; $topPages = []; $totalViews = 0; $totalConversions = 0; $totalForms = 0;
    $conversionRate = 0; $scrollByDepth = []; $scrollTotal = 1; $timeData = []; $avgTime = 0;
    $referrerData = []; $referrerTotal = 1; $campaigns = []; $deviceData = []; $deviceTotal = 1;
    $funnelViews = 0; $funnelScroll50 = 0; $funnelVideoPlay = 0; $funnelCta = 0; $funnelForm = 0; $ctaData = [];
    $videoPlays = 0; $videoCompletes = 0; $videoAvgWatch = 0; $videoCompletionRate = 0; $videoProgress = [];
    $formStarts = 0; $formAbandons = 0; $formAbandonRate = 0; $abandonedForms = [];
    $browserCounts = []; $osCounts = []; $uaDeviceCounts = []; $browserTotal = 1; $osTotal = 1;
}
?>

<!-- Toolbar: period filter + page filter -->
<div class="os-toolbar" style="justify-content:space-between">
    <div style="display:flex;align-items:center;gap:1rem">
        <?php if ($pageFilter): ?>
            <a href="<?= $p ?>/analytics?period=<?= $period ?>" class="os-btn os-btn-sm">&larr; Alle pagina's</a>
            <span class="os-toolbar-label">Filter: <strong><?= htmlspecialchars($pageFilter) ?></strong></span>
        <?php endif; ?>
    </div>
    <div class="os-period-filter">
        <?php foreach (['1' => 'Vandaag', '7' => '7d', '30' => '30d', '90' => '90d'] as $pVal => $pLabel): ?>
            <a href="<?= $p ?>/analytics?period=<?= $pVal ?><?= $pageFilter ? '&page=' . urlencode($pageFilter) : '' ?>"
               class="os-period-btn <?= $period === $pVal ? 'active' : '' ?>"><?= $pLabel ?></a>
        <?php endforeach; ?>
    </div>
</div>

<!-- KPI Stats -->
<div class="os-stats-grid os-stats-5">
    <div class="os-stat-card">
        <div class="os-stat-label">Views</div>
        <div class="os-stat-value"><?= number_format($totalViews) ?></div>
        <div class="os-stat-sub"><?= $periodLabel ?></div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Conversies</div>
        <div class="os-stat-value"><?= number_format($totalConversions) ?></div>
        <div class="os-stat-sub">CTA clicks</div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Leads</div>
        <div class="os-stat-value"><?= number_format($totalForms) ?></div>
        <div class="os-stat-sub">Formulieren</div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Conversieratio</div>
        <div class="os-stat-value"><?= $conversionRate ?>%</div>
        <div class="os-stat-sub">Views &rarr; CTA</div>
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

<!-- Two column layout -->
<div class="os-grid-2">
    <!-- Conversie funnel (met video stap) -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Conversie funnel</h2></div>
        <div class="os-panel-body">
            <?php
            $funnelSteps = [
                ['label' => 'Pageviews', 'value' => $funnelViews, 'color' => 'var(--os-accent)'],
                ['label' => 'Scroll 50%+', 'value' => $funnelScroll50, 'color' => '#7cb5ec'],
                ['label' => 'Video play', 'value' => $funnelVideoPlay, 'color' => '#e44d4d'],
                ['label' => 'CTA click', 'value' => $funnelCta, 'color' => '#90ed7d'],
                ['label' => 'Form gestart', 'value' => $formStarts, 'color' => '#f7a35c'],
                ['label' => 'Formulier verstuurd', 'value' => $funnelForm, 'color' => '#8085e9'],
            ];
            $funnelMax = max($funnelViews, 1);
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
                $vpSteps = [
                    ['label' => '25% bekeken', 'key' => 'progress_25'],
                    ['label' => '50% bekeken', 'key' => 'progress_50'],
                    ['label' => '75% bekeken', 'key' => 'progress_75'],
                    ['label' => '100% bekeken', 'key' => 'progress_100'],
                ];
                foreach ($vpSteps as $vs):
                    $count = $progressMap[$vs['key']] ?? 0;
                    $vpPct = $videoPlays > 0 ? round(($count / $videoPlays) * 100) : 0;
                ?>
                <div class="os-funnel-step">
                    <div class="os-funnel-label">
                        <span><?= $vs['label'] ?></span>
                        <span class="os-funnel-count"><?= number_format($count) ?> <span class="os-text-muted">(<?= $vpPct ?>%)</span></span>
                    </div>
                    <div class="os-bar-track">
                        <div class="os-bar-fill" style="width:<?= $vpPct ?>%;background:#e44d4d"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="os-grid-2">
    <!-- Formulier funnel -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Formulier funnel</h2></div>
        <div class="os-panel-body">
            <?php if ($formStarts == 0): ?>
                <p class="os-empty">Nog geen formulier interactie data.</p>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1rem">
                    <div>
                        <div style="font-size:0.7rem;color:var(--os-text-muted);text-transform:uppercase;margin-bottom:0.25rem">Gestart</div>
                        <div style="font-size:1.25rem;font-weight:700;color:#f7a35c"><?= number_format($formStarts) ?></div>
                    </div>
                    <div>
                        <div style="font-size:0.7rem;color:var(--os-text-muted);text-transform:uppercase;margin-bottom:0.25rem">Verstuurd</div>
                        <div style="font-size:1.25rem;font-weight:700;color:#90ed7d"><?= number_format($funnelForm) ?></div>
                    </div>
                    <div>
                        <div style="font-size:0.7rem;color:var(--os-text-muted);text-transform:uppercase;margin-bottom:0.25rem">Abandon rate</div>
                        <div style="font-size:1.25rem;font-weight:700;color:#e44d4d"><?= $formAbandonRate ?>%</div>
                    </div>
                </div>
                <?php
                $formFunnelSteps = [
                    ['label' => 'Form geopend', 'value' => $formStarts, 'color' => '#f7a35c'],
                    ['label' => 'Abandoned', 'value' => $formAbandons, 'color' => '#e44d4d'],
                    ['label' => 'Verstuurd', 'value' => $funnelForm, 'color' => '#90ed7d'],
                ];
                foreach ($formFunnelSteps as $fs):
                    $fsPct = $formStarts > 0 ? round(($fs['value'] / $formStarts) * 100) : 0;
                ?>
                <div class="os-funnel-step">
                    <div class="os-funnel-label">
                        <span><?= $fs['label'] ?></span>
                        <span class="os-funnel-count"><?= number_format($fs['value']) ?> <span class="os-text-muted">(<?= $fsPct ?>%)</span></span>
                    </div>
                    <div class="os-bar-track">
                        <div class="os-bar-fill" style="width:<?= $fsPct ?>%;background:<?= $fs['color'] ?>"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Abandoned formulieren -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Abandoned formulieren</h2></div>
        <div class="os-panel-body">
            <?php if (empty($abandonedForms)): ?>
                <p class="os-empty">Geen abandoned formulieren met ingevulde data.</p>
            <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:0.75rem">
                <?php foreach ($abandonedForms as $af):
                    $afFields = json_decode($af['fields_json'], true) ?: [];
                    $afTime = gmdate($af['time_spent'] >= 60 ? 'i:s' : 's\s', (int)$af['time_spent']);
                ?>
                    <div style="background:var(--os-bg-dark);border-radius:8px;padding:0.75rem;border-left:3px solid #e44d4d">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem">
                            <span style="font-size:0.75rem;color:var(--os-text-muted)">
                                <?= htmlspecialchars($af['page_slug']) ?> &middot; <?= $af['field_count'] ?> velden &middot; <?= $afTime ?>
                            </span>
                            <span style="font-size:0.7rem;color:var(--os-text-muted)"><?= date('d M H:i', strtotime($af['created_at'])) ?></span>
                        </div>
                        <?php if (!empty($afFields)): ?>
                        <div style="display:grid;grid-template-columns:auto 1fr;gap:0.25rem 0.75rem;font-size:0.8rem">
                            <?php foreach ($afFields as $key => $val): if ($val === '' || $val === null) continue; ?>
                                <span style="color:var(--os-text-muted)"><?= htmlspecialchars($key) ?></span>
                                <span style="color:var(--os-text)"><?= htmlspecialchars(mb_strimwidth((string)$val, 0, 50, '...')) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="os-grid-2">
    <!-- Scroll depth -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Scroll depth</h2></div>
        <div class="os-panel-body">
            <?php foreach ([25, 50, 75, 100] as $depth):
                $count = $scrollByDepth[$depth] ?? 0;
                $pct = round(($count / $scrollTotal) * 100);
            ?>
            <div class="os-funnel-step">
                <div class="os-funnel-label">
                    <span><?= $depth ?>%</span>
                    <span class="os-funnel-count"><?= number_format($count) ?> <span class="os-text-muted">(<?= $pct ?>%)</span></span>
                </div>
                <div class="os-bar-track">
                    <div class="os-bar-fill" style="width:<?= $pct ?>%;background:var(--os-accent)"></div>
                </div>
            </div>
            <?php endforeach; ?>
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
                <div class="os-bar-track">
                    <div class="os-bar-fill" style="width:<?= $pct ?>%;background:#7cb5ec"></div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
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
                <div class="os-bar-track">
                    <div class="os-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Devices (user agent based, fallback to viewport) -->
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
                <p class="os-empty">Nog geen browser data. Wordt verzameld bij nieuwe pageviews.</p>
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
                <p class="os-empty">Nog geen OS data. Wordt verzameld bij nieuwe pageviews.</p>
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

<div class="os-grid-2">
    <!-- CTA breakdown -->
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

    <!-- UTM Campagnes -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>UTM campagnes</h2></div>
        <div class="os-panel-body">
            <?php if (empty($campaigns)): ?>
                <p class="os-empty">Geen UTM data.</p>
            <?php else:
                $campMax = max(array_values($campaigns));
                foreach ($campaigns as $name => $count):
                    $pct = round(($count / max($campMax, 1)) * 100);
            ?>
            <div class="os-funnel-step">
                <div class="os-funnel-label">
                    <span><?= htmlspecialchars($name) ?></span>
                    <span class="os-funnel-count"><?= number_format($count) ?></span>
                </div>
                <div class="os-bar-track">
                    <div class="os-bar-fill" style="width:<?= $pct ?>%;background:#f15c80"></div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- Top pagina's -->
<div class="os-panel">
    <div class="os-panel-header"><h2>Top pagina's</h2></div>
    <div class="os-panel-body">
        <?php if (empty($topPages)): ?>
            <p class="os-empty">Nog geen data.</p>
        <?php else: ?>
            <table class="os-table">
                <thead><tr><th>Pagina</th><th>Views</th></tr></thead>
                <tbody>
                <?php foreach ($topPages as $tp): ?>
                    <tr>
                        <td><a href="<?= $p ?>/analytics?period=<?= $period ?>&page=<?= urlencode($tp['page_slug']) ?>" class="os-link">/<?= htmlspecialchars($tp['page_slug']) ?></a></td>
                        <td><?= number_format($tp['views']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
// Pageviews bar chart
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

    ctx.strokeStyle = '#222';
    ctx.lineWidth = 0.5;
    for (let i = 0; i <= 4; i++) {
        const y = pad.top + (chartH / 4) * i;
        ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(w - pad.right, y); ctx.stroke();
    }

    const barW = Math.max(2, (chartW / data.length) - 2);
    data.forEach((d, i) => {
        const x = pad.left + (chartW / data.length) * i + 1;
        const barH = (d.views / maxVal) * chartH;
        ctx.fillStyle = '#C9A84C';
        ctx.beginPath(); ctx.roundRect(x, pad.top + chartH - barH, barW, barH, 2); ctx.fill();
    });

    ctx.fillStyle = '#666';
    ctx.font = '10px Inter, sans-serif';
    ctx.textAlign = 'right';
    for (let i = 0; i <= 4; i++) {
        ctx.fillText(Math.round((maxVal / 4) * (4 - i)), pad.left - 8, pad.top + (chartH / 4) * i + 4);
    }

    ctx.textAlign = 'center';
    const step = Math.max(1, Math.floor(data.length / 6));
    data.forEach((d, i) => {
        if (i % step === 0) {
            const x = pad.left + (chartW / data.length) * i + barW / 2;
            ctx.fillText(d.date.slice(5), x, h - 5);
        }
    });
}
</script>

<?php require __DIR__ . '/layout-end.php'; ?>
