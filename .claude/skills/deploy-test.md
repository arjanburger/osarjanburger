# Skill: Deploy & Test

Voer deze skill uit na elke deploy naar productie. Test alle kritieke systemen zonder in te loggen.

## Stappen

### 1. Deploy triggeren
```bash
DEPLOY_SECRET=$(grep DEPLOY_SECRET .env | cut -d= -f2)
curl -s "https://os.arjanburger.com/deploy.php?key=${DEPLOY_SECRET}" | python3 -m json.tool
```
**Check:** `status: "ok"`, geen `fatal` in git_pull, migraties correct uitgevoerd.

### 2. API Health
```bash
curl -s "https://os.arjanburger.com/api/health"
```
**Check:** `{"status":"ok","version":"0.1.0"}`

### 3. Tracking endpoints (alle 7)
Stuur test-data met visitor_id prefix `_test_deploy_` zodat het herkenbaar is.

```bash
# Pageview (met device data)
curl -s -X POST "https://os.arjanburger.com/api/track/pageview" \
  -H "Content-Type: text/plain" \
  -d '{"page":"doorbraak","visitor_id":"_test_deploy","url":"https://test","screen":"1920x1080","viewport":"1440x900","user_agent":"TestBot/1.0","language":"nl-NL","platform":"Test"}'

# Conversion
curl -s -X POST "https://os.arjanburger.com/api/track/conversion" \
  -H "Content-Type: text/plain" \
  -d '{"page":"doorbraak","visitor_id":"_test_deploy","action":"test_cta","label":"Test"}'

# Scroll
curl -s -X POST "https://os.arjanburger.com/api/track/scroll" \
  -H "Content-Type: text/plain" \
  -d '{"page":"doorbraak","visitor_id":"_test_deploy","depth":50}'

# Time
curl -s -X POST "https://os.arjanburger.com/api/track/time" \
  -H "Content-Type: text/plain" \
  -d '{"page":"doorbraak","visitor_id":"_test_deploy","seconds":30}'

# Video
curl -s -X POST "https://os.arjanburger.com/api/track/video" \
  -H "Content-Type: text/plain" \
  -d '{"page":"doorbraak","visitor_id":"_test_deploy","event":"play","video_id":"test","seconds_watched":10,"duration":60}'

# Form interaction
curl -s -X POST "https://os.arjanburger.com/api/track/form-interaction" \
  -H "Content-Type: text/plain" \
  -d '{"page":"doorbraak","visitor_id":"_test_deploy","form_id":"test","event":"start","fields":{},"field_count":0,"time_spent":0}'

# Alias
curl -s -X POST "https://os.arjanburger.com/api/track/alias" \
  -H "Content-Type: text/plain" \
  -d '{"page":"doorbraak","visitor_id":"_test_deploy","canonical_id":"_test_deploy","alias_ids":["_test_deploy_alias"]}'
```
**Check:** Alle responses `{"ok":true}`

### 4. CORS headers
```bash
curl -s -I -X OPTIONS "https://os.arjanburger.com/api/track/pageview" \
  -H "Origin: https://flow.arjanburger.com" \
  -H "Access-Control-Request-Method: POST" 2>&1 | grep -i "access-control\|HTTP/"
```
**Check:** `Access-Control-Allow-Origin: https://flow.arjanburger.com`, status 204.

### 5. Landing page laadt + engine.js actief
Open `https://flow.arjanburger.com/doorbraak/` in browser.
**Check:**
- Pagina laadt zonder errors
- `document.querySelector('script[data-page]')` retourneert het engine.js script element
- Cookie `_fvid` is gezet

### 6. Security headers
```bash
curl -s -I "https://os.arjanburger.com/login" 2>&1 | grep -iE "x-frame|x-content|strict-transport|referrer-policy"
```
**Check:** X-Frame-Options: DENY, X-Content-Type-Options: nosniff, Strict-Transport-Security, Referrer-Policy aanwezig.

### 7. Flow deploy webhook
```bash
DEPLOY_SECRET=$(grep DEPLOY_SECRET .env | cut -d= -f2)
curl -s "https://flow.arjanburger.com/deploy.php?key=${DEPLOY_SECRET}" | python3 -m json.tool
```
**Check:** `status: "ok"`

### 8. PHP syntax check (lokaal)
```bash
find os/ api/ flow/public/deploy.php -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```
**Check:** Geen output = geen fouten.

## Faal-criteria
- Eén of meer endpoints retourneren geen `{"ok":true}`
- Deploy status is `"error"`
- CORS headers ontbreken
- Security headers ontbreken
- PHP syntax errors

## Na de test
Meld resultaten als samenvatting: hoeveel tests geslaagd, eventuele fouten.
