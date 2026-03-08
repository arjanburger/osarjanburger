# Skill: Security Audit

Voer deze skill uit periodiek of na grote wijzigingen. Controleert bekende risico's.

## Checks

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

### 3. Prepared statements (geen raw SQL)
```bash
grep -rn "->exec\|->query" api/public/index.php os/views/*.php | grep -v "deploy.php\|SHOW\|SELECT.*FROM.*tracking\|SELECT.*FROM.*products\|SELECT.*FROM.*landing\|SELECT.*FROM.*clients\|SELECT.*FROM.*os_"
```
**Check:** Geen user input direct in exec/query calls.

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

### 7. Security headers
```bash
curl -s -I "https://os.arjanburger.com/login" 2>&1 | grep -ciE "x-frame-options|x-content-type|strict-transport|referrer-policy"
```
**Check:** 4 headers aanwezig.

### 8. Path traversal bescherming
```bash
grep -c "realpath\|str_starts_with" os/public/index.php
```
**Check:** Minstens 2 matches (realpath + prefix check).

## Bekende open issues
- Hardcoded admin wachtwoord in deploy.php (moet naar .env)
- Geen rate limiting op tracking endpoints
- Login tokens in plaintext in DB (beter: SHA-256 hash)
