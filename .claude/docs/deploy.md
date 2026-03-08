# Deployment

## Flow

```
git push origin main
       ↓
Hostinger webhook
       ↓
os.arjanburger.com/deploy.php?key=SECRET
       ↓
1. git pull origin main
2. Database migraties
3. Seed data
4. JSON response
```

## Deploy Script (`os/public/deploy.php`)

### Authenticatie
- `DEPLOY_SECRET` uit `.env`
- Vergelijking via `hash_equals()`
- 403 bij ongeldig token

### Stappen
1. **Git pull** — `git pull origin main` in project root
2. **Tabel creatie** — Maakt ontbrekende tabellen aan (idempotent)
3. **Kolom migraties** — Voegt ontbrekende kolommen toe
4. **Admin user** — Maakt admin account aan als die niet bestaat
5. **Seed data** — Product + landing page registratie + backfill

### Speciale Acties (via `?action=`)
- `maillog` — Toont mail debug log
- `addenv` — Voegt env variabele toe aan `.env` (`?k=KEY&v=VALUE`)

### Migratie Pattern
```php
// Tabel aanmaken (skip als bestaat)
$exists = $pdo->query("SHOW TABLES LIKE 'tablename'")->rowCount() > 0;
if (!$exists) { $pdo->exec($createSql); }

// Kolom toevoegen (skip als bestaat)
$exists = $pdo->query("SHOW COLUMNS FROM `table` LIKE 'column'")->rowCount() > 0;
if (!$exists) { $pdo->exec($alterSql); }
```

### Response Format
```json
{
    "status": "ok",
    "deployed_at": "2026-03-06 12:00:00",
    "log": [
        { "step": "git_pull", "output": "Already up to date." },
        { "step": "database", "status": "ok", "migrations": ["..."] }
    ]
}
```

## Flow Deploy (`flow/public/deploy.php`)
Simpelere versie — alleen `git pull`, geen database migraties.

## Lokale Ontwikkeling

```bash
# Start dev server
php -S 127.0.0.1:18093 router.php

# URLs
# OS:   http://127.0.0.1:18093/os/dashboard
# API:  http://127.0.0.1:18093/api/health
# Flow: http://127.0.0.1:18093/flow/doorbraak/
```

### Caddy (lokaal, poort 8093)
- Prefix routing: `/os`, `/api`, `/flow`
- `OS_URL_PREFIX = '/os'` in lokale config

## Productie Omgeving

### Hostinger
- **OS**: `os.arjanburger.com` → `os/public/`
- **Flow**: `flow.arjanburger.com` → `flow/public/`
- **API**: `os.arjanburger.com/api/` → `api/public/`
- **Database**: `u813946647_osab`

### .htaccess Routing
```apache
# Subdomein routing
RewriteCond %{HTTP_HOST} ^os\. [NC]
RewriteRule ^(login|verify|dashboard|clients|pages|products|analytics|logout)(.*)$ os/public/index.php [L,QSA]

# API routing
RewriteCond %{HTTP_HOST} ^os\. [NC]
RewriteRule ^api/(.*)$ api/public/index.php [L,QSA]

# Flow: trailing slash redirect (voor relatieve CSS paden)
RewriteCond %{HTTP_HOST} ^flow\. [NC]
RewriteCond %{REQUEST_URI} !/$
RewriteCond %{DOCUMENT_ROOT}/flow/public%{REQUEST_URI}/index.html -f
RewriteRule ^(.*)$ /$1/ [R=301,L]

# Flow: statische bestanden
RewriteCond %{HTTP_HOST} ^flow\. [NC]
RewriteRule ^(.*)$ flow/public/$1 [L]
```

## Nieuwe Migratie Toevoegen

### Nieuwe tabel
1. Voeg toe aan `$tables` array in `deploy.php`
2. Push naar main → webhook runt migratie automatisch

### Nieuwe kolom
1. Voeg toe aan `$columnMigrations` array:
```php
['tabel_naam', 'kolom_naam', "ALTER TABLE tabel_naam ADD COLUMN kolom_naam TYPE DEFAULT NULL"],
```
2. Push naar main

### Seed data
Voeg toe na de kolom migraties sectie met idempotente checks (IF NOT EXISTS pattern).
