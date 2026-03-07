<?php
$clientId = $routeParam ?? null;
if (!$clientId) { http_response_code(404); echo '404'; exit; }

try {
    $stmt = db()->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();
    if (!$client) { http_response_code(404); echo 'Klant niet gevonden'; exit; }
} catch (PDOException $e) {
    http_response_code(500); echo 'Database error'; exit;
}

$pageTitle = htmlspecialchars($client['name']);
require __DIR__ . '/layout.php';

// Resolve alle visitor IDs (cross-domain aliases)
$visitorIds = [$client['visitor_id']];
if ($client['visitor_id']) {
    try {
        // Zoek canonical
        $s = db()->prepare("SELECT canonical_id FROM visitor_aliases WHERE alias_id = ?");
        $s->execute([$client['visitor_id']]);
        $canonical = $s->fetchColumn() ?: $client['visitor_id'];

        // Haal alle aliases
        $s = db()->prepare("SELECT alias_id FROM visitor_aliases WHERE canonical_id = ?");
        $s->execute([$canonical]);
        $aliases = $s->fetchAll(PDO::FETCH_COLUMN);
        $visitorIds = array_unique(array_merge([$canonical], $aliases, [$client['visitor_id']]));
    } catch (PDOException $e) {}
}

// Bouw placeholder string voor IN clause
$placeholders = implode(',', array_fill(0, count($visitorIds), '?'));

try {
    // Alle events ophalen voor de timeline
    $events = [];

    // Pageviews
    $s = db()->prepare("SELECT 'pageview' as type, page_slug, url as detail, created_at FROM tracking_pageviews WHERE visitor_id IN ($placeholders) ORDER BY created_at");
    $s->execute($visitorIds);
    $events = array_merge($events, $s->fetchAll());

    // Scroll
    $s = db()->prepare("SELECT 'scroll' as type, page_slug, CONCAT('Scroll ', depth, '%') as detail, created_at FROM tracking_scroll WHERE visitor_id IN ($placeholders) ORDER BY created_at");
    $s->execute($visitorIds);
    $events = array_merge($events, $s->fetchAll());

    // Video
    $s = db()->prepare("SELECT 'video' as type, page_slug, CONCAT(event, ' (', seconds_watched, 's/', duration, 's)') as detail, created_at FROM tracking_video WHERE visitor_id IN ($placeholders) ORDER BY created_at");
    $s->execute($visitorIds);
    $events = array_merge($events, $s->fetchAll());

    // Conversions
    $s = db()->prepare("SELECT 'conversion' as type, page_slug, CONCAT(action, ': ', label) as detail, created_at FROM tracking_conversions WHERE visitor_id IN ($placeholders) ORDER BY created_at");
    $s->execute($visitorIds);
    $events = array_merge($events, $s->fetchAll());

    // Forms
    $s = db()->prepare("SELECT 'form' as type, page_slug, fields_json as detail, created_at FROM tracking_forms WHERE visitor_id IN ($placeholders) ORDER BY created_at");
    $s->execute($visitorIds);
    $events = array_merge($events, $s->fetchAll());

    // Time on page
    $s = db()->prepare("SELECT 'time' as type, page_slug, CONCAT(seconds, 's op pagina') as detail, created_at FROM tracking_time WHERE visitor_id IN ($placeholders) ORDER BY created_at");
    $s->execute($visitorIds);
    $events = array_merge($events, $s->fetchAll());

    // Form interactions (start/progress/abandon)
    $s = db()->prepare("SELECT CONCAT('form_', event) as type, page_slug, CONCAT(form_id, ' — ', field_count, ' velden, ', time_spent, 's') as detail, created_at FROM tracking_form_interactions WHERE visitor_id IN ($placeholders) ORDER BY created_at");
    $s->execute($visitorIds);
    $events = array_merge($events, $s->fetchAll());

    // Sorteer op tijd
    usort($events, fn($a, $b) => strtotime($a['created_at']) - strtotime($b['created_at']));

    // Stats
    $totalTime = db()->prepare("SELECT SUM(seconds) FROM tracking_time WHERE visitor_id IN ($placeholders)");
    $totalTime->execute($visitorIds);
    $totalSeconds = $totalTime->fetchColumn() ?: 0;

    $maxScroll = db()->prepare("SELECT MAX(depth) FROM tracking_scroll WHERE visitor_id IN ($placeholders)");
    $maxScroll->execute($visitorIds);
    $maxScrollDepth = $maxScroll->fetchColumn() ?: 0;

    $videoTime = db()->prepare("SELECT SUM(seconds_watched) FROM tracking_video WHERE visitor_id IN ($placeholders) AND event = 'complete'");
    $videoTime->execute($visitorIds);
    $totalVideoSeconds = $videoTime->fetchColumn() ?: 0;

    // Formulier data
    $formData = db()->prepare("SELECT fields_json, form_id, page_slug, created_at FROM tracking_forms WHERE visitor_id IN ($placeholders) ORDER BY created_at DESC");
    $formData->execute($visitorIds);
    $forms = $formData->fetchAll();

} catch (PDOException $e) {
    $events = []; $totalSeconds = 0; $maxScrollDepth = 0; $totalVideoSeconds = 0; $forms = [];
}

$eventConfig = [
    'pageview'   => ['label' => 'Pageview',   'color' => 'var(--os-accent)', 'icon' => 'eye'],
    'scroll'     => ['label' => 'Scroll',     'color' => '#7cb5ec',          'icon' => 'arrow-down'],
    'video'      => ['label' => 'Video',      'color' => '#e44d4d',          'icon' => 'play'],
    'conversion' => ['label' => 'CTA',        'color' => '#90ed7d',          'icon' => 'zap'],
    'form'          => ['label' => 'Formulier',     'color' => '#f7a35c',          'icon' => 'mail'],
    'form_start'    => ['label' => 'Form gestart',  'color' => '#f7a35c',          'icon' => 'edit'],
    'form_progress' => ['label' => 'Form invullen', 'color' => '#d4a85c',          'icon' => 'edit'],
    'form_abandon'  => ['label' => 'Form verlaten', 'color' => '#e44d4d',          'icon' => 'x'],
    'time'          => ['label' => 'Tijd',          'color' => '#8085e9',          'icon' => 'clock'],
];
?>

<div class="os-toolbar">
    <a href="<?= $p ?>/clients" class="os-btn os-btn-sm">&larr; Alle klanten</a>
</div>

<!-- Client header -->
<div class="os-panel" style="margin-bottom:1.5rem">
    <div class="os-panel-body">
        <div class="os-client-header">
            <div class="os-client-avatar"><?= strtoupper(mb_substr($client['name'], 0, 1)) ?></div>
            <div class="os-client-info">
                <h2 style="font-size:1.25rem;font-weight:700;margin-bottom:0.25rem"><?= htmlspecialchars($client['name']) ?></h2>
                <div style="display:flex;gap:1.5rem;flex-wrap:wrap;font-size:0.85rem;color:var(--os-text-muted)">
                    <?php if ($client['email']): ?><span><?= htmlspecialchars($client['email']) ?></span><?php endif; ?>
                    <?php if ($client['phone']): ?><span><?= htmlspecialchars($client['phone']) ?></span><?php endif; ?>
                    <?php if ($client['company']): ?><span><?= htmlspecialchars($client['company']) ?></span><?php endif; ?>
                    <?php if ($client['source_page']): ?><span>Bron: <code><?= htmlspecialchars($client['source_page']) ?></code></span><?php endif; ?>
                    <span><?= date('d M Y H:i', strtotime($client['created_at'])) ?></span>
                </div>
            </div>
            <span class="os-badge os-badge-<?= htmlspecialchars($client['status']) ?>"><?= htmlspecialchars($client['status']) ?></span>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="os-stats-grid" style="grid-template-columns:repeat(4,1fr)">
    <div class="os-stat-card">
        <div class="os-stat-label">Events</div>
        <div class="os-stat-value"><?= count($events) ?></div>
        <div class="os-stat-sub">Totaal</div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Tijd op pagina</div>
        <div class="os-stat-value"><?= $totalSeconds ? gmdate('i:s', $totalSeconds) : '—' ?></div>
        <div class="os-stat-sub">Totaal</div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Max scroll</div>
        <div class="os-stat-value"><?= $maxScrollDepth ? $maxScrollDepth . '%' : '—' ?></div>
        <div class="os-stat-sub">Diepste punt</div>
    </div>
    <div class="os-stat-card">
        <div class="os-stat-label">Video kijktijd</div>
        <div class="os-stat-value"><?= $totalVideoSeconds ? gmdate('i:s', $totalVideoSeconds) : '—' ?></div>
        <div class="os-stat-sub">Totaal</div>
    </div>
</div>

<div class="os-grid-2">
    <!-- Journey timeline -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Bezoekersreis</h2></div>
        <div class="os-panel-body">
            <?php if (empty($events)): ?>
                <p class="os-empty">Nog geen activiteit geregistreerd.</p>
            <?php else: ?>
                <div class="os-timeline">
                    <?php
                    $prevDate = '';
                    foreach ($events as $event):
                        $cfg = $eventConfig[$event['type']] ?? $eventConfig['pageview'];
                        $eventDate = date('d M Y', strtotime($event['created_at']));
                        $eventTime = date('H:i:s', strtotime($event['created_at']));
                        $detail = $event['detail'];
                        if ($event['type'] === 'form') {
                            $fields = json_decode($detail, true);
                            $detail = $fields ? implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($fields), $fields)) : $detail;
                        }

                        if ($eventDate !== $prevDate):
                            $prevDate = $eventDate;
                    ?>
                        <div class="os-timeline-date"><?= $eventDate ?></div>
                    <?php endif; ?>

                    <div class="os-timeline-item">
                        <div class="os-timeline-dot" style="background:<?= $cfg['color'] ?>"></div>
                        <div class="os-timeline-content">
                            <div class="os-timeline-header">
                                <span class="os-timeline-type" style="color:<?= $cfg['color'] ?>"><?= $cfg['label'] ?></span>
                                <span class="os-timeline-time"><?= $eventTime ?></span>
                            </div>
                            <div class="os-timeline-detail">
                                <?php if ($event['page_slug']): ?><code><?= htmlspecialchars($event['page_slug']) ?></code> &mdash; <?php endif; ?>
                                <?= htmlspecialchars(mb_strimwidth($detail, 0, 120, '...')) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Formulier inzendingen -->
    <div class="os-panel">
        <div class="os-panel-header"><h2>Formulieren</h2></div>
        <div class="os-panel-body">
            <?php if (empty($forms)): ?>
                <p class="os-empty">Geen formulieren ingediend.</p>
            <?php else: ?>
                <?php foreach ($forms as $form):
                    $fields = json_decode($form['fields_json'], true) ?? [];
                ?>
                <div class="os-form-submission">
                    <div class="os-form-meta">
                        <span><code><?= htmlspecialchars($form['form_id']) ?></code></span>
                        <span> op <code><?= htmlspecialchars($form['page_slug']) ?></code></span>
                        <span class="os-activity-time"><?= date('d M Y H:i', strtotime($form['created_at'])) ?></span>
                    </div>
                    <div class="os-form-fields">
                        <?php foreach ($fields as $key => $value): ?>
                        <div class="os-form-field">
                            <span class="os-form-field-key"><?= htmlspecialchars($key) ?></span>
                            <span class="os-form-field-value"><?= htmlspecialchars($value) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($client['notes']): ?>
<div class="os-panel">
    <div class="os-panel-header"><h2>Notities</h2></div>
    <div class="os-panel-body">
        <p style="font-size:0.9rem;white-space:pre-wrap"><?= htmlspecialchars($client['notes']) ?></p>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/layout-end.php'; ?>
