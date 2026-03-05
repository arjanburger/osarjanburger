# Cross-Domain Visitor Tracking

## Overzicht

Engine.js biedt cross-domain visitor tracking via drie lagen die samenwerken:

1. **Shared first-party cookie** — automatisch op alle subdomeinen
2. **URL parameter handoff** — voor links naar externe domeinen
3. **Server-side email merge** — koppelt bezoekers die later terugkomen

## Hoe het werkt

### Laag 1: Cookie op root domein

Engine.js zet een cookie `_fvid` op het root domein (bijv. `.arjanburger.com`).

```
document.cookie = `_fvid=<uuid>;domain=.arjanburger.com;path=/;max-age=63072000;SameSite=Lax;Secure`
```

- **Geldig**: 2 jaar (`max-age=63072000`)
- **Bereik**: alle subdomeinen automatisch (flow., os., www., etc.)
- **Fallback**: als het root domein niet bepaald kan worden (localhost, IP), wordt localStorage gebruikt

**Voorbeeld flow:**
1. Bezoeker komt op `arjanburger.com` → krijgt cookie `_fvid=abc123` op `.arjanburger.com`
2. Bezoeker gaat naar `flow.arjanburger.com` → browser stuurt dezelfde cookie mee
3. Engine.js leest cookie → herkent dezelfde bezoeker → alle tracking events gekoppeld

### Laag 2: URL parameter handoff (externe domeinen)

Cookies werken alleen binnen hetzelfde root domein. Voor links naar externe domeinen (bijv. `hid.dev`) plakt engine.js automatisch de visitor ID aan de URL:

```
https://hid.dev/pagina → https://hid.dev/pagina?_fvid=abc123
```

Dit gebeurt automatisch bij klik op een `<a>` tag naar een extern domein.

Op het externe domein pikt engine.js de `_fvid` parameter op, slaat die lokaal op, en verwijdert de parameter uit de URL (schone URL in de adresbalk).

Als er al een lokale visitor ID bestond, stuurt engine.js een merge-request naar de API (`POST /track/alias`).

### Laag 3: Server-side email merge

Als iemand een formulier invult (email bekend), koppelt de API alle visitor_ids die bij dat email horen:

1. Bezoeker A vult formulier in op `flow.arjanburger.com` met `visitor_id=abc` en `email=jan@test.nl`
2. 6 maanden later bezoekt dezelfde persoon `hid.dev` → krijgt `visitor_id=xyz`
3. Vult opnieuw formulier in met `email=jan@test.nl`
4. API herkent email → koppelt `abc` en `xyz` via `visitor_aliases` tabel
5. Klant-detail pagina toont ALLE events van beide visitor_ids

## Integratie in een nieuw project

### Stap 1: Script toevoegen

```html
<script src="https://flow.arjanburger.com/js/engine.js" data-page="pagina-naam"></script>
```

Dat is alles. Het script regelt automatisch:
- Visitor ID (cookie + localStorage + URL param)
- Pageview tracking
- Scroll depth tracking (25%, 50%, 75%, 100%)
- Time on page tracking
- YouTube video tracking (als er een iframe is)
- Outbound link decoratie (voegt `_fvid` toe aan externe links)

### Stap 2: Formulieren (optioneel)

```html
<form data-flow-form="contact-formulier">
    <input type="text" name="naam" required>
    <input type="email" name="email" required>
    <button type="submit">Verstuur</button>
</form>
```

Het `data-flow-form` attribuut activeert:
- Automatische form submit tracking
- Auto-aanmaak van klant in OS (op basis van email)
- Server-side visitor merge (als email al bekend is)

### Stap 3: CTA tracking (optioneel)

```html
<a href="#contact" data-flow-cta="hero-cta">Neem contact op</a>
<button data-flow-cta="video-play">Bekijk video</button>
```

Elk element met `data-flow-cta` wordt automatisch getracked als conversie.

### Stap 4: Debug mode (optioneel)

```html
<script src="https://flow.arjanburger.com/js/engine.js" data-page="test" data-debug></script>
```

Voeg `data-debug` toe om alle tracking events in de browser console te zien.

## Data attributen

| Attribuut | Element | Functie |
|-----------|---------|---------|
| `data-page="slug"` | `<script>` | Identificeert de pagina in tracking |
| `data-api="url"` | `<script>` | Overschrijft API URL (optioneel) |
| `data-debug` | `<script>` | Zet console logging aan |
| `data-flow-form="id"` | `<form>` | Activeert form tracking + auto-client |
| `data-flow-cta="actie"` | elk element | Tracked clicks als conversie |
| `data-flow-success` | element in form | Getoond na succesvolle submit |

## API Endpoints

Alle data wordt gestuurd naar de OS API:

| Endpoint | Trigger | Data |
|----------|---------|------|
| `POST /track/pageview` | Pagina laden | page, visitor_id, url, referrer, utm, screen, viewport |
| `POST /track/scroll` | Scroll milestone | page, visitor_id, depth (25/50/75/100) |
| `POST /track/time` | Pagina verlaten | page, visitor_id, seconds |
| `POST /track/conversion` | CTA klik | page, visitor_id, action, label |
| `POST /track/form` | Formulier submit | page, visitor_id, form_id, fields |
| `POST /track/video` | YouTube event | page, visitor_id, event, video_id, seconds_watched, duration |
| `POST /track/alias` | Meerdere IDs gevonden | canonical_id, alias_ids[] |

## Visitor ID prioriteit

Bij het bepalen van de visitor ID wordt deze volgorde aangehouden:

```
1. Cookie (_fvid)          → Meest betrouwbaar, werkt cross-subdomain
2. URL parameter (?_fvid=) → Van externe link handoff
3. localStorage (flow_vid) → Domein-specifiek fallback
4. Nieuw genereren         → crypto.randomUUID()
```

Als er meerdere verschillende IDs gevonden worden, wordt de eerste (hoogste prioriteit) als canonical gebruikt en de rest als alias gemeld aan de server.

## Database tabellen

### visitor_aliases
Koppelt meerdere visitor_ids aan dezelfde persoon:

```sql
CREATE TABLE visitor_aliases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    canonical_id VARCHAR(100) NOT NULL,  -- Hoofd visitor ID
    alias_id VARCHAR(100) NOT NULL,      -- Alternatief ID (ander domein/browser)
    source VARCHAR(50),                  -- 'cookie_sync', 'auto_merge', 'email_merge'
    created_at DATETIME
);
```

### clients
Klanten worden automatisch aangemaakt bij form submit:

```sql
-- Relevante kolommen:
visitor_id VARCHAR(100)   -- Gekoppeld aan tracking events
source_page VARCHAR(100)  -- Pagina waar formulier was ingevuld
```

## Beperkingen

- **Andere domeinen zonder engine.js**: URL parameter handoff werkt alleen als engine.js ook op het andere domein draait
- **Incognito/private browsing**: cookies en localStorage worden gewist na sessie
- **Cookie blockers**: sommige adblockers blokkeren third-party-achtige cookies; first-party cookie op root domein wordt zelden geblokkeerd
- **Volledig nieuwe browser**: zonder cookie, URL param of email is er geen koppeling mogelijk
