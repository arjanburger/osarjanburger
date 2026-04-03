# ArjanBurger OS

Landing page tracking platform met dashboard. Drie onderdelen: **OS** (dashboard), **Flow** (landing pages + tracking), **API** (data endpoints).

> Gedetailleerde docs in `.claude/docs/`:
> - [codestyle.md](.claude/docs/codestyle.md) — Code stijl, CSS klassen, view patterns
> - [security.md](.claude/docs/security.md) — Auth, CORS, anti-spam, input sanitization
> - [deploy.md](.claude/docs/deploy.md) — Deploy flow, migraties, lokaal draaien
> - [architecture.md](.claude/docs/architecture.md) — Data flow, engine events, hiërarchie
> - [rules.md](.claude/docs/rules.md) — Regels, richtlijnen, nieuwe features toevoegen
>
> Skills in `.claude/skills/`:
> - [deploy-test.md](.claude/skills/deploy-test.md) — **Voer uit na elke deploy.** Test API, tracking, CORS, security headers, engine.js
> - [security-audit.md](.claude/skills/security-audit.md) — Periodieke security check: secrets, CSRF, XSS, headers
> - [add-landing-page.md](.claude/skills/add-landing-page.md) — Stappenplan voor nieuwe landing pages

## Tech Stack

- PHP 8.4 (geen framework, plain templates)
- MySQL 8.0
- Vanilla JS + CSS (dark theme, goud accent `#C9A84C`)
- Caddy (productie), `php -S` (lokaal via `router.php`)
- Emailit API v2 voor magic link emails

## Projectstructuur

```
os/                          # Dashboard (login-protected)
  public/index.php           # Router: auth check + route dispatch
  public/deploy.php          # Deploy webhook: git pull + migraties + seed data
  public/assets/css/os.css   # Dashboard styling
  src/config.php             # DB config, env loading, PDO factory (db())
  src/auth.php               # 2-stap login: wachtwoord + magic link
  views/layout.php           # Base layout met sidebar nav
  views/layout-end.php       # Sluit HTML layout
  views/login.php            # Login formulier
  views/dashboard.php        # Hoofd dashboard met KPIs
  views/clients.php          # Klanten overzicht
  views/client-detail.php    # Klant profiel + journey timeline
  views/pages.php            # Landing pages overzicht (gegroepeerd per product)
  views/page-detail.php      # Landing page breakdown: funnel, leads, scroll, video, CTA
  views/products.php         # Producten overzicht
  views/product-detail.php   # Product rollup: gecombineerde stats van alle pages
  views/analytics.php        # Volledig analytics dashboard

api/
  public/index.php           # Alle tracking + CRUD endpoints
  schema.sql                 # Volledige DB schema

flow/
  public/js/engine.js        # Tracking library (~2000 regels): visitor ID, pageviews,
                             #   scroll, video, forms, conversies, cross-domain cookies
  public/doorbraak/           # Landing page: index.html, style.css, page.js
  public/deploy.php           # Flow deploy webhook

.htaccess                    # Apache routing: subdomein → os/flow/api
router.php                   # Lokale dev front controller
migrate.php                  # CLI database migratie tool
```

## Routing

### Productie (subdomein-based)
- `os.arjanburger.com` → `os/public/index.php`
- `flow.arjanburger.com` → `flow/public/` (statische HTML)
- `os.arjanburger.com/api/` → `api/public/index.php`

### OS Dashboard Routes
Alle routes vereisen authenticatie behalve `/login` en `/verify`.

| Route | View | Beschrijving |
|---|---|---|
| `/login` | login.php | Email + wachtwoord formulier |
| `/verify?token=XXX` | — | Magic link verificatie |
| `/dashboard` | dashboard.php | KPIs, funnel, activiteit |
| `/clients` | clients.php | Klanten lijst + zoeken |
| `/clients/{id}` | client-detail.php | Klant profiel + journey |
| `/pages` | pages.php | Landing pages per product |
| `/pages/{slug}` | page-detail.php | Page breakdown |
| `/products` | products.php | Producten overzicht |
| `/products/{slug}` | product-detail.php | Product rollup |
| `/analytics` | analytics.php | Volledig analytics |

### API Endpoints (POST, JSON)
| Endpoint | Doel |
|---|---|
| `/track/pageview` | Pageview met UTM, referrer, viewport |
| `/track/conversion` | CTA click (action + label) |
| `/track/form` | Formulier submit; maakt auto client aan |
| `/track/scroll` | Scroll diepte (25/50/75/100%) |
| `/track/time` | Tijd op pagina (seconden) |
| `/track/video` | YouTube events (play/progress/complete) |
| `/track/form-interaction` | Form start/progress/abandon |
| `/track/alias` | Visitor ID merging |
| `/clients/create` | Handmatig klant aanmaken |
| `/health` | Health check |

## Database

### Kerntabellen
- **os_users** — Dashboard accounts (email + bcrypt hash)
- **os_login_tokens** — Magic link tokens (15 min expiry)
- **products** — Producten (naam, slug, status)
- **landing_pages** — Geregistreerde pages (slug, url, product_id, status)
- **clients** — Klanten/leads (email, visitor_id, source_page, product_id, status)

### Tracking tabellen (allemaal: page_slug, visitor_id, created_at)
- **tracking_pageviews** — URL, referrer, UTM, screen, viewport, user_agent, language, platform, ip_address, fingerprint
- **tracking_conversions** — action, label, url
- **tracking_forms** — form_id, fields_json
- **tracking_scroll** — depth (0-100)
- **tracking_time** — seconds
- **tracking_video** — event, video_id, seconds_watched, duration
- **tracking_form_interactions** — event (start/progress/abandon), field_count, time_spent
- **visitor_aliases** — canonical_id ↔ alias_id mapping

### Relaties
```
products ──1:N──> landing_pages
products ──1:N──> clients
landing_pages.slug = tracking_*.page_slug
clients.visitor_id = tracking_*.visitor_id
visitor_aliases mergt meerdere visitor_ids naar 1 persoon
```

## Conventies

- **Taal**: UI is Nederlands, code/variabelen Engels
- **CSS klassen**: `os-` prefix (os-panel, os-btn, os-stat-card, os-badge, os-table)
- **Badge statussen**: `os-badge-active`, `os-badge-draft`, `os-badge-live`, `os-badge-lead`
- **Periode filter**: `?period=1|7|30|90` (dagen), default 30
- **Layout pattern**: View begint met PHP logic, dan `require layout.php`, dan HTML, sluit met `require layout-end.php`
- **Forms**: POST naar zelfde URL, handler bovenaan file (voor output), redirect na succes
- **Modals**: `<div class="os-modal" id="xxxModal">` met `.open` class toggle
- **Charts**: Vanilla Canvas 2D (geen library), retina-aware (2x scale)

## Deployment

Push naar `main` → Hostinger webhook → `deploy.php`:
1. `git pull origin main`
2. Maak ontbrekende tabellen aan
3. Voer kolom-migraties uit
4. Maak admin user aan als die niet bestaat
5. Seed product/page data + backfill tracking

## Lokaal draaien

```bash
php -S 127.0.0.1:18093 router.php
# OS: http://127.0.0.1:18093/os/dashboard
# API: http://127.0.0.1:18093/api/health
# Flow: http://127.0.0.1:18093/flow/doorbraak/
```

## Authenticatie

2-stap magic link:
1. Wachtwoord verificatie op `/login`
2. Token generatie + email verzending via Emailit API
3. Klik op link → `/verify?token=XXX` → sessie aangemaakt

## Cross-Domain & Cross-Device Tracking

### Cross-domain (3 lagen)
1. **Shared cookie** `_fvid` op `.arjanburger.com`
2. **URL parameter** `?_fvid=xxx` voor externe domeinen
3. **Server-side merge** via email bij form submit → `visitor_aliases`

### Cross-device tracking
- **User agent** opgeslagen bij elke pageview → browser/OS/device detectie via `parseUserAgent()` in `config.php`
- **Email merge**: zelfde email op 2 devices → visitor_ids gelinkt in `visitor_aliases`
- **Dashboard**: Apparaten/Browsers/OS panels op analytics + page-detail
- **Client-detail**: device cards met "Cross-device" badge bij meerdere devices
