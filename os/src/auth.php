<?php
/**
 * ArjanBurger OS - Authenticatie (2-staps: wachtwoord + email magic link)
 */

function isAuthenticated(): bool {
    return !empty($_SESSION['os_user_id']);
}

function currentUser(): ?array {
    if (!isAuthenticated()) return null;
    return [
        'id' => $_SESSION['os_user_id'],
        'email' => $_SESSION['os_user_email'],
        'name' => $_SESSION['os_user_name'],
    ];
}

/**
 * Stap 1: Verifieer wachtwoord, genereer token, stuur email.
 * Retourneert true als wachtwoord klopt (email wordt verstuurd), false als niet.
 */
function attemptLogin(string $email, string $password): bool {
    require_once __DIR__ . '/config.php';

    $stmt = db()->prepare('SELECT id, name, email, password_hash FROM os_users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    // Wachtwoord klopt → genereer magic link token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    // Verwijder oude ongebruikte tokens voor deze user
    $del = db()->prepare('DELETE FROM os_login_tokens WHERE user_id = ? AND used = 0');
    $del->execute([$user['id']]);

    // Sla nieuw token op
    $ins = db()->prepare('INSERT INTO os_login_tokens (user_id, token, expires_at) VALUES (?, ?, ?)');
    $ins->execute([$user['id'], $token, $expiresAt]);

    // Stuur email met magic link
    sendLoginEmail($user['email'], $user['name'], $token);

    return true;
}

/**
 * Stap 2: Verifieer magic link token en maak sessie aan.
 * Retourneert true als token geldig is, false als niet.
 */
function verifyLoginToken(string $token): bool {
    require_once __DIR__ . '/config.php';

    $stmt = db()->prepare('
        SELECT t.id AS token_id, t.user_id, t.expires_at, u.name, u.email
        FROM os_login_tokens t
        JOIN os_users u ON u.id = t.user_id
        WHERE t.token = ? AND t.used = 0
        LIMIT 1
    ');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) return false;

    // Check expiry
    if (strtotime($row['expires_at']) < time()) {
        // Verlopen → markeer als gebruikt
        $upd = db()->prepare('UPDATE os_login_tokens SET used = 1 WHERE id = ?');
        $upd->execute([$row['token_id']]);
        return false;
    }

    // Token geldig → markeer als gebruikt en maak sessie
    $upd = db()->prepare('UPDATE os_login_tokens SET used = 1 WHERE id = ?');
    $upd->execute([$row['token_id']]);

    session_regenerate_id(true);
    $_SESSION['os_user_id'] = $row['user_id'];
    $_SESSION['os_user_email'] = $row['email'];
    $_SESSION['os_user_name'] = $row['name'];

    return true;
}

/**
 * Verstuur login email met magic link via Emailit API v2.
 */
function sendLoginEmail(string $email, string $name, string $token): void {
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST'];
    $prefix = defined('OS_URL_PREFIX') ? OS_URL_PREFIX : '';
    $link = $baseUrl . $prefix . '/verify?token=' . $token;

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: 'Inter', Arial, sans-serif; background: #0a0a0a; color: #e0e0e0; padding: 2rem;">
    <div style="max-width: 480px; margin: 0 auto; background: #141414; border: 1px solid #222; border-radius: 16px; padding: 2.5rem;">
        <div style="text-align: center; margin-bottom: 1.5rem;">
            <span style="background: #c8a55c; color: #0a0a0a; font-weight: 700; font-size: 1.1rem; width: 40px; height: 40px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center;">AB</span>
            <span style="font-weight: 600; font-size: 1.25rem; margin-left: 0.5rem;">OS</span>
        </div>
        <p style="color: #999; margin-bottom: 1.5rem;">Hoi {$name},</p>
        <p style="margin-bottom: 1.5rem;">Klik op de knop hieronder om in te loggen op ArjanBurger OS. Deze link is 15 minuten geldig.</p>
        <div style="text-align: center; margin: 2rem 0;">
            <a href="{$link}" style="background: #c8a55c; color: #0a0a0a; padding: 0.85rem 2rem; border-radius: 8px; font-weight: 600; text-decoration: none; display: inline-block;">Inloggen</a>
        </div>
        <p style="color: #666; font-size: 0.85rem;">Of kopieer deze link:<br><a href="{$link}" style="color: #c8a55c; word-break: break-all;">{$link}</a></p>
        <hr style="border: none; border-top: 1px solid #222; margin: 1.5rem 0;">
        <p style="color: #555; font-size: 0.8rem;">Als je dit niet hebt aangevraagd, kun je deze email negeren.</p>
    </div>
</body>
</html>
HTML;

    $apiKey = getenv('EMAILIT_API_KEY') ?: '';
    $payload = json_encode([
        'from' => 'ArjanBurger OS <ab@arjanburger.com>',
        'to' => $email,
        'subject' => 'Je inloglink — ArjanBurger OS',
        'html' => $html,
    ]);

    $ch = curl_init('https://api.emailit.com/v2/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log resultaat
    $logFile = dirname(__DIR__, 2) . '/mail_debug.log';
    file_put_contents($logFile, date('Y-m-d H:i:s') . " emailit($email) http=$httpCode response=$response\n", FILE_APPEND);
}
