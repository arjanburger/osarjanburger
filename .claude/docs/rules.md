# Regels & Richtlijnen

## Algemeen

- **Geen frameworks** — Plain PHP, vanilla JS, vanilla CSS. Geen React, geen Laravel, geen jQuery.
- **Geen externe libraries** — Geen Chart.js, geen Tailwind. Charts zijn Canvas 2D, styling is handgeschreven CSS.
- **Eenvoud** — Zo min mogelijk abstractie. Geen ORM, geen template engine, geen build tools.
- **Nederlands UI** — Alle gebruikersgerichte tekst in het Nederlands.
- **Engelse code** — Variabelen, functies, database kolommen in het Engels.

## PHP Regels

- Gebruik `db()` voor database connectie (singleton in config.php)
- Altijd prepared statements voor user input
- POST handlers BOVEN `require layout.php` (headers already sent probleem)
- Redirect na succesvolle POST (PRG pattern)
- `try/catch PDOException` rond alle queries, fallback naar lege arrays
- `htmlspecialchars()` op alle output van user data
- Gebruik `$p` (OS_URL_PREFIX) voor alle interne links

## CSS Regels

- Gebruik bestaande `os-*` klassen waar mogelijk
- Dark theme: achtergrond `#0a0a0a`, panels `#141414`, accent `#c8a55c`
- Geen inline styles tenzij echt nodig (bijv. dynamische kleuren in funnels)
- Responsive via CSS grid, geen media queries tenzij nodig

## JavaScript Regels

- Vanilla JS — geen build step, geen modules
- Charts via Canvas 2D met retina support (2x scale)
- PHP data naar JS via `<?= json_encode($data) ?>`
- Geen externe CDN scripts behalve Google Fonts

## Database Regels

- Alle tabellen: `ENGINE=InnoDB`
- Tracking tabellen: altijd `page_slug`, `visitor_id`, `created_at` met index
- Kolommen: `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- Soft deletes: gebruik `status` kolom, niet DELETE
- Migraties: idempotent (IF NOT EXISTS / check before ALTER)

## Git Regels

- Push naar `main` triggert auto-deploy
- Geen feature branches nodig (solo project)
- Commits in het Engels
- `.env` bestanden NOOIT in git

## Nieuwe Features Toevoegen

### Nieuwe View
1. Maak `os/views/naam.php` aan
2. Volg view pattern: POST handler → $pageTitle → require layout → queries → HTML → require layout-end
3. Voeg route toe in `os/public/index.php` switch
4. Voeg toe aan `.htaccess` clean URLs pattern
5. Optioneel: nav item in `layout.php`

### Nieuwe Tracking Event
1. Voeg handler toe in `api/public/index.php`
2. Voeg match case toe in router
3. Maak tabel aan in deploy.php `$tables` array
4. Implementeer in `engine.js`

### Nieuwe Database Tabel
1. Voeg CREATE TABLE toe aan `$tables` in deploy.php
2. Push → auto-deploy maakt tabel aan

### Nieuwe Kolom op Bestaande Tabel
1. Voeg toe aan `$columnMigrations` in deploy.php
2. Push → auto-deploy voegt kolom toe
