# Technische Infrastructuur

## Hosting
- **Provider**: Hostinger (shared hosting)
- **Document root**: project root (bevat .htaccess)
- **PHP versie**: 8.4
- **MySQL versie**: 8.0

## Domeinen & Routing

### Productie
| Domein | Doel | Routeert naar |
|---|---|---|
| `os.arjanburger.com` | Dashboard | `os/public/index.php` |
| `os.arjanburger.com/api/*` | Tracking API | `api/public/index.php` |
| `flow.arjanburger.com` | Landing pages | `flow/public/` (statisch) |
| `flow.arjanburger.com/doorbraak/` | HID landing page | `flow/public/doorbraak/index.html` |

### Lokaal
```bash
php -S 127.0.0.1:18093 router.php
```
| URL | Doel |
|---|---|
| `http://127.0.0.1:18093/os/dashboard` | Dashboard |
| `http://127.0.0.1:18093/api/health` | API health check |
| `http://127.0.0.1:18093/flow/doorbraak/` | Legacy static landing page (`flow/public/`) |
| `http://127.0.0.1:18102/doorbraak` | Flow Astro dev server (source in `Dev/flow-astro/`, inline editor) |
| `http://127.0.0.1:18093/flow/doorbraak/` | Built output (serveert `flow/public/`, geen editor) |

### Flow Astro (landing pages)
- **Source repo**: `/Users/arjanburger/Dev/flow-astro/` (eigen git repo, los van osarjanburger) — Astro 6, static output
- **Build output**: `ArjanBurgerOS/flow/public/` (via rsync in build script) — dit is wat Hostinger pullt
- **Build + deploy flow**:
  1. `cd /Users/arjanburger/Dev/flow-astro && npm run build` → rsynct naar `../ArjanBurgerOS/flow/public/`
  2. `cd ArjanBurgerOS && git add flow/public && git commit && git push origin main`
  3. Hostinger webhook pullt → live op `flow.arjanburger.com`
- **Dev + editor**: `cd /Users/arjanburger/Dev/flow-astro && npm run dev` (port 18102) — inline editor via `?edit=true` of EDIT-knop → contenteditable → blur → POST `/api/save-content` (alleen in dev)
- Content bestanden: `flow-astro/src/content/{doorbraak,doorbraakexclusive}.json`
- Build-script stript `data-edit` attributen en `edit-mode.js` uit `dist/`, behoudt `flow/public/deploy.php` via rsync `--exclude`

## Database

### Lokaal
- Host: `127.0.0.1:3306`
- Database: `arjanburger_os`
- User: `root` (geen wachtwoord)

### Productie
- Database: `u813946647_osab`
- Credentials in `.env.prod` (NIET in git)

## Deployment

### Automatisch (webhook)
1. Push naar `main` branch
2. Hostinger webhook triggert `deploy.php?key=DEPLOY_SECRET`
3. deploy.php doet: git pull → DB migraties → seed data
4. Response JSON met status

### Handmatig deployen
```bash
DEPLOY_SECRET=$(grep DEPLOY_SECRET .env | cut -d= -f2)
curl "https://os.arjanburger.com/deploy.php?key=${DEPLOY_SECRET}"
```

### Migraties toevoegen
- **Nieuwe tabel**: voeg toe aan `$tables` in `os/public/deploy.php`
- **Nieuwe kolom**: voeg toe aan `$columnMigrations` array
- **Seed data**: voeg toe na kolom migraties met idempotente checks
- Push → webhook → automatisch uitgevoerd

## Environment Variables (.env)
```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=arjanburger_os
DB_USER=root
DB_PASS=
DEPLOY_SECRET=<random>
API_BASE=http://127.0.0.1:18093/api/public
FLOW_DOMAIN=http://127.0.0.1:18093/flow/public
EMAILIT_API_KEY=<key>
```

## Engine.js Tracking Flow
```
Landing page laadt
  → engine.js init met data-page="slug"
  → Visitor ID uit cookie (_fvid) of nieuw genereren
  → sendBeacon (text/plain) naar os.arjanburger.com/api/track/*
  → API parsed JSON body, slaat op in tracking_* tabellen
  → Bij form submit: auto-creates client met product_id
```

## Belangrijke Paden
| Bestand | Doel |
|---|---|
| `.htaccess` | Apache routing (subdomein → directory) |
| `router.php` | Lokale dev front controller |
| `os/public/deploy.php` | Deploy webhook + migraties |
| `os/src/config.php` | DB connectie (db() functie) |
| `os/src/auth.php` | Login + magic link |
| `api/public/index.php` | Alle API endpoints |
| `flow/public/js/engine.js` | Tracking library |
