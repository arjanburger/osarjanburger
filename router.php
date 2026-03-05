<?php
/**
 * ArjanBurger OS - Lokale dev router
 *
 * Eén PHP server serveert alles. Routeert op basis van:
 * - hostname (os.arjanburger.dev / flow.arjanburger.dev)
 * - of URL prefix (/os, /flow, /api) als fallback
 *
 * Gebruik: php -S 127.0.0.1:18093 router.php
 */

$host = $_SERVER['HTTP_HOST'] ?? '';
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = __DIR__;

// Bepaal welke app op basis van hostname of prefix
$app = 'flow'; // default

if (str_contains($host, 'os.')) {
    $app = 'os';
} elseif (str_contains($host, 'flow.') || str_contains($host, 'arjanburger.dev') === false) {
    // flow.* of directe IP/localhost → flow
    $app = 'flow';
}

// URL prefix override (voor dev zonder hostname)
if (str_starts_with($uri, '/os/') || $uri === '/os') {
    $app = 'os';
    $uri = substr($uri, 3) ?: '/';
} elseif (str_starts_with($uri, '/api/')) {
    $app = 'api';
    $uri = substr($uri, 4) ?: '/';
} elseif (str_starts_with($uri, '/flow/')) {
    $app = 'flow';
    $uri = substr($uri, 5) ?: '/';
}

// Stel document root in per app
$docRoot = match ($app) {
    'os' => $root . '/os/public',
    'api' => $root . '/api/public',
    'flow' => $root . '/flow/public',
};

// Statische bestanden serveren
$filePath = $docRoot . $uri;
if ($uri !== '/' && is_file($filePath)) {
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    $mimeTypes = [
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
    ];
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }
    readfile($filePath);
    return;
}

// Voor flow: statische HTML pagina's (landing pages)
if ($app === 'flow') {
    // /doorbraak → /doorbraak/index.html
    $htmlPath = $docRoot . $uri . '/index.html';
    if (is_file($htmlPath)) {
        header('Content-Type: text/html');
        readfile($htmlPath);
        return;
    }

    // Root → lijst van pagina's of redirect
    if ($uri === '/') {
        header('Content-Type: text/html');
        echo '<!DOCTYPE html><html><head><title>ArjanBurger Flow</title></head>';
        echo '<body><h1>ArjanBurger Flow</h1><p>Landing pages engine.</p></body></html>';
        return;
    }

    http_response_code(404);
    echo '404 - Page not found';
    return;
}

// Voor os en api: route via index.php
$_SERVER['REQUEST_URI'] = $uri;

// Stel URL prefix in zodat redirects/links correct zijn
// Lokaal (prefix-based routing): /os prefix. Productie (eigen subdomein): leeg.
if (!str_contains($host, 'os.') && !str_contains($host, 'flow.')) {
    define('OS_URL_PREFIX', '/os');
}

require $docRoot . '/index.php';
