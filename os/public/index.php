<?php
/**
 * ArjanBurger OS - Entry point
 * Router voor het OS dashboard
 */

session_start();

$basePath = dirname(__DIR__);
require_once $basePath . '/src/auth.php';
require_once $basePath . '/src/config.php';

// URL prefix: /os lokaal, / op productie
$urlPrefix = defined('OS_URL_PREFIX') ? OS_URL_PREFIX : '';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';

// Statische bestanden doorlaten
$publicFile = __DIR__ . $uri;
if ($uri !== '/' && is_file($publicFile)) {
    $ext = pathinfo($publicFile, PATHINFO_EXTENSION);
    $mimeTypes = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2'];
    if (isset($mimeTypes[$ext])) header('Content-Type: ' . $mimeTypes[$ext]);
    readfile($publicFile);
    return;
}

// Verify magic link token (2-staps login stap 2)
if ($uri === '/verify') {
    require_once $basePath . '/src/config.php';
    $token = $_GET['token'] ?? '';
    if ($token && verifyLoginToken($token)) {
        header('Location: ' . $urlPrefix . '/dashboard');
        exit;
    }
    // Token ongeldig of verlopen → terug naar login met foutmelding
    $_SESSION['verify_error'] = 'Link is ongeldig of verlopen. Probeer opnieuw in te loggen.';
    header('Location: ' . $urlPrefix . '/login');
    exit;
}

// Auth check (behalve login pagina)
if ($uri !== '/login' && !isAuthenticated()) {
    header('Location: ' . $urlPrefix . '/login');
    exit;
}

// Routes
$routeParam = null;

// Client detail route: /clients/123
if (preg_match('#^/clients/(\d+)$#', $uri, $m)) {
    $uri = '/clients/detail';
    $routeParam = $m[1];
}

switch ($uri) {
    case '/login':
        require $basePath . '/views/login.php';
        break;
    case '/':
    case '/dashboard':
        require $basePath . '/views/dashboard.php';
        break;
    case '/clients':
        require $basePath . '/views/clients.php';
        break;
    case '/clients/detail':
        require $basePath . '/views/client-detail.php';
        break;
    case '/pages':
        require $basePath . '/views/pages.php';
        break;
    case '/analytics':
        require $basePath . '/views/analytics.php';
        break;
    case '/logout':
        session_destroy();
        header('Location: ' . $urlPrefix . '/login');
        exit;
    default:
        http_response_code(404);
        echo '404 - Niet gevonden';
        break;
}
