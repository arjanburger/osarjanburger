# Code Style & Conventies

## Taal
- UI tekst: **Nederlands** (labels, meldingen, navigatie)
- Code: **Engels** (variabelen, functies, klassen, comments)
- Database kolommen: Engels (created_at, page_slug, visitor_id)

## PHP

### Stijl
- Geen framework — plain PHP templates
- Functies in snake_case: `trackPageview()`, `mergeVisitorIds()`
- Variabelen in camelCase: `$pageSlug`, `$visitorId`, `$periodDays`
- SQL variabelen in camelCase: `$filterSql`, `$periodSql`
- Prepared statements met `?` placeholders (geen named params)
- PDO met `ERRMODE_EXCEPTION` en `FETCH_ASSOC`
- Inline SQL — geen ORM, geen query builder

### View Pattern
```php
<?php
// 1. POST handler (voor output! anders headers already sent)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // verwerk form, redirect
    header('Location: ' . $p . '/route');
    exit;
}

// 2. Stel pagina titel in
$pageTitle = 'Titel';
require __DIR__ . '/layout.php';

// 3. Database queries
try {
    $data = db()->query("...")->fetchAll();
} catch (PDOException $e) {
    $data = [];
}
?>

<!-- 4. HTML output -->
<div class="os-panel">...</div>

<?php require __DIR__ . '/layout-end.php'; ?>
```

### Route Parameter
Detail views ontvangen `$routeParam` via de router:
```php
$slug = $routeParam ?? null;
if (!$slug) { http_response_code(404); echo '404'; exit; }
```

### Database Prefix
```php
$p = defined('OS_URL_PREFIX') ? OS_URL_PREFIX : '';
// Gebruik: href="<?= $p ?>/products"
```

### Periode Filter Pattern
```php
$period = $_GET['period'] ?? '30';
$periodDays = match($period) { '1' => 1, '7' => 7, '90' => 90, default => 30 };
$periodSql = "created_at >= DATE_SUB(CURDATE(), INTERVAL $periodDays DAY)";
```

## HTML/CSS

### CSS Klassen (os- prefix)
```
os-layout          # Hoofd layout grid
os-sidebar         # Sidebar navigatie
os-main            # Main content area
os-header          # Pagina header met titel
os-content         # Content wrapper
os-toolbar         # Actie balk (buttons, filters)
os-panel           # Content panel (wit/donker kader)
os-panel-header    # Panel header met titel
os-panel-body      # Panel content
os-stats-grid      # Grid van stat kaarten
os-stats-5         # 5-koloms variant
os-stat-card       # Individuele stat kaart
os-stat-label      # Stat label (klein, grijs)
os-stat-value      # Stat waarde (groot, bold)
os-stat-sub        # Stat sub-tekst
os-table           # Data tabel
os-clickable-row   # Klikbare tabel rij (cursor: pointer)
os-btn             # Basis button
os-btn-primary     # Primaire actie (goud)
os-btn-sm          # Kleine button
os-badge           # Status badge
os-badge-active    # Groen
os-badge-draft     # Grijs
os-badge-live      # Groen
os-badge-lead      # Blauw
os-badge-paused    # Oranje
os-page-card       # Landing page kaart
os-page-card-stats # Stats grid in kaart
os-modal           # Modal overlay
os-modal-backdrop  # Modal achtergrond
os-modal-content   # Modal inhoud
os-nav-item        # Sidebar nav link
os-funnel-step     # Funnel balk rij
os-bar-track       # Balk achtergrond
os-bar-fill        # Balk vulling
os-grid-2          # 2-koloms grid
os-period-filter   # Periode knoppen container
os-period-btn      # Periode knop
os-empty           # Lege state tekst
```

### Kleuren (CSS variabelen)
```css
--os-bg: #0a0a0a;            /* Achtergrond */
--os-surface: #141414;        /* Panels, kaarten */
--os-border: #1e1e1e;         /* Randen */
--os-text: #e5e5e5;           /* Tekst */
--os-text-muted: #888;        /* Secundaire tekst */
--os-accent: #c8a55c;         /* Goud accent (buttons, links, active) */
```

### Modal Pattern
```html
<div class="os-modal" id="xxxModal">
    <div class="os-modal-backdrop" onclick="this.parentElement.classList.remove('open')"></div>
    <div class="os-modal-content">
        <h2>Titel</h2>
        <form method="POST" action="<?= $p ?>/route">
            <!-- form fields -->
            <div class="os-modal-actions">
                <button type="button" class="os-btn" onclick="this.closest('.os-modal').classList.remove('open')">Annuleren</button>
                <button type="submit" class="os-btn os-btn-primary">Opslaan</button>
            </div>
        </form>
    </div>
</div>
```
Open: `element.classList.add('open')`
Sluit: `element.classList.remove('open')`

## JavaScript

### Dashboard (os.js)
- Vanilla JS, geen jQuery, geen frameworks
- Canvas 2D charts — retina-aware met 2x scale
- Inline `<script>` blokken in views voor pagina-specifieke charts
- PHP data naar JS via `json_encode()`:
  ```php
  <script>const data = <?= json_encode($dailyViews) ?>;</script>
  ```

### Engine.js (tracking library)
- Self-contained IIFE
- Configuratie via `<script>` data attributen: `data-page="slug"`
- Visitor ID in cookie `_fvid` (2 jaar TTL)
- Alle events via `POST` naar API met JSON body
- Anti-spam: honeypot veld + minimum 2s submit tijd

## SVG Icons
- Inline SVG in navigatie en buttons
- `width="18" height="18"` voor nav, `width="16" height="16"` voor buttons
- `fill="none" stroke="currentColor" stroke-width="2"`
- Geen icon library — alle icons zijn inline SVG paths
