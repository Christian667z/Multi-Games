<!DOCTYPE html>
<html lang="fr" data-theme="<?= e($currentTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compte Joueur - Multi-Games</title>
    <meta name="description" content="Espace personnel joueur Multi-Games : statistiques complètes, historique des parties, avatar, et sécurité.">

    <link rel="stylesheet" href="assets/css/themes/themes.css">
    <link rel="stylesheet" href="assets/css/base.css">
    <style><?= $customThemeCss ?></style>

    <script src="assets/js/theme.js"></script>
    <script src="assets/js/audio.js" defer></script>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="brand-wrapper">
            <div class="brand-icon"><span class="x-sym">🎮</span></div>
            <div class="brand-title">Multi-Games <span>Compte Joueur</span></div>
        </a>
        <div class="topbar-actions">
            <a class="btn-topbar" href="index.php">Hub Jeux</a>
            <a class="btn-topbar" href="games/morpion/index.php">Morpion</a>
            <a class="btn-topbar" href="leaderboard.php">Classement 🏆</a>
            <a class="btn-topbar" href="space-core/">Space Core</a>

            <?php if ($user && $user["role"] === 'admin'): ?>
                <a class="btn-topbar admin-btn" href="admin.php">Administration</a>
            <?php endif; ?>

            <?php if ($user): ?>
                <form method="post" action="account.php" class="inline-form" style="margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="btn-topbar" type="submit">Déconnexion</button>
                </form>
            <?php endif; ?>

            <button class="btn-topbar palette-btn" id="theme-palette-btn" title="Changer de thème">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/><path d="M12 2a7 7 0 0 0 7 7c0 2-2 3-2 3"/>
                </svg>
            </button>
        </div>
    </header>

    <main class="main-container account-page" style="padding-top: 1.5rem;">
        <?php if ($error): ?>
            <div class="account-alert" role="alert">⚠️ <?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="account-success" role="status">✅ <?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($user): ?>
            <div class="account-card" style="max-width: 1050px;">
                <!-- En-tête profil -->
                <div class="account-hero-header">
                    <div class="account-avatar-large" id="current-user-avatar"><?= e($user["avatar"] ?: '🚀') ?></div>
                    <div class="account-hero-info">
                        <div class="account-hero-name">
                            <span><?= e($user["display_name"]) ?></span>
                            <?php if ($user["role"] === 'admin'): ?>
                                <span style="font-size: 0.72rem; background: var(--badge-bg); color: var(--badge-text); border: 1px solid var(--badge-border); padding: 0.2rem 0.6rem; border-radius: 9999px;">ADMINISTRATEUR</span>
                            <?php else: ?>
                                <span style="font-size: 0.72rem; background: rgba(255,255,255,0.08); color: var(--text-muted); border: 1px solid rgba(255,255,255,0.12); padding: 0.2rem 0.6rem; border-radius: 9999px;">JOUEUR</span>
                            <?php endif; ?>
                            <span style="font-size: 0.78rem; background: rgba(250, 204, 21, 0.15); color: #facc15; border: 1px solid rgba(250, 204, 21, 0.35); padding: 0.2rem 0.6rem; border-radius: 9999px;">Rang #<?= e($userRank) ?></span>
                            <span style="font-size: 0.78rem; background: rgba(96, 165, 250, 0.15); color: #93c5fd; border: 1px solid rgba(96, 165, 250, 0.35); padding: 0.2rem 0.6rem; border-radius: 9999px;">Ratio V/D : <?= e($playerStats["ratio"]) ?></span>
                        </div>
                        <div class="account-hero-email"><?= e($user["email"]) ?></div>
                        <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 0.3rem;">
                            Membre depuis le <?= e(date('j', (int) $user['created_at']) . ' ' . array('janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre')[(int) date('n', (int) $user['created_at']) - 1] . ' ' . date('Y', (int) $user['created_at'])) ?>
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.6rem; flex-wrap: wrap;">
                        <a href="games/morpion/index.php" class="btn-primary-action" style="width: auto; padding: 0.6rem 1.2rem; font-size: 0.85rem;">
                            Jouer une partie ⚡
                        </a>
                        <a href="leaderboard.php" class="btn-secondary-action" style="width: auto; padding: 0.6rem 1.2rem; font-size: 0.85rem;">
                            Voir classement 🏆
                        </a>
                    </div>
                </div>

                <!-- Onglets -->
                <div class="account-tabs" role="tablist">
                    <button class="account-tab-btn active" data-tab="tab-stats" role="tab" aria-selected="true">
                        <span>📊</span> Statistiques
                    </button>
                    <button class="account-tab-btn" data-tab="tab-history" role="tab" aria-selected="false">
                        <span>📜</span> Historique des parties (<?= e(count($gameHistory)) ?>)
                    </button>
                    <button class="account-tab-btn" data-tab="tab-settings" role="tab" aria-selected="false">
                        <span>⚙️</span> Profil &amp; Sécurité
                    </button>
                    <button class="account-tab-btn" data-tab="tab-leaderboard" role="tab" aria-selected="false">
                        <span>🏆</span> Classement Top 10
                    </button>
                </div>

                <!-- CONTENU ONGLET 1 : STATISTIQUES DÉTAILLÉES -->
                <div class="account-tab-content active" id="tab-stats">
                    <!-- Bannière de détection de scores locaux à synchroniser -->
                    <div id="local-sync-banner" class="stat-sync-box" style="display: none;">
                        <div class="stat-sync-info">
                            <span style="font-size: 1.25rem;">🔄</span>
                            <div>
                                <strong>Des parties jouées sur ce navigateur n'ont pas encore été synchronisées.</strong>
                                <div style="font-size: 0.78rem; opacity: 0.85;" id="local-sync-detail">Fusionnez vos victoires locales dans votre compte en un clic.</div>
                            </div>
                        </div>
                        <button type="button" class="btn-sync-stats" onclick="syncBrowserStats()">
                            Synchroniser maintenant ➔
                        </button>
                    </div>

                    <div class="stats-header-banner">
                        <div class="hub-tag" style="margin: 0;">Performances &amp; Bilan Compétitif</div>
                        <div style="display: flex; gap: 0.6rem; align-items: center; font-size: 0.84rem;">
                            <?php if ($playerStats["current_streak"] > 1): ?>
                                <span style="background: rgba(239, 68, 68, 0.18); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.35); padding: 0.25rem 0.65rem; border-radius: 9999px; font-weight: 700;">
                                    🔥 <?= e($playerStats["current_streak"]) ?> Victoires d'affilée
                                </span>
                            <?php endif; ?>
                            <?php if ($playerStats["best_streak"] > 1): ?>
                                <span style="background: rgba(250, 204, 21, 0.15); color: #facc15; border: 1px solid rgba(250, 204, 21, 0.3); padding: 0.25rem 0.65rem; border-radius: 9999px; font-size: 0.8rem;">
                                    ⚡ Record : <?= e($playerStats["best_streak"]) ?> d'affilée
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 1. GRILLE KPI PRINCIPALE : VICTOIRES, DÉFAITES, RATIO, TAUX, NULS, POINTS -->
                    <div class="stats-kpi-grid">
                        <!-- KPI 1 : VICTOIRES -->
                        <div class="stat-kpi-card kpi-wins">
                            <div class="stat-kpi-top">
                                <span class="stat-kpi-title">Victoires</span>
                                <span class="stat-kpi-icon">🏆</span>
                            </div>
                            <div class="stat-kpi-number" id="kpi-val-wins"><?= e($playerStats["wins"]) ?></div>
                            <div class="stat-kpi-sub">
                                <span>Total des victoires remportées</span>
                            </div>
                        </div>

                        <!-- KPI 2 : DÉFAITES -->
                        <div class="stat-kpi-card kpi-losses">
                            <div class="stat-kpi-top">
                                <span class="stat-kpi-title">Défaites</span>
                                <span class="stat-kpi-icon">❌</span>
                            </div>
                            <div class="stat-kpi-number" id="kpi-val-losses"><?= e($playerStats["losses"]) ?></div>
                            <div class="stat-kpi-sub">
                                <span>Parties concédées</span>
                            </div>
                        </div>

                        <!-- KPI 3 : RATIO V/D (MIS EN VALEUR) -->
                        <div class="stat-kpi-card kpi-ratio">
                            <div class="stat-kpi-top">
                                <span class="stat-kpi-title" style="color: #93c5fd;">Ratio V / D</span>
                                <span class="stat-kpi-icon">⚖️</span>
                            </div>
                            <div class="stat-kpi-number" id="kpi-val-ratio"><?= e($playerStats["ratio"]) ?></div>
                            <div class="stat-kpi-sub">
                                <?php $rNum = (float)($playerStats['ratio'] ?? 0); ?>
                                <?php if ($playerStats["losses"] === 0 && $playerStats["wins"] > 0): ?>
                                    <span style="color: #34d399; font-weight: 700;">Invaincu (0 défaite)</span>
                                <?php elseif ($rNum >= 2.0): ?>
                                    <span style="color: #60a5fa; font-weight: 700;">Excellente domination 🔥</span>
                                <?php elseif ($rNum >= 1.0): ?>
                                    <span style="color: #34d399; font-weight: 700;">Ratio positif ⚡</span>
                                <?php elseif ($playerStats["completed"] > 0): ?>
                                    <span style="color: #fbbf24; font-weight: 700;">En progression 🎯</span>
                                <?php else: ?>
                                    <span>Aucune partie terminée</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- KPI 4 : TAUX DE VICTOIRE -->
                        <div class="stat-kpi-card kpi-winrate">
                            <div class="stat-kpi-top">
                                <span class="stat-kpi-title">Taux de Victoire</span>
                                <span class="stat-kpi-icon">🎯</span>
                            </div>
                            <div class="stat-kpi-number" id="kpi-val-winrate"><?= e($playerStats["win_rate"]) ?>%</div>
                            <div class="stat-kpi-sub">
                                <span>Sur <?= e($playerStats["wins"] + $playerStats["losses"] + $playerStats["ties"]) ?> parties jouées</span>
                            </div>
                        </div>

                        <!-- KPI 5 : MATCHS NULS -->
                        <div class="stat-kpi-card">
                            <div class="stat-kpi-top">
                                <span class="stat-kpi-title">Matchs Nuls</span>
                                <span class="stat-kpi-icon">🤝</span>
                            </div>
                            <div class="stat-kpi-number" style="color: #fbbf24;" id="kpi-val-ties"><?= e($playerStats["ties"]) ?></div>
                            <div class="stat-kpi-sub">
                                <span>Égalités parfaites</span>
                            </div>
                        </div>

                        <!-- KPI 6 : POINTS AU CLASSEMENT -->
                        <div class="stat-kpi-card">
                            <div class="stat-kpi-top">
                                <span class="stat-kpi-title">Points Classement</span>
                                <span class="stat-kpi-icon">🎖️</span>
                            </div>
                            <div class="stat-kpi-number" style="color: var(--primary);"><?= e($playerStats["points"]) ?></div>
                            <div class="stat-kpi-sub">
                                <span>3 pts / Victoire, 1 pt / Nul</span>
                            </div>
                        </div>
                    </div>

                    <!-- 2. BARRE VISUELLE DU RATIO ET RÉPARTITION DES RÉSULTATS -->
                    <?php $totalFinishedGames = $playerStats['wins'] + $playerStats['losses'] + $playerStats['ties']; $pctWins = $totalFinishedGames > 0 ? round(($playerStats['wins'] / $totalFinishedGames) * 100) : 0; $pctTies = $totalFinishedGames > 0 ? round(($playerStats['ties'] / $totalFinishedGames) * 100) : 0; $pctLosses = $totalFinishedGames > 0 ? (100 - $pctWins - $pctTies) : 0; ?>
                    <div class="ratio-bar-card">
                        <div class="ratio-bar-header">
                            <div class="ratio-bar-title">
                                <span>📊</span> Répartition Globale des Résultats
                            </div>
                            <div style="font-size: 0.82rem; color: var(--text-muted);">
                                <strong><?= e($totalFinishedGames) ?></strong> parties terminées au total
                            </div>
                        </div>

                        <!-- Piste segmentée -->
                        <div class="ratio-bar-track" title="Victoires: <?= e($pctWins) ?>% | Nuls: <?= e($pctTies) ?>% | Défaites: <?= e($pctLosses) ?>%">
                            <?php if ($totalFinishedGames === 0): ?>
                                <div style="width: 100%; height: 100%; background: rgba(255,255,255,0.08);"></div>
                            <?php else: ?>
                                <div class="ratio-seg-wins" style="width: <?= e($pctWins) ?>%;"></div>
                                <div class="ratio-seg-ties" style="width: <?= e($pctTies) ?>%;"></div>
                                <div class="ratio-seg-losses" style="width: <?= e($pctLosses) ?>%;"></div>
                            <?php endif; ?>
                        </div>

                        <!-- Légende détaillée -->
                        <div class="ratio-bar-legend">
                            <div class="ratio-legend-item">
                                <span class="ratio-dot wins"></span>
                                <span>Victoires : <strong style="color: #34d399;"><?= e($playerStats["wins"]) ?></strong> (<?= e($pctWins) ?>%)</span>
                            </div>
                            <div class="ratio-legend-item">
                                <span class="ratio-dot ties"></span>
                                <span>Matchs nuls : <strong style="color: #fbbf24;"><?= e($playerStats["ties"]) ?></strong> (<?= e($pctTies) ?>%)</span>
                            </div>
                            <div class="ratio-legend-item">
                                <span class="ratio-dot losses"></span>
                                <span>Défaites : <strong style="color: #f87171;"><?= e($playerStats["losses"]) ?></strong> (<?= e($pctLosses) ?>%)</span>
                            </div>
                        </div>
                    </div>

                    <!-- 3. CARTES D'ANALYSE DÉTAILLÉES PAR MODE DE JEU -->
                    <div class="mode-stats-grid">
                        <!-- Mode 1 : Contre l'IA Space -->
                        <div class="mode-stat-card">
                            <div class="mode-stat-header">
                                <div class="mode-stat-title">
                                    <span>🤖</span> Contre l'IA Space
                                </div>
                                <span class="mode-stat-badge">Solo vs Bot</span>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 0.55rem;">
                                <div class="mode-stat-row">
                                    <span>Ratio V / D</span>
                                    <strong style="color: #60a5fa;"><?= e($playerStats["modes"]["bot"]["ratio"] ?? '0.00') ?></strong>
                                </div>
                                <div class="mode-stat-row">
                                    <span>Victoires</span>
                                    <strong style="color: #34d399;"><?= e($playerStats["modes"]["bot"]["wins"] ?? 0) ?></strong>
                                </div>
                                <div class="mode-stat-row">
                                    <span>Défaites</span>
                                    <strong style="color: #f87171;"><?= e($playerStats["modes"]["bot"]["losses"] ?? 0) ?></strong>
                                </div>
                                <div class="mode-stat-row">
                                    <span>Matchs nuls</span>
                                    <strong style="color: #fbbf24;"><?= e($playerStats["modes"]["bot"]["ties"] ?? 0) ?></strong>
                                </div>
                                <div class="mode-stat-row">
                                    <span>Taux de réussite</span>
                                    <strong style="color: #facc15;"><?= e($playerStats["modes"]["bot"]["win_rate"] ?? 0) ?>%</strong>
                                </div>
                                <div class="mode-stat-row">
                                    <span>Parties lancées</span>
                                    <strong><?= e($playerStats["bot_games"]) ?></strong>
                                </div>
                            </div>
                        </div>

                        <!-- Mode 2 : Duels Wi-Fi LAN -->
                        <div class="mode-stat-card">
                            <div class="mode-stat-header">
                                <div class="mode-stat-title">
                                    <span>📶</span> Duels Wi-Fi LAN
                                </div>
                                <span class="mode-stat-badge">Multijoueur</span>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 0.55rem;">
                                <div class="mode-stat-row">
                                    <span>Ratio V / D</span>
                                    <strong style="color: #60a5fa;"><?= e($playerStats["modes"]["wifi"]["ratio"] ?? '0.00') ?></strong>
                                </div>
                                <div class="mode-stat-row">
                                    <span>Victoires</span>
                                    <strong style="color: #34d399;"><?= e($playerStats["modes"]["wifi"]["wins"] ?? 0) ?></strong>
                                </div>
                                <div class="mode-stat-row">
                                    <span>Défaites</span>
                                    <strong style="color: #f87171;"><?= e($playerStats["modes"]["wifi"]["losses"] ?? 0) ?></strong>
                                </div>
                                <div class="mode-stat-row">
                                    <span>Matchs nuls</span>
                                    <strong style="color: #fbbf24;"><?= e($playerStats["modes"]["wifi"]["ties"] ?? 0) ?></strong>
                                </div>
                                <div class="mode-stat-row">
                                    <span>Taux de réussite</span>
                                    <strong style="color: #facc15;"><?= e($playerStats["modes"]["wifi"]["win_rate"] ?? 0) ?>%</strong>
                                </div>
                                <div class="mode-stat-row">
                                    <span>Parties lancées</span>
                                    <strong><?= e($playerStats["wifi_games"]) ?></strong>
                                </div>
                            </div>
                        </div>

                        <!-- Mode 3 : 1v1 Local -->
                        <div class="mode-stat-card">
                            <div class="mode-stat-header">
                                <div class="mode-stat-title">
                                    <span>👥</span> 1v1 Local
                                </div>
                                <span class="mode-stat-badge">Même écran</span>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 0.55rem;">
                                <div class="mode-stat-row">
                                    <span>Ratio V / D</span>
                                    <strong style="color: #60a5fa;"><?= e($playerStats["modes"]["local"]["ratio"] ?? '0.00') ?></strong>
                                </div>
                                <div class="mode-stat-row">
                                    <span>Victoires</span>
                                    <strong style="color: #34d399;"><?= e($playerStats["modes"]["local"]["wins"] ?? 0) ?></strong>
                                </div>
                                <div class="mode-stat-row">
                                    <span>Défaites</span>
                                    <strong style="color: #f87171;"><?= e($playerStats["modes"]["local"]["losses"] ?? 0) ?></strong>
                                </div>
                                <div class="mode-stat-row">
                                    <span>Matchs nuls</span>
                                    <strong style="color: #fbbf24;"><?= e($playerStats["modes"]["local"]["ties"] ?? 0) ?></strong>
                                </div>
                                <div class="mode-stat-row">
                                    <span>Taux de réussite</span>
                                    <strong style="color: #facc15;"><?= e($playerStats["modes"]["local"]["win_rate"] ?? 0) ?>%</strong>
                                </div>
                                <div class="mode-stat-row">
                                    <span>Parties lancées</span>
                                    <strong><?= e($playerStats["local_games"]) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 4. PERFORMANCES CONTRE CHAQUE NIVEAU D'IA -->
                    <div style="background: var(--card-inner); border: 1px solid var(--card-border); border-radius: 1rem; padding: 1.3rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
                            <h3 style="font-size: 1.05rem; font-weight: 700; color: #fff; margin: 0;">
                                🧬 Détail des Affrontements par Niveau de Difficulté
                            </h3>
                            <span style="font-size: 0.8rem; color: var(--text-dim);">Mémoire d'IA : <?= e($botStats["games_learned"]) ?> parties apprises</span>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.9rem;">
                            <?php $levelsMeta = [['key'=>'novice','label'=>'Novice','emoji'=>'??','color'=>'#34d399'],['key'=>'medium','label'=>'Interm?diaire','emoji'=>'??','color'=>'#60a5fa'],['key'=>'advanced','label'=>'Avanc?','emoji'=>'??','color'=>'#a78bfa'],['key'=>'legend','label'=>'L?gende','emoji'=>'??','color'=>'#facc15'],['key'=>'adaptive','label'=>'?volutif','emoji'=>'??','color'=>'#f43f5e']]; ?>
                            <?php foreach ($levelsMeta as $lvl): ?>
                                <?php $bStat = $playerStats['bot_level_breakdown'][$lvl['key']] ?? ['wins'=>0,'losses'=>0,'ties'=>0,'ratio'=>'0.00','win_rate'=>0]; ?>
                                <div style="background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.06); border-radius: 0.75rem; padding: 0.85rem;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                                        <span style="font-weight: 700; font-size: 0.88rem; color: <?= e($lvl["color"]) ?>;"><?= e($lvl["emoji"]) ?> <?= e($lvl["label"]) ?></span>
                                        <span style="font-size: 0.75rem; color: var(--text-dim);">Ratio <?= e($bStat["ratio"]) ?></span>
                                    </div>
                                    <div style="font-family: 'JetBrains Mono', monospace; font-size: 1rem; font-weight: 800; color: #fff; margin-bottom: 0.2rem;">
                                        <span style="color: #34d399;"><?= e($bStat["wins"]) ?>V</span> - <span style="color: #f87171;"><?= e($bStat["losses"]) ?>D</span>
                                        <?php if ($bStat["ties"] > 0): ?>
                                            - <span style="color: #fbbf24;"><?= e($bStat["ties"]) ?>N</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 0.76rem; color: var(--text-muted);">
                                        <?= e($bStat["win_rate"]) ?>% de victoires
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- CONTENU ONGLET 2 : HISTORIQUE DES PARTIES -->
                <div class="account-tab-content" id="tab-history">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
                        <div class="hub-tag" style="margin: 0;">Journal des dernières confrontations</div>
                        <span style="font-size: 0.82rem; color: var(--text-muted);"><?= e(count($gameHistory)) ?> parties enregistrées</span>
                    </div>

                    <?php if (count($gameHistory) === 0): ?>
                        <div style="text-align: center; padding: 3rem 1rem; background: var(--card-inner); border-radius: 1rem; border: 1px solid var(--card-border);">
                            <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">🎲</div>
                            <h3 style="color: #fff; font-weight: 700; margin-bottom: 0.4rem;">Aucune partie enregistrée</h3>
                            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.2rem;">Lancez une partie en local, contre l'IA ou en Wi-Fi pour commencer votre historique.</p>
                            <a href="games/morpion/index.php" class="btn-primary-action" style="width: auto; margin: 0 auto; padding: 0.6rem 1.5rem;">Jouer maintenant</a>
                        </div>
                    <?php else: ?>
                        <div class="history-table-wrapper">
                            <table class="history-table">
                                <thead>
                                    <tr>
                                        <th>Date &amp; Heure</th>
                                        <th>Mode de Jeu</th>
                                        <th>Format Grille</th>
                                        <th style="text-align: center;">Résultat</th>
                                        <th style="text-align: right;">Durée</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($gameHistory as $game): ?>
                                        <tr>
                                            <td style="color: var(--text-muted); font-family: 'JetBrains Mono', monospace; font-size: 0.82rem; white-space: nowrap;">
                                                <?= e($game["formatted_date"]) ?>
                                            </td>
                                            <td>
                                                <strong><?= e($game["modeName"]) ?></strong>
                                            </td>
                                            <td style="color: var(--text-muted);">
                                                <?= e($game["grid_desc"]) ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <span class="badge-outcome <?= e($game["outcomeBadge"]) ?>">
                                                    <?= e($game["outcomeLabel"]) ?>
                                                </span>
                                            </td>
                                            <td style="text-align: right; color: var(--text-dim); font-family: 'JetBrains Mono', monospace; font-size: 0.82rem;">
                                                <?= e($game["duration"] ? $game["duration"] . 's' : '—') ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- CONTENU ONGLET 3 : PARAMÈTRES DU COMPTE -->
                <div class="account-tab-content" id="tab-settings">
                    <div class="hub-tag" style="margin-bottom: 1.2rem;">Personnalisation &amp; Sécurité</div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap: 1.4rem; align-items: start;">
                        <!-- 1. Changer d'Avatar (Boutons Radio Fiables) -->
                        <div class="account-form">
                            <h3 class="card-title" style="font-size: 1.15rem;">Changer d'Avatar</h3>
                            <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 0.5rem;">Choisissez l'icône qui vous représentera dans les parties et au classement.</p>
                            <form method="post" action="account.php">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="update_avatar">

                                <div class="avatar-grid">
                                    <?php foreach ($availableAvatars as $av): ?>
                                        <label class="avatar <?= e(($user["avatar"] ?: '🚀') === $av ? 'active' : '') ?>" title="Choisir <?= e($av) ?>">
                                            <input type="radio" name="avatar" value="<?= e($av) ?>" <?= e(($user["avatar"] ?: '🚀') === $av ? 'checked' : '') ?> onchange="document.getElementById('current-user-avatar').textContent = this.value;">
                                            <span><?= e($av) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <button class="btn-primary-action" type="submit" style="margin-top: 0.5rem;">Enregistrer l'avatar</button>
                            </form>
                        </div>

                        <!-- 2. Changer de Pseudo -->
                        <div class="account-form">
                            <h3 class="card-title" style="font-size: 1.15rem;">Modifier le Pseudo</h3>
                            <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 0.5rem;">Visible par les autres joueurs en ligne et sur le podium.</p>
                            <form method="post" action="account.php">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="update_profile">
                                <label for="update-name">Nouveau pseudo (2 à 30 caractères)</label>
                                <input id="update-name" name="display_name" type="text" value="<?= e($user["display_name"]) ?>" required minlength="2" maxlength="30" autocomplete="nickname" placeholder="Votre nouveau pseudo">
                                <button class="btn-primary-action" type="submit">Sauvegarder le pseudo</button>
                            </form>
                        </div>

                        <!-- 3. Changer d'Email -->
                        <div class="account-form">
                            <h3 class="card-title" style="font-size: 1.15rem;">Modifier l'Adresse E-mail</h3>
                            <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 0.5rem;">Utilisée pour vous connecter à votre compte Multi-Games.</p>
                            <form method="post" action="account.php">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="update_email">
                                <label for="update-email">Nouvelle adresse e-mail</label>
                                <input id="update-email" name="email" type="email" value="<?= e($user["email"]) ?>" required maxlength="190" autocomplete="email" placeholder="votre.email@exemple.com">
                                <button class="btn-primary-action" type="submit">Sauvegarder l'e-mail</button>
                            </form>
                        </div>

                        <!-- 4. Changer de Mot de Passe -->
                        <div class="account-form">
                            <h3 class="card-title" style="font-size: 1.15rem;">Changer de Mot de Passe</h3>
                            <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 0.5rem;">Sécurisez votre compte avec un mot de passe robuste.</p>
                            <form method="post" action="account.php">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="update_password">

                                <label for="current-password">Mot de passe actuel</label>
                                <div class="password-input-wrapper">
                                    <input id="current-password" name="current_password" type="password" required autocomplete="current-password" placeholder="Votre mot de passe actuel">
                                    <button type="button" class="btn-toggle-password" onclick="togglePasswordVisibility('current-password', this)" title="Afficher ou masquer">👁️</button>
                                </div>

                                <label for="new-password">Nouveau mot de passe (min. 10 caractères)</label>
                                <div class="password-input-wrapper">
                                    <input id="new-password" name="new_password" type="password" required minlength="10" autocomplete="new-password" placeholder="Nouveau mot de passe" oninput="updatePasswordCounter(this.value, 'update-pass-count')">
                                    <button type="button" class="btn-toggle-password" onclick="togglePasswordVisibility('new-password', this)" title="Afficher ou masquer">👁️</button>
                                </div>
                                <div class="password-counter-bar">
                                    <span>Longueur requise :</span>
                                    <span id="update-pass-count" class="password-counter-text">0/10 caractères min.</span>
                                </div>

                                <label for="confirm-password">Confirmer le nouveau mot de passe</label>
                                <div class="password-input-wrapper">
                                    <input id="confirm-password" name="confirm_password" type="password" required minlength="10" autocomplete="new-password" placeholder="Confirmation du mot de passe">
                                    <button type="button" class="btn-toggle-password" onclick="togglePasswordVisibility('confirm-password', this)" title="Afficher ou masquer">👁️</button>
                                </div>

                                <button class="btn-primary-action" type="submit">Modifier le mot de passe</button>
                            </form>
                        </div>

                        <!-- 5. Thème préféré -->
                        <div class="account-form">
                            <h3 class="card-title" style="font-size: 1.15rem;">Thème Visuel du Compte</h3>
                            <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 0.5rem;">Définissez votre palette de couleurs favorite par défaut.</p>
                            <form method="post" action="account.php">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="update_theme">
                                <label for="account-theme-select">Palette de couleurs :</label>
                                <select id="account-theme-select" name="theme_key" class="theme-selector-select" style="width: 100%; padding: 0.8rem; border-radius: 0.65rem;" onchange="window.setSpecificTheme(this.value)">
                                    <?php foreach ($gameThemes as $theme): ?>
                                        <option value="<?= e($theme["theme_key"]) ?>" <?= e(($user["theme_preference"] ?: $currentTheme) === $theme["theme_key"] ? 'selected' : '') ?>>
                                            <?= e($theme["name"]) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn-primary-action" type="submit" style="margin-top: 0.8rem;">Enregistrer comme favori</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- CONTENU ONGLET 4 : CLASSEMENT TOP 10 -->
                <div class="account-tab-content" id="tab-leaderboard">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.2rem; flex-wrap: wrap; gap: 0.8rem;">
                        <div class="hub-tag" style="margin: 0;">Top 10 Joueurs de la Plateforme</div>
                        <a href="leaderboard.php" class="btn-secondary-action" style="width: auto; padding: 0.45rem 1rem; font-size: 0.85rem;">
                            Voir tout le classement ➔
                        </a>
                    </div>

                    <div class="leaderboard-table-wrapper">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th style="width: 60px; text-align: center;">Rang</th>
                                    <th>Joueur</th>
                                    <th style="text-align: center;">Points</th>
                                    <th style="text-align: center;">Victoires</th>
                                    <th style="text-align: center;">Défaites</th>
                                    <th style="text-align: center;">Ratio</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leaderboard as $entry): ?>
                                    <tr class="<?= e($user["id"] === $entry["id"] ? 'leaderboard-row-me' : '') ?>">
                                        <td class="leaderboard-rank-col">
                                            <?php if ($entry["rank"] === 1): ?>🥇
                                            <?php elseif ($entry["rank"] === 2): ?>🥈
                                            <?php elseif ($entry["rank"] === 3): ?>🥉
                                            <?php else: ?>#<?= e($entry["rank"]) ?><?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center;">
                                                <span class="user-inline-avatar"><?= e($entry["avatar"]) ?></span>
                                                <strong><?= e($entry["display_name"]) ?></strong>
                                                <?php if ($user["id"] === $entry["id"]): ?>
                                                    <span style="font-size: 0.68rem; background: rgba(52, 211, 153, 0.2); color: #34d399; border: 1px solid rgba(52, 211, 153, 0.35); padding: 0.15rem 0.45rem; border-radius: 9999px; margin-left: 0.5rem;">VOUS</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td style="text-align: center; font-family: 'JetBrains Mono', monospace; font-weight: 800; color: var(--primary);">
                                            <?= e($entry["points"]) ?>
                                        </td>
                                        <td style="text-align: center; font-weight: 700; color: #34d399;">
                                            <?= e($entry["wins"]) ?>
                                        </td>
                                        <td style="text-align: center; color: #f87171;">
                                            <?= e($entry["losses"]) ?>
                                        </td>
                                        <td style="text-align: center; font-weight: 700;">
                                            <span style="color: #60a5fa;"><?= e($entry["ratio"] ?: '0.00') ?></span>
                                            <div style="font-size: 0.72rem; color: var(--text-dim); font-weight: 500;"><?= e($entry["win_rate"]) ?>%</div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Utilisateur non connecté : Formulaires Connexion et Inscription Équilibrés -->
            <section class="account-card" style="max-width: 980px;">
                <div class="hub-tag">Espace Joueur</div>
                <h1 class="hub-title">CONNEXION <span>/ INSCRIPTION</span></h1>
                <p class="hub-subtitle account-intro">
                    Connectez-vous pour enregistrer votre historique de parties, personnaliser votre avatar, grimper dans le classement et entraîner l’IA.
                </p>
                <div class="account-forms auth-grid">
                    <!-- Formulaire de Connexion -->
                    <form method="post" action="account.php" class="account-form" id="login-form">
                        <h2 class="card-title">Se connecter</h2>

                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="login">

                        <label for="login-email">Adresse e-mail</label>
                        <input id="login-email" name="email" type="email" autocomplete="username" required maxlength="190" placeholder="Votre adresse e-mail" oninput="validateEmailField(this, 'login-email-error')">
                        <div id="login-email-error" class="field-error-msg" style="display: none;"></div>

                        <label for="login-password">Mot de passe</label>
                        <div class="password-input-wrapper">
                            <input id="login-password" name="password" type="password" autocomplete="current-password" required placeholder="Votre mot de passe">
                            <button type="button" class="btn-toggle-password" onclick="togglePasswordVisibility('login-password', this)" title="Afficher ou masquer" aria-label="Afficher ou masquer le mot de passe">👁️</button>
                        </div>
                        <div id="login-password-error" class="field-error-msg" style="display: none;"></div>

                        <div class="auth-helper-row">
                            <label class="checkbox-label">
                                <input type="checkbox" name="remember" checked>
                                <span>Rester connecté</span>
                            </label>
                            <a href="javascript:void(0)" class="auth-link" onclick="openForgotModal()">
                                Mot de passe oublié ?
                            </a>
                        </div>

                        <button class="btn-primary-action" type="submit" style="margin-top: 0.5rem;">
                            Connexion à mon compte ➔
                        </button>

                        <!-- Encart d'aide & avantages du compte pour combler le vide élégamment -->
                        <div class="auth-card-extra">
                            <a href="#register-name" class="auth-switch-link" onclick="document.getElementById('register-name').focus();">
                                <span>Pas encore inscrit ?</span>
                                <strong>Créer un compte gratuit ➔</strong>
                            </a>

                            <div class="auth-features-list">
                                <div class="auth-feature-item">
                                    <span>🏆</span>
                                    <span>Parties enregistrées &amp; ratio victoires/défaites</span>
                                </div>
                                <div class="auth-feature-item">
                                    <span>👑</span>
                                    <span>Position officielle au classement mondial</span>
                                </div>
                                <div class="auth-feature-item">
                                    <span>🧬</span>
                                    <span>Mémoire d'IA évolutive propre à votre compte</span>
                                </div>
                            </div>
                        </div>
                    </form>

                    <!-- Formulaire d'Inscription -->
                    <form method="post" action="account.php" class="account-form" id="register-form">
                        <h2 class="card-title">Créer un compte</h2>
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="register">

                        <label for="register-name">Pseudo joueur</label>
                        <input id="register-name" name="display_name" type="text" autocomplete="nickname" required minlength="2" maxlength="30" placeholder="Votre pseudo (2 à 30 car.)" oninput="validatePseudoField(this, 'reg-name-error')">
                        <div id="reg-name-error" class="field-error-msg" style="display: none;"></div>

                        <label for="register-email">Adresse e-mail</label>
                        <input id="register-email" name="email" type="email" autocomplete="email" required maxlength="190" placeholder="votre@email.com" oninput="validateEmailField(this, 'reg-email-error')">
                        <div id="reg-email-error" class="field-error-msg" style="display: none;"></div>

                        <label for="register-password">Mot de passe (10 caractères min.)</label>
                        <div class="password-input-wrapper">
                            <input id="register-password" name="password" type="password" autocomplete="new-password" required minlength="10" placeholder="Votre mot de passe" oninput="updatePasswordCounter(this.value, 'reg-pass-count'); validatePassField(this, 'reg-password-error')">
                            <button type="button" class="btn-toggle-password" onclick="togglePasswordVisibility('register-password', this)" title="Afficher ou masquer" aria-label="Afficher ou masquer le mot de passe">👁️</button>
                        </div>
                        <div class="password-counter-bar">
                            <span>Longueur :</span>
                            <span id="reg-pass-count" class="password-counter-text">0/10 caractères min.</span>
                        </div>
                        <div id="reg-password-error" class="field-error-msg" style="display: none;"></div>

                        <label style="margin-top: 0.2rem; display: block;">Choisissez votre avatar :</label>
                        <!-- Grille responsive avec boutons radio (un seul choix actif, pas de débordement) -->
                        <div class="avatar-grid" id="reg-avatar-grid">
                            <?php foreach ($availableAvatars as $idx => $av): ?>
                                <label class="avatar <?= e($idx === 0 ? 'active' : '') ?>" title="Avatar <?= e($av) ?>">
                                    <input type="radio" name="avatar" value="<?= e($av) ?>" <?= e($idx === 0 ? 'checked' : '') ?>>
                                    <span><?= e($av) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <button class="btn-primary-action" type="submit" style="margin-top: 0.5rem;">
                            Créer mon compte ✨
                        </button>
                    </form>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <footer class="footer">© 2026 Space &amp; Multi-Games</footer>

    <script>
        // Gestion des onglets de la page compte
        document.querySelectorAll('.account-tab-btn').forEach(button => {
            button.addEventListener('click', () => {
                document.querySelectorAll('.account-tab-btn').forEach(btn => {
                    btn.classList.remove('active');
                    btn.setAttribute('aria-selected', 'false');
                });
                document.querySelectorAll('.account-tab-content').forEach(content => {
                    content.classList.remove('active');
                });

                button.classList.add('active');
                button.setAttribute('aria-selected', 'true');
                const targetId = button.getAttribute('data-tab');
                const target = document.getElementById(targetId);
                if (target) target.classList.add('active');
            });
        });

        // Gestion de la sélection d'avatar exclusive
        document.querySelectorAll('.avatar-grid').forEach(grid => {
            grid.addEventListener('change', (e) => {
                if (e.target.matches('input[type="radio"]')) {
                    grid.querySelectorAll('.avatar').forEach(a => a.classList.remove('active'));
                    e.target.closest('.avatar')?.classList.add('active');
                }
            });
        });

        // Afficher / masquer le mot de passe (Œil)
        function togglePasswordVisibility(inputId, button) {
            const input = document.getElementById(inputId);
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                button.textContent = '🙈';
                button.setAttribute('title', 'Masquer le mot de passe');
            } else {
                input.type = 'password';
                button.textContent = '👁️';
                button.setAttribute('title', 'Afficher le mot de passe');
            }
        }

        // Compteur dynamique de caractères pour les mots de passe
        function updatePasswordCounter(value, counterElementId) {
            const counter = document.getElementById(counterElementId);
            if (!counter) return;
            const len = (value || '').length;
            if (len >= 10) {
                counter.textContent = `${len}/10 caractères ✓`;
                counter.className = 'password-counter-text valid';
            } else {
                counter.textContent = `${len}/10 caractères min.`;
                counter.className = 'password-counter-text';
            }
        }

        // Validation en direct du pseudo
        function validatePseudoField(input, errId) {
            const err = document.getElementById(errId);
            if (!err) return;
            const len = (input.value || '').trim().length;
            if (len > 0 && (len < 2 || len > 30)) {
                err.textContent = 'Le pseudo doit comporter entre 2 et 30 caractères.';
                err.style.display = 'block';
            } else {
                err.style.display = 'none';
            }
        }

        // Validation en direct de l'email
        function validateEmailField(input, errId) {
            const err = document.getElementById(errId);
            if (!err) return;
            const val = (input.value || '').trim();
            if (val.length > 0 && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
                err.textContent = 'Adresse e-mail invalide.';
                err.style.display = 'block';
            } else {
                err.style.display = 'none';
            }
        }

        // Validation en direct du mot de passe
        function validatePassField(input, errId) {
            const err = document.getElementById(errId);
            if (!err) return;
            const val = input.value || '';
            if (val.length > 0 && val.length < 10) {
                err.textContent = `Mot de passe trop court (${val.length}/10 caractères requis).`;
                err.style.display = 'block';
            } else {
                err.style.display = 'none';
            }
        }

        // Répartition intelligente des erreurs serveur sous les champs cibles
        document.addEventListener('DOMContentLoaded', () => {
            const serverAlert = document.querySelector('.account-alert');
            if (serverAlert) {
                const text = serverAlert.textContent.trim().toLowerCase();
                if (text.includes('email') || text.includes('compte')) {
                    const regErr = document.getElementById('reg-email-error');
                    const logErr = document.getElementById('login-email-error');
                    if (regErr && text.includes('existe déjà')) {
                        regErr.textContent = 'Cette adresse e-mail est déjà utilisée.';
                        regErr.style.display = 'block';
                    } else if (logErr) {
                        logErr.textContent = serverAlert.textContent.replace('⚠️', '').trim();
                        logErr.style.display = 'block';
                    }
                } else if (text.includes('mot de passe') || text.includes('passe')) {
                    const regErr = document.getElementById('reg-password-error');
                    const logErr = document.getElementById('login-password-error');
                    if (regErr && text.includes('10')) {
                        regErr.textContent = 'Mot de passe trop court (10 caractères minimum requis).';
                        regErr.style.display = 'block';
                    } else if (logErr) {
                        logErr.textContent = 'Mot de passe ou identifiant incorrect.';
                        logErr.style.display = 'block';
                    }
                } else if (text.includes('pseudo')) {
                    const nameErr = document.getElementById('reg-name-error');
                    if (nameErr) {
                        nameErr.textContent = 'Le pseudo doit comporter entre 2 et 30 caractères.';
                        nameErr.style.display = 'block';
                    }
                }
            }
        });

        function openForgotModal() {
            alert('Pour réinitialiser votre mot de passe, contactez l’administrateur à spacedev@thegoat.com ou utilisez votre email de secours.');
        }

        // Synchronisation des statistiques locales du navigateur
        function checkLocalStats() {
            const w = parseInt(localStorage.getItem('mg_stat_wins') || '0', 10);
            const l = parseInt(localStorage.getItem('mg_stat_losses') || '0', 10);
            const t = parseInt(localStorage.getItem('mg_stat_ties') || '0', 10);
            const serverWins = <?= e($playerStats["wins"] ?: 0) ?>;
            const serverLosses = <?= e($playerStats["losses"] ?: 0) ?>;

            if (w > serverWins || l > serverLosses) {
                const banner = document.getElementById('local-sync-banner');
                const detail = document.getElementById('local-sync-detail');
                if (banner && detail) {
                    detail.textContent = `Navigateur : ${w} Victoires | ${l} Défaites (Compte : ${serverWins} V | ${serverLosses} D). Cliquez pour fusionner.`;
                    banner.style.display = 'flex';
                }
            }
        }

        async function syncBrowserStats() {
            const w = parseInt(localStorage.getItem('mg_stat_wins') || '0', 10);
            const l = parseInt(localStorage.getItem('mg_stat_losses') || '0', 10);
            const t = parseInt(localStorage.getItem('mg_stat_ties') || '0', 10);
            try {
                const res = await fetch('games/morpion/api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.MG_CSRF || ''
                    },
                    body: JSON.stringify({
                        action: 'sync_stats',
                        wins: w,
                        losses: l,
                        ties: t
                    })
                });
                const data = await res.json();
                if (data.success && data.stats) {
                    document.getElementById('local-sync-banner').style.display = 'none';
                    window.location.reload();
                } else {
                    alert(data.error || 'Erreur lors de la synchronisation.');
                }
            } catch (err) {
                console.error(err);
                alert('Erreur réseau lors de la synchronisation.');
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            <?php if ($user): ?>
                checkLocalStats();
            <?php endif; ?>
        });
    </script>
    <?php if ($user): ?>
        <script>window.MG_CSRF = <?= js_json($csrfToken) ?>;</script>
        <script src="assets/js/account-presence.js" data-api="games/morpion/api.php" defer></script>
    <?php endif; ?>
</body>
</html>
