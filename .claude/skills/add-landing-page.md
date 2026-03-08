# Skill: Landing Page Toevoegen

Voer deze skill uit wanneer een nieuwe landing page wordt aangemaakt.

## Stappen

### 1. HTML pagina aanmaken
Maak `flow/public/{slug}/index.html` met:
- Engine.js script tag: `<script src="/js/engine.js" data-page="{slug}"></script>`
- Formulier: `<form data-flow-form="{slug}_form">`
- CTA buttons: `<a data-flow-cta="cta_naam">Tekst</a>`
- YouTube iframe (optioneel): automatisch getracked door engine.js

### 2. Registreer in database
Voeg toe aan `deploy.php` seed data sectie:
```php
$lpExists = $pdo->query("SELECT id FROM landing_pages WHERE slug = '{slug}'")->fetch();
if (!$lpExists) {
    $stmt = $pdo->prepare("INSERT INTO landing_pages (title, slug, url, product_id, status) VALUES (?, ?, ?, ?, 'live')");
    $stmt->execute(['{Titel}', '{slug}', 'https://flow.arjanburger.com/{slug}/', $productId]);
}
```

### 3. .htaccess routing
Trailing slash redirect werkt automatisch via bestaande .htaccess regels.
Check: `https://flow.arjanburger.com/{slug}/` moet de pagina laden.

### 4. Deploy + test
- Push naar main
- Trigger deploy webhook
- Bezoek de pagina
- Check in browser: `document.querySelector('script[data-page]').getAttribute('data-page')` === `'{slug}'`
- Check API: pageview data verschijnt in `tracking_pageviews`

### 5. Koppel aan product (optioneel)
Als de page bij een bestaand product hoort:
```sql
UPDATE landing_pages SET product_id = (SELECT id FROM products WHERE slug = '{product_slug}') WHERE slug = '{slug}';
```
