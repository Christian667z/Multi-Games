<!DOCTYPE html>
<html lang="fr" data-theme="<?= e($currentTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classement des Joueurs - Multi-Games</title>
    <meta name="description" content="Classement officiel des joueurs de Multi-Games : scores, victoires, ratios et podium mondial.">

    <link rel="stylesheet" href="assets/css/themes/themes.css">
    <link rel="stylesheet" href="assets/css/base.css">
    <style><?= $customThemeCss ?></style>

    <script src="assets/js/theme.js"></script>
    <script src="assets/js/audio.js" defer></script>
</head>
<body>

    <!-- Barre supérieure -->
    <header class="topbar">
        <a href="index.php" class="brand-wrapper">
            <div class="brand-icon">
                <span class="x-sym">🏆</span>
            </div>
            <div class="brand-title">Multi-Games <span>Classement</span></div>
        </a>

        <div class="topbar-actions">
            <a class="btn-topbar" href="index.php">Hub Jeux</a>
            <a class="btn-topbar" href="games/morpion/index.php">Morpion</a>
            <a class="btn-topbar" href="space-core/">Parler à Space</a>

            <?php if ($user): ?>
                <a class="btn-topbar" href="account.php">
                    <span><?= e($user["avatar"] ?: '🚀') ?></span>
                    <span><?= e($user["display_name"]) ?></span>
                </a>
                <?php if ($user["role"] === 'admin'): ?>
                    <a class="btn-topbar admin-btn" href="admin.php">Admin</a>
                <?php endif; ?>
            <?php else: ?>
                <a class="btn-topbar" href="account.php">Connexion</a>
            <?php endif; ?>

            <!-- Bouton Palette -->
            <button class="btn-topbar palette-btn" id="theme-palette-btn" title="Changer de thème">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/><path d="M12 2a7 7 0 0 0 7 7c0 2-2 3-2 3"/>
                </svg>
            </button>
        </div>
    </header>

    <main class="main-container">
        <div class="hub-hero" style="padding-bottom: 1.5rem;">
            <div class="hub-tag">🏆 Tableau d'Honneur &amp; Compétition</div>
            <h1 class="hub-title">CLASSEMENT <span>GLOBAL</span></h1>
            <p class="hub-subtitle">
                Comparez vos victoires, vos points d'arcade et votre ratio face à tous les joueurs de la communauté Multi-Games.
            </p>
        </div>

        <?php if ($user && $userRank): ?>
            <div style="background: color-mix(in srgb, var(--primary) 12%, rgba(18, 20, 26, 0.9)); border: 1px solid var(--primary); border-radius: 1rem; padding: 1rem 1.5rem; margin-bottom: 2rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div style="font-size: 2.2rem;"><?= e($user["avatar"] ?: '🚀') ?></div>
                    <div>
                        <div style="font-weight: 800; font-size: 1.15rem; color: #fff;">
                            <?= e($user["display_name"]) ?> <span style="font-weight: 400; font-size: 0.85rem; color: var(--text-muted);">(Votre rang)</span>
                        </div>
                        <div style="color: var(--text-muted); font-size: 0.88rem;">
                            Position actuelle : <strong style="color: var(--primary);">#<?= e($userRank) ?></strong> sur <?= e(count($leaderboard)) ?> joueurs classés
                        </div>
                    </div>
                </div>
                <div style="display: flex; gap: 1.2rem; align-items: center;">
                    <?php if ($userStats): ?>
                        <div style="text-align: right;">
                            <div style="font-family: 'JetBrains Mono', monospace; font-size: 1.2rem; font-weight: 800; color: #34d399;"><?= e($userStats["wins"]) ?> V | <?= e($userStats["losses"]) ?> D</div>
                            <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Ratio <?= e($userStats["win_rate"]) ?>% • <?= e($userStats["points"]) ?> pts</div>
                        </div>
                    <?php endif; ?>
                    <a href="games/morpion/index.php" class="btn-primary-action" style="width: auto; padding: 0.55rem 1.2rem; font-size: 0.85rem;">
                        Grimper au classement ⚡
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- PODIUM TOP 3 -->
        <?php if (count($leaderboard) >= 3): ?>
            <div class="leaderboard-podium">
                <!-- 2ème Place -->
                <div class="podium-card rank-2">
                    <div class="podium-rank-crown">🥈</div>
                    <div class="podium-avatar"><?= e($leaderboard[1]["avatar"]) ?></div>
                    <div class="podium-name"><?= e($leaderboard[1]["display_name"]) ?></div>
                    <div class="podium-points"><?= e($leaderboard[1]["points"]) ?> pts</div>
                    <div class="podium-stats-sub"><?= e($leaderboard[1]["wins"]) ?>V • <?= e($leaderboard[1]["losses"]) ?>D • <?= e($leaderboard[1]["win_rate"]) ?>%</div>
                </div>

                <!-- 1ère Place (Vainqueur) -->
                <div class="podium-card rank-1">
                    <div class="podium-rank-crown">👑</div>
                    <div class="podium-avatar"><?= e($leaderboard[0]["avatar"]) ?></div>
                    <div class="podium-name" style="font-size: 1.35rem; color: #facc15;"><?= e($leaderboard[0]["display_name"]) ?></div>
                    <div class="podium-points" style="font-size: 1.5rem;"><?= e($leaderboard[0]["points"]) ?> pts</div>
                    <div class="podium-stats-sub"><?= e($leaderboard[0]["wins"]) ?>V • <?= e($leaderboard[0]["losses"]) ?>D • <?= e($leaderboard[0]["win_rate"]) ?>%</div>
                </div>

                <!-- 3ème Place -->
                <div class="podium-card rank-3">
                    <div class="podium-rank-crown">🥉</div>
                    <div class="podium-avatar"><?= e($leaderboard[2]["avatar"]) ?></div>
                    <div class="podium-name"><?= e($leaderboard[2]["display_name"]) ?></div>
                    <div class="podium-points"><?= e($leaderboard[2]["points"]) ?> pts</div>
                    <div class="podium-stats-sub"><?= e($leaderboard[2]["wins"]) ?>V • <?= e($leaderboard[2]["losses"]) ?>D • <?= e($leaderboard[2]["win_rate"]) ?>%</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- TABLEAU COMPLET DU CLASSEMENT -->
        <div class="leaderboard-table-wrapper">
            <table class="history-table">
                <thead>
                    <tr>
                        <th style="width: 70px; text-align: center;">Rang</th>
                        <th>Joueur</th>
                        <th style="text-align: center;">Points</th>
                        <th style="text-align: center;">Parties</th>
                        <th style="text-align: center;">Victoires</th>
                        <th style="text-align: center;">Défaites</th>
                        <th style="text-align: center;">Nuls</th>
                        <th style="text-align: center;">Ratio</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($leaderboard) === 0): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                                Aucune partie enregistrée pour le moment. Jouez une partie pour inaugurer le classement !
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($leaderboard as $entry): ?>
                            <tr class="<?= e($user && $user["id"] === $entry["id"] ? 'leaderboard-row-me' : '') ?>">
                                <td class="leaderboard-rank-col">
                                    <?php if ($entry["rank"] === 1): ?>
                                        <span title="1er" style="color: #facc15;">🥇</span>
                                    <?php elseif ($entry["rank"] === 2): ?>
                                        <span title="2ème" style="color: #cbd5e1;">🥈</span>
                                    <?php elseif ($entry["rank"] === 3): ?>
                                        <span title="3ème" style="color: #d97706;">🥉</span>
                                    <?php else: ?>
                                        <span style="color: var(--text-dim); font-size: 0.95rem;">#<?= e($entry["rank"]) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center;">
                                        <span class="user-inline-avatar"><?= e($entry["avatar"]) ?></span>
                                        <strong><?= e($entry["display_name"]) ?></strong>
                                        <?php if ($entry["role"] === 'admin'): ?>
                                            <span style="font-size: 0.68rem; background: var(--badge-bg); color: var(--badge-text); border: 1px solid var(--badge-border); padding: 0.15rem 0.45rem; border-radius: 9999px; margin-left: 0.5rem;">ADMIN</span>
                                        <?php endif; ?>
                                        <?php if ($user && $user["id"] === $entry["id"]): ?>
                                            <span style="font-size: 0.68rem; background: rgba(52, 211, 153, 0.2); color: #34d399; border: 1px solid rgba(52, 211, 153, 0.35); padding: 0.15rem 0.45rem; border-radius: 9999px; margin-left: 0.5rem;">VOUS</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="text-align: center; font-family: 'JetBrains Mono', monospace; font-weight: 800; color: var(--primary);">
                                    <?= e($entry["points"]) ?>
                                </td>
                                <td style="text-align: center; color: var(--text-muted);">
                                    <?= e($entry["total_games"]) ?>
                                </td>
                                <td style="text-align: center; font-weight: 700; color: #34d399;">
                                    <?= e($entry["wins"]) ?>
                                </td>
                                <td style="text-align: center; font-weight: 700; color: #f87171;">
                                    <?= e($entry["losses"]) ?>
                                </td>
                                <td style="text-align: center; color: #fbbf24;">
                                    <?= e($entry["ties"]) ?>
                                </td>
                                <td style="text-align: center;">
                                    <span style="font-weight: 800; font-family: 'JetBrains Mono', monospace; color: #60a5fa;"><?= e($entry["ratio"] ?: '0.00') ?></span>
                                    <div style="font-size: 0.72rem; color: <?= e($entry["win_rate"] >= 60 ? '#34d399' : ($entry["win_rate"] >= 40 ? '#fbbf24' : '#a1a1aa')) ?>; font-weight: 600;">
                                        <?= e($entry["win_rate"]) ?>%
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 2rem; text-align: center;">
            <p style="font-size: 0.85rem; color: var(--text-dim);">
                💡 Les points sont calculés selon le barème officiel : +3 points par victoire, +1 point par match nul.
            </p>
        </div>
    </main>

    <footer class="footer">
        © 2026 Multi-Games &amp; Space. Classement officiel mis à jour en temps réel.
    </footer>

</body>
</html>
