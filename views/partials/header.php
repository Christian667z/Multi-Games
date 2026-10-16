<?php
$headerSubtitle = $headerSubtitle ?? 'Jeux';
?>
<header class="topbar">
    <a href="<?= e(app_url('/')) ?>" class="brand-wrapper">
        <div class="brand-icon"><span class="x-sym">🎮</span></div>
        <div class="brand-title">Multi-Games <span><?= e($headerSubtitle) ?></span></div>
    </a>
    <div class="topbar-actions">
        <a class="btn-topbar" href="<?= e(app_url('/')) ?>">Hub Jeux</a>
        <a class="btn-topbar" href="<?= e(app_url('/games/morpion/')) ?>">Morpion</a>
        <a class="btn-topbar" href="<?= e(app_url('/leaderboard.php')) ?>">Classement 🏆</a>
        <a class="btn-topbar" href="<?= e(app_url('/space-core/')) ?>">Space Core</a>
        <?php if ($user): ?>
            <a class="btn-topbar" href="<?= e(app_url('/profile.php')) ?>">
                <span><?= e($user['avatar'] ?: '🚀') ?></span>
                <span><?= e($user['display_name']) ?></span>
            </a>
            <form method="post" action="<?= e(app_url('/account.php')) ?>" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="btn-topbar" type="submit">Déconnexion</button>
            </form>
            <?php if ($user['role'] === 'admin'): ?>
                <a class="btn-topbar admin-btn" href="<?= e(app_url('/admin.php')) ?>">Administration</a>
            <?php endif; ?>
        <?php else: ?>
            <a class="btn-topbar" href="<?= e(app_url('/account.php')) ?>">Connexion</a>
        <?php endif; ?>
        <button class="btn-topbar palette-btn" id="theme-palette-btn" title="Changer de thème">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><path d="M12 2a7 7 0 0 0 7 7c0 2-2 3-2 3"/>
            </svg>
        </button>
    </div>
</header>
