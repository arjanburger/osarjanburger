# Security

## Authenticatie

### 2-Stap Magic Link Login
1. Email + wachtwoord verificatie (`os/src/auth.php: attemptLogin()`)
   - bcrypt hash via `password_verify()`
   - Bij success: genereer 32-byte random token, verwijder oude tokens
   - Sla token op met 15 min expiry
   - Stuur magic link email via Emailit API
2. Klik op magic link → `/verify?token=XXX`
   - Markeert token als `used = 1`, start PHP sessie
   - Redirect naar `/dashboard`

### Sessie Management
- PHP native sessions
- Auth check in `os/public/index.php` voor elke request
- `/logout` → `session_destroy()` + redirect

## API Security

### CORS
```php
$allowedOrigins = [
    'https://flow.arjanburger.com',
    'https://arjanburger.com',
    'http://192.168.3.135:8093',
    'https://hid.dev'
];
```
- Dynamische `Access-Control-Allow-Origin` header
- OPTIONS preflight afgehandeld (204)
- sendBeacon gebruikt `text/plain` (CORS-safelisted, geen preflight)

### Deploy Webhook
- `DEPLOY_SECRET` in `.env` (niet in git)
- Verificatie via `hash_equals($secret, $_GET['key'])`

## Anti-Spam (Form Submit)
- Honeypot: verborgen veld, als ingevuld → spam
- Tijdscheck: submit < 2 seconden → spam

## Input/Output
- `htmlspecialchars()` op alle user content in views
- Prepared statements met `?` placeholders
- `php://input` + `json_decode()` voor API input
- Geen raw user input in SQL strings

## Gevoelige Data
- `.env`, `.env.prod` in `.gitignore`
- Credentials nooit in code of git
- `.env.prod` alleen op Hostinger server

---

## Security Audit Bevindingen (maart 2026)

### KRITIEK
1. **Hardcoded admin wachtwoord** in `deploy.php` (line 270) — zichtbaar in source code
   - TODO: Verplaats naar .env of genereer bij eerste setup
2. **addenv endpoint** in deploy.php kan willekeurige env vars schrijven
   - TODO: Verwijder of beperk tot whitelist
3. **maillog endpoint** dumpt email debug log
   - TODO: Verwijder uit productie

### HOOG
4. **Deploy secret in URL query param** — belandt in server logs
   - TODO: Verplaats naar HTTP header (X-Deploy-Key)
5. **Geen CSRF tokens** op dashboard formulieren
   - TODO: Implementeer per-sessie CSRF token
6. **Geen auth op API CRUD endpoints** (/clients/create, /pages/create)
   - TODO: Voeg sessie/token check toe
7. **Geen rate limiting** op tracking endpoints
   - TODO: Implementeer IP-based rate limiting
8. **CORS localhost check** te breed (`str_contains` i.p.v. hostname match)
   - TODO: Parse origin URL, vergelijk hostname exact

### MEDIUM
9. **Geen session_regenerate_id** bij login — session fixation risico
10. **Sessie cookies** niet gehardened (httponly, secure, samesite)
11. **Geen input validatie** op tracking data (lengte, ranges)
12. **Login tokens** in plaintext in DB (beter: SHA-256 hash)
13. **Path traversal** mogelijk in OS static file serving
14. **flow/deploy.php** retourneert altijd status 'ok' (bug)

### LAAG
15. **Geen security headers** (CSP, X-Frame-Options, HSTS)
16. **PDO error messages** in deploy response (info leakage)
17. **Status badges** niet altijd escaped met htmlspecialchars()
18. **Tracking endpoints** valideren Content-Type niet

### POSITIEF
- Prepared statements consequent gebruikt
- Goede XSS escaping in views
- Timing-safe vergelijking voor deploy key (`hash_equals`)
- Magic link tokens zijn single-use, time-limited
- `.gitignore` correct voor .env files
- `ATTR_EMULATE_PREPARES => false` in PDO config
- Idempotente migraties
