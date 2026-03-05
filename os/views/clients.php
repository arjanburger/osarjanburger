<?php
$pageTitle = 'Klanten';
require __DIR__ . '/layout.php';

try {
    $clients = db()->query("
        SELECT c.*,
            (SELECT COUNT(*) FROM tracking_pageviews tp WHERE tp.visitor_id = c.visitor_id) as pageviews,
            (SELECT MAX(depth) FROM tracking_scroll ts WHERE ts.visitor_id = c.visitor_id) as max_scroll,
            (SELECT COUNT(*) FROM tracking_forms tf WHERE tf.visitor_id = c.visitor_id) as form_count
        FROM clients c
        ORDER BY c.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $clients = [];
}
?>

<div class="os-toolbar">
    <button class="os-btn os-btn-primary" onclick="document.getElementById('addClientModal').classList.add('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Klant toevoegen
    </button>
</div>

<div class="os-panel">
    <div class="os-panel-body">
        <?php if (empty($clients)): ?>
            <p class="os-empty">Nog geen klanten. Formulierinzendingen maken automatisch klanten aan.</p>
        <?php else: ?>
            <table class="os-table">
                <thead>
                    <tr>
                        <th>Naam</th>
                        <th>Email</th>
                        <th>Bron</th>
                        <th>Views</th>
                        <th>Scroll</th>
                        <th>Status</th>
                        <th>Aangemaakt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $client): ?>
                    <tr class="os-clickable-row" onclick="location.href='<?= $p ?>/clients/<?= $client['id'] ?>'">
                        <td><strong><?= htmlspecialchars($client['name']) ?></strong></td>
                        <td><?= htmlspecialchars($client['email'] ?? '—') ?></td>
                        <td><?php if ($client['source_page']): ?><code><?= htmlspecialchars($client['source_page']) ?></code><?php else: ?>—<?php endif; ?></td>
                        <td><?= number_format($client['pageviews'] ?? 0) ?></td>
                        <td><?= $client['max_scroll'] ? $client['max_scroll'] . '%' : '—' ?></td>
                        <td><span class="os-badge os-badge-<?= $client['status'] ?>"><?= htmlspecialchars($client['status']) ?></span></td>
                        <td><?= date('d M Y', strtotime($client['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Add client modal -->
<div class="os-modal" id="addClientModal">
    <div class="os-modal-backdrop" onclick="this.parentElement.classList.remove('open')"></div>
    <div class="os-modal-content">
        <h2>Klant toevoegen</h2>
        <form method="POST" action="/api/clients/create">
            <div class="form-group">
                <label>Naam</label>
                <input type="text" name="name" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email">
            </div>
            <div class="form-group">
                <label>Telefoon</label>
                <input type="tel" name="phone">
            </div>
            <div class="form-group">
                <label>Bedrijf</label>
                <input type="text" name="company">
            </div>
            <div class="form-group">
                <label>Notities</label>
                <textarea name="notes" rows="3"></textarea>
            </div>
            <div class="os-modal-actions">
                <button type="button" class="os-btn" onclick="this.closest('.os-modal').classList.remove('open')">Annuleren</button>
                <button type="submit" class="os-btn os-btn-primary">Opslaan</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/layout-end.php'; ?>
