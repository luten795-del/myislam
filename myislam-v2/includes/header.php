<?php
/**
 * Shared page header/layout - include this from every page instead of
 * copy-pasting the <head>, top nav, and sidebar markup.
 *
 * WHY THIS FILE EXISTS
 * Right now every page (content.php, and presumably subjects.php,
 * profile.php, etc.) carries its own copy of ~1900 lines of CSS plus the
 * header/nav/sidebar HTML. That means:
 *   - Any CSS fix (like the dark-mode text-color fixes we just made) has to
 *     be hand-copied into every single page, and it's easy to miss one.
 *   - Every page download re-sends the same ~1900 lines of CSS instead of
 *     the browser caching it once.
 *   - Adding PWA support (manifest link + service worker registration) has
 *     to be repeated on every page instead of being added in one place.
 *
 * HOW TO USE THIS FILE
 * Before requiring this file, the calling page should set the variables
 * below (all optional except $pageTitle - sensible defaults are used for
 * the rest). Example, at the top of content.php:
 *
 *     $pageTitle       = $pageTitle; // already computed above
 *     $metaDescription = $content['short_description'] ?? '';
 *     $metaKeywords    = $content['tags'] ?? 'learning';
 *     $metaAuthor      = $content['author_name'] ?? 'MyIslam';
 *     $ogType          = 'article';
 *     require __DIR__ . '/includes/header.php';
 *
 * This file prints everything through the opening
 * <main class="main-content" id="mainContent"> tag. The calling page then
 * prints its own content, closes </main> itself (since only the calling
 * page knows what else - like an aside - needs to sit inside
 * .page-layout before it closes), and finally requires footer.php.
 *
 * OPTIONAL VARIABLES with safe defaults - the calling page's own nav/user
 * data (from your existing auth/session/navigation-building code) is used
 * when present; if a page doesn't set one of these yet, it degrades
 * gracefully instead of throwing an undefined-variable warning:
 *   $mainNavigation, $sidebarNavigation, $unreadCount, $userInfo,
 *   $userDisplayName
 */

$pageTitle         = $pageTitle ?? 'MyIslam';
$metaDescription   = $metaDescription ?? '';
$metaKeywords      = $metaKeywords ?? 'learning';
$metaAuthor        = $metaAuthor ?? 'MyIslam';
$ogType            = $ogType ?? 'website';
$htmlLang          = $htmlLang ?? 'en';
$htmlDir           = $htmlDir ?? 'ltr';
$bodyClass         = $bodyClass ?? '';
$mainNavigation    = $mainNavigation ?? [];
$sidebarNavigation = $sidebarNavigation ?? [];
$unreadCount       = $unreadCount ?? 0;
$userInfo          = $userInfo ?? [];
$userDisplayName   = $userDisplayName ?? 'User';

// The lines above already guarantee every one of these variables exists by
// this point (PHP's ?? never throws on an undefined variable - that's the
// one operator exempt from the undefined-variable notice). If your editor
// still underlines $userDisplayName / $userInfo / $mainNavigation /
// $sidebarNavigation further down this file, that's your IDE's static
// analyzer being unable to see past the ?? on its own line - it's a lint
// warning, not a PHP runtime error, and safe to ignore.
?>
<!DOCTYPE html>
<html lang="<?php echo e($htmlLang); ?>" dir="<?php echo e($htmlDir); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="<?php echo e($metaDescription); ?>">
    <meta name="keywords" content="<?php echo e($metaKeywords); ?>">
    <meta name="author" content="<?php echo e($metaAuthor); ?>">
    <meta name="robots" content="index, follow">

    <meta property="og:title" content="<?php echo e($pageTitle); ?>">
    <meta property="og:description" content="<?php echo e($metaDescription); ?>">
    <meta property="og:type" content="<?php echo e($ogType); ?>">
    <meta property="og:url" content="https://<?php echo e($_SERVER['HTTP_HOST'] ?? 'localhost'); ?><?php echo e($_SERVER['REQUEST_URI'] ?? ''); ?>">
    <meta property="og:site_name" content="MyIslam">

    <title><?php echo e($pageTitle); ?></title>

    <!-- GLightbox for image lightbox -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css" />

    <!-- Shared theme CSS - was previously ~1900 lines duplicated inline on
         every page. Now one file, cacheable once by the browser AND by the
         service worker. Edit /assets/css/theme.css to change styles on
         every page at once. -->
    <link rel="stylesheet" href="/assets/css/theme.css">

    <!-- PWA: manifest link, service worker registration, and the offline-
         lesson storage layer (offline-db.js). This is the SAME include
         used by nearly every other page in the site (subjects.php,
         subject.php, login.php, the admin panel, etc.) - using it here
         too means there's exactly one place that governs PWA registration
         site-wide, instead of this file duplicating that logic. -->
    <?php require __DIR__ . '/pwa.php'; ?>
</head>
<body class="<?php echo e($bodyClass); ?>">

<a href="#mainContent" class="skip-link">Skip to main content</a>

<header class="app-header">
    <div class="header-left">
        <a href="subjects.php" class="brand-box" style="display:flex;align-items:center;gap:4px;">
            <img src="includes/logo.png" alt="MyIslam" style="height:50px;width:50px;border-radius:50%;border:2px solid var(--brand);object-fit:cover;padding:2px;">
            <span style="font-size:22px;font-weight:1000;color:var(--brand);">MyIslam</span>
        </a>
        <nav class="header-top-nav" aria-label="Main navigation">
            <?php foreach ($mainNavigation as $navItem): ?>
                <a href="<?php echo e($navItem['link']); ?>" class="header-top-link <?php echo $navItem['active'] ? 'active' : ''; ?>">
                    <?php echo e($navItem['name']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="header-right">
        <button class="icon-btn" id="themeToggle" aria-label="Dark mode">☀</button>
        <button class="lang-btn" id="langToggle">EN</button>
        <a href="notification.php" class="icon-btn" style="position: relative;" aria-label="Notifications">
            🔔
            <?php if ($unreadCount > 0): ?>
                <span class="notification-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
            <?php endif; ?>
        </a>
        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" type="button" aria-label="Open profile menu" aria-expanded="false">
                <?php if (!empty($userInfo['picture'])): ?>
                    <img src="<?php echo e($userInfo['picture']); ?>" alt="Profile">
                <?php else: ?>
                    <?php echo e(initials($userDisplayName)); ?>
                <?php endif; ?>
            </button>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($userInfo['picture'])): ?>
                        <img src="<?php echo e($userInfo['picture']); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?php echo e(initials($userDisplayName)); ?></div>
                    <?php endif; ?>
                    <div>
                        <h4><?php echo e($userDisplayName); ?></h4>
                        <p><?php echo e($userInfo['email'] ?? ''); ?></p>
                        <p style="font-size: 10px;">Member since <?php echo e($userInfo['member_since'] ?? 'recently'); ?></p>
                    </div>
                </div>
                <a href="profile.php">👤 <?php echo t('profile'); ?></a>
                <a href="settings.php">⚙️ <?php echo t('settings'); ?></a>
                <a href="logout.php">🚪 <?php echo t('logout'); ?></a>
            </div>
        </div>
    </div>
</header>

<button class="sidebar-toggle-btn" id="menuToggle" aria-label="Menu">☰</button>

<div class="page-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-title">📚 Navigation</div>
        <nav class="subject-list">
            <?php foreach ($sidebarNavigation as $item): ?>
                <a href="<?php echo e($item['link']); ?>" class="subject-link <?php echo $item['active'] ? 'active' : ''; ?>">
                    <span><?php echo e($item['icon']); ?></span>
                    <span><?php echo e($item['name']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="main-content" id="mainContent">