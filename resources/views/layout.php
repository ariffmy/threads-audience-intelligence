<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= $e($title) ?> · Threads Audience Intelligence</title>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/vendor/bootstrap-5.3.8.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
<?php if (isset($identity) && $identity !== null): ?>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <div class="app-shell">
        <aside class="sidebar" aria-label="Application navigation">
            <a href="/dashboard" class="brand"><span class="brand-mark">t<span>i</span></span><span>THREADS<br><small>AUDIENCE INTELLIGENCE</small></span></a>
            <div class="workspace-chip"><span class="status-dot"></span> Personal workspace <span class="workspace-tag">01</span></div>
            <button class="mobile-menu" type="button" aria-expanded="false" aria-controls="primary-navigation">Navigation <span aria-hidden="true">☰</span></button>
            <div id="primary-navigation" class="navigation-content">
                <p class="nav-label">WORKSPACE</p>
                <nav class="nav flex-column gap-1">
                    <?php foreach (['/dashboard' => ['Dashboard', '◫'], '/profile' => ['Account & profile', '◎'], '/settings' => ['Application settings', '⚙'], '/status' => ['System status', '◷']] as $url => [$label, $icon]): ?>
                        <a class="nav-link <?= $title === $label ? 'active' : '' ?>" href="<?= $e($url) ?>" <?= $title === $label ? 'aria-current="page"' : '' ?>><span aria-hidden="true" class="nav-symbol"><?= $e($icon) ?></span><?= $e($label) ?></a>
                    <?php endforeach; ?>
                </nav>
                <p class="nav-label future-label">COMING IN LATER PHASES</p>
                <div class="future-navigation"><span>Threads connections</span><span>Discussion analytics</span><span>Audience reports</span></div>
                <div class="sidebar-note"><span class="phase-light">PHASE 01</span><p>A secure foundation for understanding your audience.</p></div>
                <div class="sidebar-account">
                    <span class="avatar"><?= $e(mb_strtoupper(mb_substr($identity['display_name'], 0, 1))) ?></span>
                    <div><strong><?= $e($identity['display_name']) ?></strong><small>Administrator</small></div>
                    <form action="/logout" method="post"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="logout-button" title="Sign out" aria-label="Sign out" type="submit">↗</button></form>
                </div>
            </div>
        </aside>
        <div class="main-shell">
            <header class="topbar"><span class="topbar-breadcrumb">Workspace <span>/</span> <strong><?= $e($title) ?></strong></span><span class="private-label"><span class="status-dot"></span> PRIVATE APPLICATION</span></header>
            <main id="main-content" class="main-content">
                <?php if (!empty($flash)): ?><div class="alert alert-success" role="status"><?= $e($flash) ?></div><?php endif; ?>
                <?= $content ?>
            </main>
            <footer class="app-footer"><span>Threads Audience Intelligence</span><span>Personal use · Phase 1</span></footer>
        </div>
    </div>
<?php else: ?>
    <?= $content ?>
<?php endif; ?>
</body>
</html>
