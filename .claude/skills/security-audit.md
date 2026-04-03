# Skill: Security Audit

Voer deze skill uit periodiek of na grote wijzigingen. Controleert alle componenten op bekende risico's.

## Uitvoering
- Deze skill wordt uitgevoerd als **standalone subagent** zonder conversatie-context.
- Voer alle checks zelfstandig uit vanuit de project root (`/Users/arjanburger/Dev/ArjanBurgerOS`).
- **Doe geen code wijzigingen.** Alleen controleren en rapporteren.
- Stuur een gestructureerde samenvatting terug naar de hoofdagent met:
  - Per check: **PASS** of **FAIL** + korte toelichting
  - Totaal: X/24 geslaagd
  - Bij failures: exacte output + ernst (kritiek/medium/laag) + suggestie voor fix
  - Nieuwe bevindingen die niet in de checklist staan

---

## A. PHP — Server-side Security

### 1. Geen hardcoded secrets in code
```bash
grep -rn "password\|secret\|api_key\|token" os/public/deploy.php os/src/auth.php api/public/index.php --include="*.php" | grep -v "password_hash\|password_verify\|DEPLOY_SECRET\|csrf_token\|login_token"
```
**Check:** Geen plaintext wachtwoorden of API keys.

### 2. CSRF tokens op formulieren
```bash
grep -rn "csrf_token" os/views/*.php | grep -c "hidden"
```
**Check:** Alle forms met POST hebben een CSRF hidden field.

### 3. Prepared statements (geen raw SQL met user input)
```bash
grep -rn "->exec\|->query" api/public/index.php os/views/*.php | grep -v "deploy.php\|SHOW\|SELECT.*FROM.*tracking\|SELECT.*FROM.*products\|SELECT.*FROM.*landing\|SELECT.*FROM.*clients\|SELECT.*FROM.*os_"
```
**Check:** Geen user input direct in exec/query calls. Alle user-data via `->prepare()` + `->execute()`.

### 4. XSS escaping in views
```bash
grep -rn "<?=" os/views/*.php | grep -v "htmlspecialchars\|number_format\|json_encode\|\$p\|date(\|gmdate(\|\\$period\|\\$pVal\|\\$pLabel\|\\$periodLabel" | head -20
```
**Check:** Alle user-data output gebruikt htmlspecialchars().

### 5. .env niet in git
```bash
git ls-files .env .env.prod 2>/dev/null
```
**Check:** Geen output (bestanden niet getrackt).

### 6. Session hardening actief
```bash
grep -c "cookie_httponly\|cookie_secure\|cookie_samesite\|strict_mode" os/public/index.php
```
**Check:** Minstens 4 matches.

### 7. Path traversal bescherming
```bash
grep -c "realpath\|str_starts_with" os/public/index.php
```
**Check:** Minstens 2 matches (realpath + prefix check).

### 8. PHP error display uitgeschakeld
```bash
grep -rn "display_errors\|error_reporting" os/public/index.php os/src/config.php
```
**Check:** `display_errors` = Off of niet zichtbaar voor gebruikers in productie. Geen stack traces naar de browser.

---

## B. JavaScript — Client-side Security

### 9. Geen secrets in JS bestanden
```bash
grep -rn "api_key\|secret\|password\|token\|Bearer" flow/public/js/ --include="*.js"
```
**Check:** Geen output. JS mag nooit API keys, wachtwoorden of tokens bevatten.

### 10. engine.js input sanitization
```bash
grep -n "textContent\|trim()\|slice(" flow/public/js/engine.js
```
**Check:** CTA labels worden getrimd en afgekapt (`.trim().slice(0, 100)`). Geen ongelimiteerde user input naar API.

### 11. Geen eval() of innerHTML met user input
```bash
grep -rn "eval(\|innerHTML\s*=" flow/public/js/ --include="*.js"
```
**Check:** `innerHTML` alleen voor vaste strings (succes-melding). Nooit met user input. Geen `eval()`.

### 12. Honeypot anti-spam op formulieren
```bash
grep -n "honeypot\|_flow_hp" flow/public/js/engine.js
```
**Check:** Honeypot veld wordt aangemaakt, tijdscontrole (`timeSpent < 2`) actief.

### 13. Cookie security attributen
```bash
grep -n "SameSite\|Secure\|HttpOnly\|max-age" flow/public/js/engine.js
```
**Check:** Cookies gezet met `SameSite=Lax`, `Secure` op HTTPS. `max-age` heeft redelijke waarde.

### 14. Geen gevoelige data in localStorage/sessionStorage
```bash
grep -rn "localStorage\|sessionStorage" flow/public/js/ --include="*.js"
```
**Check:** Alleen `flow_vid` (visitor ID) en `flow_utm` (UTM params) opgeslagen. Geen PII.

---

## C. API — Endpoint Security

### 15. CORS configuratie
```bash
grep -n "Access-Control\|allowedOrigins\|HTTP_ORIGIN" api/public/index.php
```
**Check:** Alleen expliciete origins in allowlist. Geen wildcard `*`. Lokale IPs alleen voor dev.

### 16. CORS headers online testen
```bash
curl -s -I -X OPTIONS "https://os.arjanburger.com/api/track/pageview" \
  -H "Origin: https://evil.com" \
  -H "Access-Control-Request-Method: POST" 2>&1 | grep -i "access-control"
```
**Check:** Geen `Access-Control-Allow-Origin` header voor onbekende origins.

### 17. Input validatie op tracking endpoints
```bash
grep -n "?? ''\|?? null\|?? 0\|in_array" api/public/index.php
```
**Check:** Alle velden hebben defaults. `trackFormInteraction` valideert event enum.

### 18. Geen directory listing
```bash
curl -s -o /dev/null -w "%{http_code}" "https://os.arjanburger.com/api/"
curl -s -o /dev/null -w "%{http_code}" "https://flow.arjanburger.com/js/"
```
**Check:** Geen 200 met directory listing. Moet 403/404 retourneren.

---

## D. Infrastructuur & Headers

### 19. Security headers (online)
```bash
curl -s -I "https://os.arjanburger.com/login" 2>&1 | grep -iE "x-frame-options|x-content-type|strict-transport|referrer-policy"
```
**Check:** 4 headers aanwezig: X-Frame-Options: DENY, X-Content-Type-Options: nosniff, Strict-Transport-Security, Referrer-Policy.

### 20. HTTPS redirect
```bash
curl -s -o /dev/null -w "%{http_code}" "http://os.arjanburger.com/login"
```
**Check:** 301/302 redirect naar HTTPS.

### 21. PHP versie niet gelekt
```bash
curl -s -I "https://os.arjanburger.com/login" 2>&1 | grep -i "x-powered-by"
```
**Check:** Geen `X-Powered-By: PHP` header. Voeg `header_remove('X-Powered-By')` toe indien aanwezig.

### 22. Deploy endpoint beschermd
```bash
curl -s -o /dev/null -w "%{http_code}" "https://os.arjanburger.com/deploy.php"
curl -s -o /dev/null -w "%{http_code}" "https://flow.arjanburger.com/deploy.php"
```
**Check:** 403 Unauthorized zonder geldige key parameter.

---

## E. Landing Pages — Statische Content

### 23. Geen inline scripts met user data
```bash
grep -rn "<script" flow/public/ --include="*.html" | grep -v "engine.js\|youtube"
```
**Check:** Geen inline `<script>` tags met dynamische data. Alleen engine.js en YouTube embeds.

### 24. Externe resources via HTTPS
```bash
grep -rn "http://" flow/public/ --include="*.html" --include="*.css" | grep -v "https://"
```
**Check:** Alle externe resources (fonts, scripts, images) laden via HTTPS.

---

## Bekende open issues
- Hardcoded admin wachtwoord in deploy.php (moet naar .env)
- Geen rate limiting op tracking endpoints
- Login tokens in plaintext in DB (beter: SHA-256 hash)
- IP-adressen opgeslagen: check AVG/GDPR compliance (privacy policy nodig)

---

## Rapportage aan hoofdagent
Retourneer een gestructureerd rapport in dit formaat:

```
## Security Audit Resultaat
**Datum:** YYYY-MM-DD HH:MM
**Status:** ✅ ALLES OK / ⚠️ ISSUES GEVONDEN

### A. PHP — Server-side Security
| # | Check | Status | Details |
|---|-------|--------|---------|
| 1 | Hardcoded secrets | PASS/FAIL | ... |
| ... | ... | ... | ... |

### B. JavaScript — Client-side Security
| # | Check | Status | Details |
|---|-------|--------|---------|
| 9 | Secrets in JS | PASS/FAIL | ... |
| ... | ... | ... | ... |

(herhaal voor C, D, E)

**Totaal:** X/24 geslaagd

### Issues (indien van toepassing)
| Ernst | Check | Probleem | Suggestie |
|-------|-------|----------|-----------|
| 🔴 Kritiek | ... | ... | ... |
| 🟡 Medium | ... | ... | ... |
| 🟢 Laag | ... | ... | ... |

### Nieuwe bevindingen
- Eventuele risico's die niet in de checklist staan
```

**Belangrijk:** Wijzig geen code. Rapporteer alleen bevindingen en suggesties.
