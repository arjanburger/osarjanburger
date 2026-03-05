<?php
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once dirname(__DIR__) . '/src/config.php';
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (attemptLogin($email, $password)) {
        $prefix = defined('OS_URL_PREFIX') ? OS_URL_PREFIX : '';
        header('Location: ' . $prefix . '/dashboard');
        exit;
    }
    $error = 'Ongeldige inloggegevens.';
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
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
            background: #c8a55c;
            color: #0a0a0a;
            font-weight: 700;
            font-size: 1.1rem;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-logo-text { font-weight: 600; font-size: 1.25rem; }
        .login-title { font-size: 1.1rem; font-weight: 500; margin-bottom: 1.5rem; text-align: center; color: #999; }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; font-size: 0.8rem; color: #888; margin-bottom: 0.4rem; font-weight: 500; }
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
        .form-group input:focus { outline: none; border-color: #c8a55c; }
        .login-btn {
            width: 100%;
            padding: 0.85rem;
            background: #c8a55c;
            color: #0a0a0a;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 0.5rem;
        }
        .login-btn:hover { background: #d4b36a; }
        .login-error {
            background: rgba(220, 50, 50, 0.1);
            border: 1px solid rgba(220, 50, 50, 0.3);
            color: #e55;
            padding: 0.7rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-logo">
            <div class="login-logo-mark">AB</div>
            <span class="login-logo-text">OS</span>
        </div>
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
    </div>
</body>
</html>
