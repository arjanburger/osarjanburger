<?php
/**
 * ArjanBurger OS - Authenticatie
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

function attemptLogin(string $email, string $password): bool {
    require_once __DIR__ . '/config.php';

    $stmt = db()->prepare('SELECT id, name, email, password_hash FROM os_users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['os_user_id'] = $user['id'];
        $_SESSION['os_user_email'] = $user['email'];
        $_SESSION['os_user_name'] = $user['name'];
        return true;
    }

    return false;
}
