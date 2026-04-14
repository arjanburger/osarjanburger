<?php
/**
 * ArjanBurger OS - Base layout
 * Gebruik: $pageTitle, $pageContent (of yield via sections)
 */
$user = currentUser();
$p = defined('OS_URL_PREFIX') ? OS_URL_PREFIX : '';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> — <?= OS_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;0,700;1,400;1,500;1,700&family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $p ?>/assets/css/os.css">
</head>
<body>
    <div class="os-layout">
        <!-- Sidebar -->
        <aside class="os-sidebar">
            <div class="os-sidebar-header">
                <div class="os-logo">
                    <svg viewBox="0 0 58 52" width="32" height="29" style="flex-shrink:0"><rect x="0" y="0" width="2" height="52" fill="#C9A84C"/><rect x="6" y="0" width="52" height="52" fill="#1A3550"/><text x="32" y="26" text-anchor="middle" dominant-baseline="central" font-family="Montserrat, sans-serif" font-weight="700" font-size="18" letter-spacing="2" fill="#FDFCFA">AB</text></svg>
                    <span class="os-logo-text">OS</span>
                </div>
                <span class="os-version">v<?= OS_VERSION ?></span>
            </div>
            <nav class="os-nav">
                <a href="<?= $p ?>/dashboard" class="os-nav-item <?= ($uri ?? '') === '/dashboard' || ($uri ?? '') === '/' ? 'active' : '' ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                    <span>Dashboard</span>
                </a>
                <a href="<?= $p ?>/clients" class="os-nav-item <?= str_starts_with($uri ?? '', '/clients') ? 'active' : '' ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Klanten</span>
                </a>
                <a href="<?= $p ?>/products" class="os-nav-item <?= str_starts_with($uri ?? '', '/products') ? 'active' : '' ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                    <span>Producten</span>
                </a>
                <a href="<?= $p ?>/pages" class="os-nav-item <?= str_starts_with($uri ?? '', '/pages') ? 'active' : '' ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span>Landing Pages</span>
                </a>
                <a href="<?= $p ?>/analytics" class="os-nav-item <?= ($uri ?? '') === '/analytics' ? 'active' : '' ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    <span>Analytics</span>
                </a>
            </nav>
            <div class="os-sidebar-footer">
                <div class="os-user">
                    <div class="os-user-avatar"><?= strtoupper(substr($user['name'] ?? 'A', 0, 1)) ?></div>
                    <div class="os-user-info">
                        <span class="os-user-name"><?= htmlspecialchars($user['name'] ?? '') ?></span>
                        <a href="<?= $p ?>/logout" class="os-user-logout">Uitloggen</a>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main content -->
        <main class="os-main">
            <header class="os-header">
                <h1><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h1>
                <form method="GET" action="<?= $p ?>/search" class="os-header-search" role="search">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="search" name="q" placeholder="Zoek leads, pagina's, producten..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" autocomplete="off">
                </form>
            </header>
            <div class="os-content">
                <?php /* page content gets inserted here */ ?>
