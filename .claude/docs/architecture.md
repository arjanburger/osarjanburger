# Architectuur

## Overzicht

```
┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│  Flow             │     │  API              │     │  OS Dashboard     │
│  (landing pages)  │────▶│  (tracking data)  │◀────│  (analytics)      │
│                   │     │                   │     │                   │
│  engine.js        │     │  api/public/      │     │  os/views/        │
│  doorbraak/       │     │  index.php        │     │  os/src/          │
└──────────────────┘     └────────┬──────────┘     └──────────────────┘
                                  │
                         ┌────────▼──────────┐
                         │  MySQL Database    │
                         │  arjanburger_os    │
                         └───────────────────┘
```

## Data Flow

### Bezoeker op Landing Page
```
1. Bezoeker opent flow.arjanburger.com/doorbraak/
2. engine.js laadt, leest data-page="doorbraak"
3. Genereert/leest visitor_id (_fvid cookie)
4. POST /track/pageview → tracking_pageviews
5. Bij scroll → POST /track/scroll → tracking_scroll
6. Bij video play → POST /track/video → tracking_video
7. Bij CTA click → POST /track/conversion → tracking_conversions
8. Bij form start → POST /track/form-interaction → tracking_form_interactions
9. Bij form submit → POST /track/form → tracking_forms + clients
10. Bij verlaten → POST /track/time → tracking_time
```

### Auto Client Aanmaak (trackForm)
```
Form submit met email
       ↓
Zoek product_id via landing_pages.slug
       ↓
Email al bekend?
  ├─ Ja → Update client (visitor_id, source_page, product_id)
  │        Merge visitor IDs als anders
  └─ Nee → Nieuwe client (status='lead')
            Met visitor_id, source_page, product_id
```

### Cross-Domain Tracking
```
arjanburger.com                    flow.arjanburger.com
       │                                   │
       │  Shared cookie _fvid              │
       │  op .arjanburger.com              │
       ├───────────────────────────────────┤
       │                                   │
       │  Outbound link decoratie          │
       │  ?_fvid=xxx op externe links      │
       │                                   │
       │  Server-side email merge          │
       │  visitor_aliases tabel            │
       └───────────────────────────────────┘
```

## Hiërarchie

```
Product (bijv. "High Impact Doorbraak")
  └── Landing Page(s) (bijv. /doorbraak, /doorbraak-v2)
       ├── Tracking: pageviews, scroll, video, CTA clicks
       ├── Form interactions: start, progress, abandon
       ├── Form submissions → auto client/lead aanmaak
       └── Clients/Leads (via source_page + product_id)
            └── Journey: alle events via visitor_id
```

## Engine.js Events

| Event | Trigger | API Endpoint | Data |
|---|---|---|---|
| Pageview | Page load | /track/pageview | url, referrer, UTM, screen, viewport |
| Scroll | 25/50/75/100% diepte | /track/scroll | depth |
| Time | Page verlaten (visibilitychange/beforeunload) | /track/time | seconds |
| Video play | YouTube play event | /track/video | event=play, video_id |
| Video progress | 25/50/75% gekeken | /track/video | event=progress_XX, seconds_watched |
| Video complete | 100% gekeken | /track/video | event=complete |
| CTA click | data-track-cta element click | /track/conversion | action, label, url |
| Form start | Eerste veld focus | /track/form-interaction | event=start |
| Form progress | Elk veld ingevuld | /track/form-interaction | event=progress, field_count |
| Form abandon | Page verlaten met onafgemaakt form | /track/form-interaction | event=abandon, fields, time_spent |
| Form submit | Form verstuurd | /track/form | form_id, fields (als JSON) |
| Alias | Cross-domain visitor merge | /track/alias | canonical_id, alias_ids |

## Dashboard Views

### Analytics Breakdown
Alle analytics views gebruiken dezelfde filter structuur:
```php
$periodSql = "created_at >= DATE_SUB(CURDATE(), INTERVAL $periodDays DAY)";
$filterSql = " AND page_slug = " . db()->quote($slug);
```

### Aggregatie Niveaus
- **Dashboard** (`dashboard.php`): Alle data, vandaag + 7 dagen trend
- **Analytics** (`analytics.php`): Alle data of per page, met periode filter
- **Product** (`product-detail.php`): Gecombineerd van alle pages van dit product
- **Page** (`page-detail.php`): Eén specifieke landing page
- **Client** (`client-detail.php`): Eén specifieke bezoeker (via visitor_id)

### Conversie Funnel
Standaard funnel in meerdere views:
```
Pageviews → Scroll 50%+ → Video play → CTA click → Form gestart → Form verstuurd
```
Elke stap toont count + dropoff percentage t.o.v. vorige stap.
