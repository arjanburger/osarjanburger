# ArjanBurger OS

Management platform voor landing pages, klant-tracking en analytics.

## Architectuur

Drie componenten in één repository:

```
ArjanBurgerOS/
├── os/          → Dashboard (privé, login-beschermd)
├── flow/        → Landing pages + tracking engine (publiek)
├── api/         → Tracking API + CRUD endpoints
└── router.php   → Front controller (routeert naar juiste app)
```

### Routing

**Lokaal** (één server op `:8093`): URL prefix-based routing
- `http://192.168.3.135:8093/os/` → Dashboard
- `http://192.168.3.135:8093/flow/` → Landing pages
- `http://192.168.3.135:8093/api/` → API

**Productie**: subdomain-based routing via Caddy
- `os.arjanburger.com` → Dashboard
- `flow.arjanburger.com` → Landing pages + engine.js
- `os.arjanburger.com/api/` → API

De `router.php` bepaalt op basis van hostname of URL prefix welke app geladen wordt. De constante `OS_URL_PREFIX` wordt lokaal op `/os` gezet zodat alle interne links correct zijn.

---

## Infrastructuur

### Lokaal
- **Caddy** als reverse proxy + PHP-FPM
- **PHP-FPM** via unix socket (`/opt/homebrew/var/run/php-fpm.sock`)
- **MySQL** via brew services op `127.0.0.1:3306`
- Database: `arjanburger_os`
- Caddyfile: `try_files {path} /router.php`

### Productie (Hostinger)
- Deploy via git webhook + `deploy.php`
- Caddy met subdomain routing
- Aparte PHP-FPM pool per site

### Configuratie

`.env` bestand (zie `.env.example`):
```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=arjanburger_os
DB_USER=root
DB_PASS=
DEPLOY_SECRET=random_string
```

---

## OS Dashboard

Privé admin interface met sessie-gebaseerde authenticatie (bcrypt).

### Pagina's

| Route | View | Functie |
|-------|------|---------|
| `/dashboard` | `dashboard.php` | Overzicht: stat cards, funnel, activiteit feed, sparkline |
| `/clients` | `clients.php` | Klantenlijst (auto + handmatig), klikbaar naar detail |
| `/clients/{id}` | `client-detail.php` | Klant profiel + volledige bezoekersreis timeline |
| `/pages` | `pages.php` | Landing page beheer |
| `/analytics` | `analytics.php` | Volledige analytics met periode filter |
| `/login` | `login.php` | Inlogscherm |

### Dashboard
- 6 stat cards: pageviews (met trend %), conversies, leads, gem. scroll, gem. tijd, klanten
- Mini conversie funnel (vandaag)
- Live activiteit feed (pageviews, CTAs, forms met relatieve timestamps)
- 7-dagen sparkline area chart
- Recente formulierinzendingen

### Analytics
- **Periode filter**: Vandaag / 7d / 30d / 90d
- **Per-pagina filter**: klik op een pagina om alleen die data te zien
- 5 KPI stat cards
- Pageviews bar chart
- Conversie funnel: Pageviews → Scroll 50%+ → Video play → CTA click → Formulier (met drop-off %)
- Video engagement: plays, completion rate, gem. kijktijd, voortgang bars
- Scroll depth verdeling
- Tijd op pagina distributie
- Verkeersbronnen (referrer breakdown)
- Apparaten (desktop/tablet/mobiel)
- CTA clicks tabel
- UTM campagnes
- Top pagina's

### Klant-detail pagina
- Header met contactgegevens, bron-pagina, status badge
- 4 stat cards: totaal events, tijd op pagina, max scroll, video kijktijd
- **Bezoekersreis timeline**: alle events chronologisch met gekleurde dots, gegroepeerd per datum
  - Toont events van ALLE gekoppelde visitor_ids (cross-domain)
- Formulier inhoud: volledige ingediende velden

### Layout systeem
- `layout.php` → opent HTML, sidebar, header
- `layout-end.php` → sluit content/main/body
- Variabele `$p` = URL prefix (automatisch bepaald)
- Variabele `$uri` = huidige route (voor active nav highlighting)

### CSS
- Donker thema met goud accent (`#C9A84C`)
- Font: Inter (Google Fonts)
- CSS variabelen in `:root` voor alle kleuren
- Responsive breakpoints: 1200px, 768px
- Component classes: `.os-panel`, `.os-stat-card`, `.os-table`, `.os-badge`, `.os-btn`, `.os-modal`, `.os-timeline`, `.os-funnel-step`

---

## Flow Engine (engine.js)

Standalone JavaScript tracking script. Koppel aan elke willekeurige pagina:

```html
<script src="https://flow.arjanburger.com/js/engine.js" data-page="pagina-naam"></script>
```

### Wat het automatisch doet

1. **Visitor ID bepalen** — cookie → URL param → localStorage → nieuw genereren
2. **Cross-domain cookie** — zet `_fvid` op `.arjanburger.com` (werkt op alle subdomeinen)
3. **Pageview tracking** — bij laden: page, url, referrer, UTM, screen, viewport
4. **Scroll depth** — bij 25%, 50%, 75%, 100%
5. **Time on page** — bij verlaten (via Beacon API)
6. **YouTube video tracking** — detecteert iframes, tracked play/progress/complete
7. **Outbound link decoratie** — plakt `?_fvid=xxx` aan externe links
8. **UTM opslag** — bewaart UTM params in sessionStorage

### Data attributen

| Attribuut | Element | Functie |
|-----------|---------|---------|
| `data-page="slug"` | `<script>` | Pagina identificatie |
| `data-api="url"` | `<script>` | Overschrijf API URL |
| `data-debug` | `<script>` | Console logging aan |
| `data-flow-form="id"` | `<form>` | Form tracking + auto-client aanmaak |
| `data-flow-cta="actie"` | elk element | CTA click tracking |
| `data-flow-success` | element in form | Getoond na succesvolle submit |

### Formulier voorbeeld

```html
<form data-flow-form="contact">
    <input type="text" name="naam" required>
    <input type="email" name="email" required>
    <input type="tel" name="telefoon">
    <textarea name="bericht"></textarea>
    <button type="submit">Verstuur</button>
</form>
```

Bij submit:
- Engine.js onderschept de submit, leest alle velden uit
- Stuurt naar `POST /track/form`
- API maakt automatisch klant aan (of update bestaande op basis van email)
- Toont success message in het formulier

### CTA voorbeeld

```html
<a href="#contact" data-flow-cta="hero-aanmelden">Meld je aan</a>
```

Bij klik: stuurt `POST /track/conversion` met action + label.

---

## Cross-Domain Visitor Tracking

Drie lagen die samenwerken:

### Laag 1: Shared cookie op `.arjanburger.com`

Cookie `_fvid` op het root domein. Werkt automatisch op alle subdomeinen.

- **Geldig**: 2 jaar
- **Bereik**: `arjanburger.com`, `flow.arjanburger.com`, `os.arjanburger.com`, etc.
- **Fallback**: localStorage als cookie niet gezet kan worden (IP, localhost)

### Laag 2: URL parameter handoff

Voor externe domeinen (bijv. `hid.dev`): engine.js plakt `?_fvid=xxx` aan uitgaande links. Op het externe domein pikt engine.js de parameter op en verwijdert hem uit de URL.

### Laag 3: Server-side email merge

Bij formulier submit zoekt de API op email. Als het email al bij een andere visitor_id hoort, worden ze gekoppeld via `visitor_aliases`. Dit werkt ook als iemand maanden later terugkomt.

### Visitor ID prioriteit

```
1. Cookie (_fvid)           → Cross-subdomain
2. URL parameter (?_fvid=)  → Van externe link
3. localStorage (flow_vid)  → Domein-specifiek
4. Nieuw genereren          → crypto.randomUUID()
```

Als er meerdere IDs gevonden worden, wordt de eerste als canonical gebruikt en de rest als alias aan de server gemeld.

---

## API

Alle endpoints ontvangen en retourneren JSON. CORS geconfigureerd voor engine.js.

### Tracking endpoints (van engine.js)

| Endpoint | Data |
|----------|------|
| `POST /track/pageview` | page, visitor_id, url, referrer, utm, screen, viewport |
| `POST /track/scroll` | page, visitor_id, depth |
| `POST /track/time` | page, visitor_id, seconds |
| `POST /track/conversion` | page, visitor_id, action, label |
| `POST /track/form` | page, visitor_id, form_id, fields (+ auto-client) |
| `POST /track/video` | page, visitor_id, event, video_id, seconds_watched, duration |
| `POST /track/alias` | canonical_id, alias_ids[] |

### CRUD endpoints (van OS dashboard)

| Endpoint | Functie |
|----------|---------|
| `POST /clients/create` | Klant aanmaken (JSON of form POST) |
| `POST /pages/create` | Landing page aanmaken |
| `GET /health` | Health check |

### Auto-client aanmaak

Bij `POST /track/form`:
- Zoekt email in de ingediende velden (`email`, `e-mail`, `Email`)
- Email gevonden + nieuwe klant → maakt klant aan met status `lead`
- Email gevonden + bestaande klant → update visitor_id, merge aliases
- Geen email → alleen form tracking, geen client

---

## Database

MySQL `arjanburger_os`. Schema in `api/schema.sql`.

### Tabellen

| Tabel | Functie |
|-------|---------|
| `os_users` | Dashboard login (email + bcrypt hash) |
| `clients` | Klanten (auto + handmatig), met `visitor_id` en `source_page` |
| `landing_pages` | Landing page registratie |
| `tracking_pageviews` | Pageview events |
| `tracking_conversions` | CTA click events |
| `tracking_forms` | Formulier submits (velden als JSON) |
| `tracking_scroll` | Scroll depth events (25/50/75/100) |
| `tracking_time` | Tijd op pagina events |
| `tracking_video` | YouTube video events (play/progress/complete) |
| `visitor_aliases` | Cross-domain visitor ID koppeling |

Alle tracking tabellen hebben `page_slug`, `visitor_id`, en `created_at` met index op `(page_slug, created_at)`.

---

## Bestanden overzicht

```
ArjanBurgerOS/
├── router.php                    # Front controller (prefix/subdomain routing)
├── .env                          # Database + deploy config
│
├── os/                           # Dashboard (privé)
│   ├── public/
│   │   ├── index.php             # OS router (auth + routes)
│   │   ├── deploy.php            # Git webhook deploy
│   │   └── assets/css/os.css     # Alle styling
│   ├── src/
│   │   ├── config.php            # DB connectie, constanten
│   │   └── auth.php              # Login/sessie functies
│   └── views/
│       ├── layout.php            # Base layout (sidebar, header)
│       ├── layout-end.php        # Sluit layout
│       ├── login.php             # Login pagina
│       ├── dashboard.php         # Dashboard met stats + feeds
│       ├── clients.php           # Klantenlijst
│       ├── client-detail.php     # Klant profiel + journey
│       ├── pages.php             # Landing pages beheer
│       └── analytics.php         # Volledige analytics
│
├── api/                          # API
│   ├── public/index.php          # Alle endpoints
│   └── schema.sql                # Database schema
│
├── flow/                         # Landing pages (publiek)
│   └── public/
│       ├── deploy.php            # Git webhook deploy
│       ├── js/engine.js          # Tracking engine
│       └── doorbraak/            # Landing page: High Impact Doorbraak
│           ├── index.html
│           ├── style.css
│           └── page.js           # YouTube player + animaties
│
└── docs/
    ├── README.md                 # Dit bestand
    └── cross-domain-tracking.md  # Gedetailleerde tracking docs
```

---

## Nieuwe landing page toevoegen

1. Maak een map in `flow/public/`:
   ```
   flow/public/mijn-pagina/
   ├── index.html
   ├── style.css
   └── page.js (optioneel)
   ```

2. Voeg engine.js toe aan de HTML:
   ```html
   <script src="/js/engine.js" data-page="mijn-pagina"></script>
   ```

3. Voeg optioneel formulieren en CTAs toe met de juiste data attributen.

4. Registreer de pagina in OS dashboard → Landing Pages.

Klaar. Alle tracking werkt automatisch.

---

## Nieuwe pagina op extern domein koppelen

1. Host engine.js zelf of verwijs naar flow:
   ```html
   <script src="https://flow.arjanburger.com/js/engine.js" data-page="externe-pagina"></script>
   ```

2. CORS: voeg het externe domein toe aan `$allowedOrigins` in `api/public/index.php`.

3. Cross-domain tracking werkt automatisch:
   - Cookie sync op subdomeinen van `.arjanburger.com`
   - URL parameter handoff voor andere domeinen
   - Email merge bij formulier submit
