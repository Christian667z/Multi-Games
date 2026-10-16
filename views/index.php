<!DOCTYPE html>
<html lang="fr" data-theme="<?= e($currentTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multi-Games</title>
    <meta name="description" content="Plateforme de jeux multijoueur PvP 4K avec Morpion, modes Local/Bot/Wi-Fi, thèmes dynamiques et assistant Space Core IA.">
    <meta property="og:title" content="Multi-Games">
    <meta property="og:description" content="Plateforme de jeux multijoueur PvP 4K avec Morpion, modes Local/Bot/Wi-Fi, thèmes dynamiques et assistant Space Core IA.">

    <link rel="stylesheet" href="assets/css/themes/themes.css">
    <link rel="stylesheet" href="assets/css/base.css">
    <style><?= $customThemeCss ?></style>

    <script src="assets/js/theme.js"></script>
    <script src="assets/js/audio.js" defer></script>

    <style>
        .hub-hero {
            text-align: center;
            padding: 3.5rem 1rem 2.5rem;
            position: relative;
        }
        .hub-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.15em;
            color: var(--primary);
            text-transform: uppercase;
            background: var(--badge-bg);
            border: 1px solid var(--badge-border);
            padding: 0.35rem 0.9rem;
            border-radius: 9999px;
            margin-bottom: 1rem;
        }
        .hub-title {
            font-size: clamp(2.8rem, 5vw, 4.5rem);
            font-weight: 900;
            letter-spacing: -0.03em;
            color: #fff;
            margin-bottom: 0.8rem;
        }
        .hub-title span {
            color: var(--primary);
        }
        .hub-subtitle {
            font-size: clamp(1rem, 1.8vw, 1.25rem);
            color: var(--text-muted);
            max-width: 650px;
            margin: 0 auto;
            line-height: 1.5;
        }
        .hub-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(clamp(280px, 24vw, 460px), 1fr));
            gap: 1.8rem;
            margin-top: 1.5rem;
        }
        .game-card-link {
            text-decoration: none;
            color: inherit;
        }
        .game-badge {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            letter-spacing: 0.04em;
        }
        .game-badge.active {
            background: rgba(16, 185, 129, 0.2);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.4);
        }
        .game-badge.soon {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-dim);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .game-players {
            font-size: 0.78rem;
            color: var(--text-dim);
            font-family: 'JetBrains Mono', monospace;
        }
    </style>
</head>
<body>

    <!-- Barre supérieure -->
    <header class="topbar">
        <a href="index.php" class="brand-wrapper">
            <div class="brand-icon">
                <span class="x-sym">🎮</span>
            </div>
            <div class="brand-title">Multi-Games <span>PvP Hub</span></div>
        </a>

        <div class="topbar-actions">
            <a class="btn-topbar" href="leaderboard.php">
                <span>🏆</span>
                <span>Classement</span>
            </a>
            <a class="btn-topbar" href="space-core/">Parler à Space</a>
            <?php if ($user): ?>
                <a class="btn-topbar" href="profile.php">
                    <span><?= e($user["avatar"] ?: '🚀') ?></span>
                    <span><?= e($user["display_name"]) ?></span>
                </a>
                <?php if ($user["role"] === 'admin'): ?>
                    <a class="btn-topbar admin-btn" href="admin.php">Admin</a>
                <?php endif; ?>
            <?php else: ?>
                <a class="btn-topbar" href="account.php">Connexion / Inscription</a>
            <?php endif; ?>

            <!-- Bouton Palette -->
            <button class="btn-topbar palette-btn" id="theme-palette-btn" title="Changer de thème">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/><path d="M12 2a7 7 0 0 0 7 7c0 2-2 3-2 3"/>
                </svg>
            </button>

            <!-- Profil classé réel (rang, points, niveau) — calculé par le serveur -->
            <?php
            $hubRank = $user ? mg_rank_for_points((int)($user['ranked_points'] ?? 0)) : null;
            $hubLevel = $user ? mg_level_from_xp((int)($user['xp'] ?? 0)) : null;
            ?>
            <?php if ($user): ?>
                <a href="leaderboard.php" class="level-badge" id="stats-pill" title="Votre rang, vos points et votre niveau — voir le classement" style="text-decoration: none; cursor: pointer;">
                    <span><?= e($hubRank['icon']) ?></span>
                    <span><?= e($hubRank['label']) ?> · <?= e($hubRank['points']) ?> pts · Niv. <?= e($hubLevel['level']) ?></span>
                </a>
            <?php else: ?>
                <a href="account.php" class="level-badge" id="stats-pill" title="Connectez-vous pour être classé" style="text-decoration: none; cursor: pointer;">
                    <span>🔒</span>
                    <span>Mode classé — connexion requise</span>
                </a>
            <?php endif; ?>

            <!-- Paramètres Sélecteur Thème Lisible -->
            <select id="themeSelect" class="btn-topbar theme-selector-select" title="Palette de couleurs">
                <?php foreach ($gameThemes as $theme): ?>
                    <option value="<?= e($theme["theme_key"]) ?>" <?= e($currentTheme === $theme["theme_key"] ? 'selected' : '') ?>>
                        <?= e($theme["name"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </header>

    <!-- Zone Principale -->
    <main class="main-container">
        <div class="hub-hero">
            <div class="hub-tag">⚡ Plateforme de Jeux Multijoueur</div>
            <h1 class="hub-title">MULTI-<span>GAMES</span></h1>
            <p class="hub-subtitle">
                Affrontez vos amis sur le même PC en Local, défiez-les sur le même réseau Wi-Fi ou mesurez-vous à nos IA.
            </p>
        </div>

        <div class="hub-grid">
            <?php foreach ($games as $g): ?>
                <a class="game-card-link" href="<?= e($g["url"]) ?>">
                    <div class="mode-card" style="<?= e(!$g["active"] ? 'opacity: 0.6; filter: grayscale(0.5); cursor: not-allowed;' : '') ?>">
                        <div class="card-top">
                            <span class="game-players"><?= e($g["players"]) ?></span>
                            <span class="game-badge <?= e($g["active"] ? 'active' : 'soon') ?>">
                                <?= e($g["badge"]) ?>
                            </span>
                        </div>

                        <div class="card-body">
                            <div class="card-illustration" style="font-size: 3rem;">
                                <?= e($g["icon"]) ?>
                            </div>

                            <h2 class="card-title"><?= e($g["name"]) ?></h2>
                            <p class="card-desc"><?= e($g["desc"]) ?></p>

                            <div class="card-actions">
                                <?php if ($g["active"]): ?>
                                    <button class="btn-primary-action">
                                        Jouer maintenant ➔
                                    </button>
                                <?php else: ?>
                                    <button class="btn-secondary-action" disabled style="cursor: not-allowed; opacity: 0.7;">
                                        Arrive bientôt 🔒
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </main>

    <footer class="footer">
        © 2026 Space & Multi-Games. Tous droits réservés. Version 4.0
    </footer>

    <script>
        window.MG_CSRF = <?= js_json($csrfToken) ?>;
        // La puce d'en-tête est rendue par le serveur (rang, points, niveau) :
        // plus de compteurs localStorage figés sur « 0 V | 0 D ».
    </script>
    <script src="assets/js/account-presence.js" data-api="games/morpion/api.php" defer></script>

</body>
</html>
