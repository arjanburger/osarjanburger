<?php
$error = null;
$emailSent = false;

// Check voor verify foutmelding (verlopen/ongeldige magic link)
if (!empty($_SESSION['verify_error'])) {
    $error = $_SESSION['verify_error'];
    unset($_SESSION['verify_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once dirname(__DIR__) . '/src/config.php';
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (attemptLogin($email, $password)) {
        // Wachtwoord klopt → email verstuurd met magic link
        $emailSent = true;
    } else {
        $error = 'Ongeldige inloggegevens.';
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inloggen — <?= OS_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;0,700;1,400;1,500;1,700&family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #0a0a0a;
            color: #e0e0e0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: #141414;
            border: 1px solid #222;
            border-radius: 16px;
            padding: 3rem;
            width: 100%;
            max-width: 400px;
        }
        .login-logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 2rem;
            justify-content: center;
        }
        .login-logo-mark {
            background: #1A3550;
            color: #fff;
            font-weight: 700;
            font-size: 1.1rem;
            width: 42px;
            height: 42px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(201, 168, 76, 0.2);
            letter-spacing: 0.05em;
        }
        .login-logo-text { font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 1rem; letter-spacing: 0.15em; }
        .login-title { font-family: 'Playfair Display', Georgia, serif; font-size: 1.2rem; font-weight: 400; font-style: italic; margin-bottom: 1.5rem; text-align: center; color: #999; }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; font-family: 'Montserrat', sans-serif; font-size: 0.7rem; color: #888; margin-bottom: 0.4rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; }
        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        .form-group input:focus { outline: none; border-color: #C9A84C; }
        .login-btn {
            width: 100%;
            padding: 0.85rem;
            background: #C9A84C;
            color: #0a0a0a;
            border: none;
            border-radius: 6px;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 0.5rem;
        }
        .login-btn:hover { background: #B8963E; }
        .login-error {
            background: rgba(220, 50, 50, 0.1);
            border: 1px solid rgba(220, 50, 50, 0.3);
            color: #e55;
            padding: 0.7rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 1rem;
        }
        .login-success {
            text-align: center;
        }
        .login-success-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .login-success h2 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: #e0e0e0;
        }
        .login-success p {
            color: #888;
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 0.5rem;
        }
        .login-success .email-highlight {
            color: #C9A84C;
            font-weight: 500;
        }
        .login-back {
            display: inline-block;
            margin-top: 1.5rem;
            color: #666;
            font-size: 0.85rem;
            text-decoration: none;
            transition: color 0.2s;
        }
        .login-back:hover { color: #C9A84C; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-logo">
            <svg viewBox="0 0 58 52" width="40" height="36" style="flex-shrink:0"><rect x="0" y="0" width="2" height="52" fill="#C9A84C"/><rect x="6" y="0" width="52" height="52" fill="#1A3550"/><text x="32" y="26" text-anchor="middle" dominant-baseline="central" font-family="Montserrat, sans-serif" font-weight="700" font-size="18" letter-spacing="2" fill="#FDFCFA">AB</text></svg>
            <span class="login-logo-text">OS</span>
        </div>

        <?php if ($emailSent): ?>
            <div class="login-success">
                <div class="login-success-icon">&#9993;</div>
                <h2>Check je e-mail</h2>
                <p>Er is een inloglink verstuurd naar<br><span class="email-highlight"><?= htmlspecialchars($_POST['email'] ?? '') ?></span></p>
                <p>Klik op de link in de e-mail om in te loggen. De link is 15 minuten geldig.</p>
                <a href="<?= defined('OS_URL_PREFIX') ? OS_URL_PREFIX : '' ?>/login" class="login-back">Opnieuw proberen</a>
            </div>
        <?php else: ?>
            <p class="login-title">Log in op je dashboard</p>

            <?php if ($error): ?>
                <div class="login-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="<?= defined('OS_URL_PREFIX') ? OS_URL_PREFIX : '' ?>/login">
                <div class="form-group">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" required autofocus
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="password">Wachtwoord</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="login-btn">Inloggen</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
