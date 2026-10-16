<!DOCTYPE html>
<html lang="fr" data-theme="<?= e($currentTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Morpion by Space - Multi-Games 4K</title>

    <?php
    // Profil classé calculé côté serveur : la puce de l'en-tête est correcte
    // dès le premier rendu, sans attendre un appel API.
    $rankedTier = $user ? mg_rank_for_points((int)($user['ranked_points'] ?? 0)) : null;
    $rankedLevel = $user ? mg_level_from_xp((int)($user['xp'] ?? 0)) : null;
    $rankedSeason = $user ? (int)($user['season_id'] ?? 1) : 1;
    ?>
    <script>
        window.LAN_BASE_URL = <?= js_json($lanBaseUrl) ?>;
        window.MG_CSRF = <?= js_json($csrfToken) ?>;
        window.SPACE_CORE_GRID_SIZE = <?= js_json($requestedGridSize) ?>;
        window.MG_PLAYER_NAME = <?= js_json($playerDisplayName) ?>;
        window.MG_RANKED = <?= js_json([
            'authenticated' => (bool) $user,
            'points' => $rankedTier['points'] ?? 0,
            'rank' => $rankedTier,
            'level' => $rankedLevel,
            'season' => $rankedSeason,
            'tiers' => mg_rank_tiers(),
        ]) ?>;
    </script>

    <link rel="stylesheet" href="../../assets/css/themes/themes.css">
    <link rel="stylesheet" href="../../assets/css/base.css">
    <style><?= $customThemeCss ?>
/* ==================================================================
   Arène Morpion : mise en page recalibrée.
   Le plateau se dimensionne désormais sur la HAUTEUR disponible en plus
   de la largeur : sur un écran 1080p, l'arène complète tenait 1111 px
   pour une fenêtre de ~961 px, ce qui coupait le bas du plateau et
   obligeait à faire défiler la page en pleine partie.
   ================================================================== */
.game-arena {
  --arena-board-max: min(700px, max(210px, calc(100vh - 22rem)));
  --arena-board-max: min(700px, max(210px, calc(100dvh - 22rem)));
  justify-content: flex-start;
  padding: clamp(0.7rem, 1.6vw, 1.15rem);
}

/* En pleine partie, la page ne défile plus : le pied de page disparaît et le
   plateau occupe exactement l'espace restant sous la barre supérieure. */
body.in-game { overflow: hidden; }
body.in-game .footer { display: none; }

.arena-heading { margin-bottom: 0.5rem; }
.arena-heading h1 { font-size: clamp(1.15rem, 2.2vw, 1.7rem); }
.arena-heading h1 span { display: inline; margin-left: 0.45rem; font-size: 0.68rem; }
.arena-heading p { display: none; }
.arena-topbar { margin-bottom: 0.5rem; gap: 0.5rem; }
.game-scoreboard { padding: 0.5rem 0.75rem; }
.score-player { font-size: 0.92rem; }

/* Les contrôles tiennent sur une seule ligne défilable au lieu de deux. */
.arena-controls {
  display: flex;
  flex-wrap: nowrap;
  overflow-x: auto;
  gap: 0.4rem;
  margin: 0.15rem 0 0.45rem;
  padding: 0.5rem;
  scrollbar-width: thin;
}
.arena-controls > * { flex: 0 0 auto; }
.arena-controls .btn-secondary-action { min-height: 34px; padding: 0.4rem 0.7rem; font-size: 0.78rem; }
.arena-setting { font-size: 0.78rem; }

.turn-indicator { margin-bottom: 0.45rem; padding: 0.35rem 1rem; font-size: 0.82rem; }

.board-container {
  width: min(94%, var(--arena-board-max));
  max-width: var(--arena-board-max);
  margin: 0.3rem auto 0.5rem;
}

@media (max-height: 780px) {
  .arena-heading { display: none; }
}

/* Sur grand écran, l'arène s'élargit : les contrôles tiennent alors sur une
   seule ligne sans avoir à faire défiler horizontalement. */
@media (min-width: 1500px) {
  .game-arena { max-width: min(1360px, 96vw); }
}

/* --- Indicateur « le bot réfléchit » --- */
.bot-thinking {
  display: none;
  align-items: center;
  gap: 0.4rem;
  margin-left: 0.4rem;
  color: var(--primary);
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}
.score-player.is-thinking .bot-thinking { display: inline-flex; }
.bot-thinking i {
  width: 5px; height: 5px; border-radius: 50%;
  background: currentColor;
  animation: botDot 1s ease-in-out infinite;
}
.bot-thinking i:nth-child(3) { animation-delay: 0.16s; }
.bot-thinking i:nth-child(4) { animation-delay: 0.32s; }
@keyframes botDot { 0%, 100% { opacity: 0.25; transform: translateY(0); } 50% { opacity: 1; transform: translateY(-2px); } }
@media (prefers-reduced-motion: reduce) { .bot-thinking i { animation: none; opacity: 0.8; } }

/* --- Puce de rang / niveau / XP dans l'en-tête --- */
.mg-rank-chip {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.3rem 0.7rem 0.45rem;
  border: 1px solid var(--card-border);
  border-radius: 0.7rem;
  background: var(--card-inner);
  color: var(--text-main);
  font: inherit;
  cursor: pointer;
  transition: border-color 0.2s ease, transform 0.2s ease;
}
.mg-rank-chip:hover { border-color: var(--primary); transform: translateY(-1px); }
.mg-rank-icon { font-size: 1.25rem; line-height: 1; }
.mg-rank-body { display: grid; gap: 0.05rem; text-align: left; }
.mg-rank-label { font-size: 0.78rem; font-weight: 800; letter-spacing: 0.01em; }
.mg-rank-meta { color: var(--text-muted); font-size: 0.68rem; }
.mg-rank-meta b { color: var(--primary); font-weight: 800; }
.mg-xp-track {
  position: absolute;
  left: 0.35rem; right: 0.35rem; bottom: 0.15rem;
  height: 3px;
  border-radius: 999px;
  background: color-mix(in srgb, var(--primary) 18%, transparent);
  overflow: hidden;
}
.mg-xp-fill { display: block; height: 100%; width: 0; border-radius: 999px; background: var(--primary); transition: width 0.45s ease; }
@media (prefers-reduced-motion: reduce) { .mg-xp-fill { transition: none; } }

/* --- Carte et modale du mode Classé --- */
.mode-card.is-ranked { border-color: color-mix(in srgb, var(--primary) 45%, transparent); }
.ranked-banner {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  margin-bottom: 0.5rem;
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--card-border);
  border-radius: 0.7rem;
  background: var(--card-inner);
}
.ranked-banner .rb-icon { font-size: 1.6rem; line-height: 1; }
.ranked-banner .rb-text { display: grid; gap: 0.1rem; min-width: 0; }
.ranked-banner .rb-title { font-size: 0.85rem; font-weight: 800; }
.ranked-banner .rb-sub { color: var(--text-muted); font-size: 0.72rem; }
.ranked-tiers { display: flex; flex-wrap: wrap; gap: 0.3rem; margin: 0.15rem 0 0.6rem; }
.ranked-tier {
  display: inline-flex; align-items: center; gap: 0.25rem;
  padding: 0.18rem 0.5rem;
  border: 1px solid var(--card-border);
  border-radius: 999px;
  background: var(--card-inner);
  color: var(--text-dim);
  font-size: 0.68rem;
}
.ranked-tier.is-current { border-color: var(--primary); color: var(--primary); font-weight: 800; }
.ranked-choice { display: grid; gap: 0.5rem; }
.ranked-choice .rc-title { color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
.ranked-choice .rc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 0.45rem; }

/* --- Modale de récompenses --- */
.reward-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 0.7rem; margin: 0.4rem 0 0.2rem; }
.reward-box {
  padding: 0.85rem 0.6rem;
  border: 1px solid var(--card-border);
  border-radius: 0.8rem;
  background: var(--card-inner);
  text-align: center;
}
.reward-box .rb-value { font-size: 1.5rem; font-weight: 900; line-height: 1.1; }
.reward-box .rb-label { margin-top: 0.2rem; color: var(--text-muted); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; }
.reward-box.is-up { border-color: #34d399; }
.reward-box.is-down { border-color: #ef4444; }
.reward-box.is-neutral { border-color: var(--card-border); }
.reward-progress { height: 6px; margin-top: 0.55rem; border-radius: 999px; background: color-mix(in srgb, var(--primary) 18%, transparent); overflow: hidden; }
.reward-progress i { display: block; height: 100%; border-radius: 999px; background: var(--primary); }
.reward-note { margin-top: 0.7rem; color: var(--text-muted); font-size: 0.8rem; text-align: center; }
.reward-levelup {
  margin-top: 0.6rem;
  padding: 0.5rem 0.7rem;
  border: 1px solid #34d399;
  border-radius: 0.6rem;
  background: rgba(52, 211, 153, 0.12);
  color: #34d399;
  font-weight: 800;
  font-size: 0.85rem;
  text-align: center;
}
.reward-levelup[hidden] { display: none; }
.input-group[hidden] { display: none; }

/* --- Tableau du barème --- */
.ranked-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
}
.ranked-table th,
.ranked-table td {
  padding: 0.5rem 0.6rem;
  border-bottom: 1px solid var(--card-border);
  font-size: 0.85rem;
  white-space: nowrap;
}
.ranked-table th {
  color: var(--text-muted);
  font-size: 0.7rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}
.ranked-table td.is-up { color: #34d399; font-weight: 700; }
.ranked-table td.is-down { color: #f87171; font-weight: 700; }
.ranked-table tr:last-child td { border-bottom: 0; }

/* Plateau large et lisible, sans effets néon. */
body.in-game { overflow: auto; }

.game-arena {
  width: 100%;
  max-width: min(1200px, 96vw);
  flex-direction: column;
  align-items: stretch;
  justify-content: flex-start;
  box-sizing: border-box;
  background: #0c0e13;
  box-shadow: none;
}

.board-container {
  width: 100%;
  max-width: none;
  flex: none;
  align-self: stretch;
  margin: 0.5rem 0 0.8rem;
  container-type: inline-size;
}

#game-board,
#board,
.board-grid,
.board-grid-3x3,
.board-grid-5x5,
.board-grid-gomoku {
  background: #10131a;
  border: 1px solid var(--card-border);
  box-shadow: none;
}

#game-board .board-cell,
#board .board-cell {
  background: #0c0e13;
  border-color: rgba(255, 255, 255, 0.18);
  font-size: calc(100cqw / var(--grid-n, 3) * 0.5) !important;
  text-shadow: none;
}

#game-board .board-cell:hover:not(.filled),
#board .board-cell:hover:not(.filled) {
  background: rgba(255, 255, 255, 0.08);
}

#game-board .board-cell.cell-x,
#game-board .board-cell.cell-o,
#board .board-cell.cell-x,
#board .board-cell.cell-o {
  text-shadow: none;
}

#game-board .board-cell.winning-cell,
#board .board-cell.winning-cell {
  background: rgba(255, 255, 255, 0.12) !important;
  color: var(--text-main) !important;
  text-shadow: none !important;
  box-shadow: none;
}

#game-board .board-cell.last-move,
#board .board-cell.last-move {
  outline: 2px solid #f472b6;
  outline-offset: -3px;
  background: rgba(244, 114, 182, 0.12);
}

.arena-controls {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.5rem;
  overflow: visible;
}

@media (max-width: 720px) {
  .arena-controls { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>

    <script src="../../assets/js/theme.js"></script>
    <script src="../../assets/js/audio.js" defer></script>
    <script src="bot.js" defer></script>
    <script src="games.js?v=space-core" defer></script>
</head>
<body>

    <header class="topbar">
        <a href="../../index.php" class="brand-wrapper" title="Retour à l'accueil Multi-Games">
            <div class="brand-icon">
                <span class="x-sym">✕</span>
                <span class="o-sym">○</span>
            </div>
            <div class="brand-title">Morpion <span>by Space</span></div>
        </a>

        <div class="topbar-actions">
            <a class="btn-topbar" href="../../leaderboard.php">
                <span>🏆</span>
                <span>Classement</span>
            </a>
            <a class="btn-topbar" href="../../space-core/">Space Core</a>
            <?php if ($user): ?>
                <a class="btn-topbar" href="../../profile.php">
                    <span><?= e($user["avatar"] ?: '🚀') ?></span>
                    <span><?= e($user["display_name"]) ?></span>
                </a>
                <?php if ($user["role"] === 'admin'): ?>
                    <a class="btn-topbar admin-btn" href="../../admin.php">Admin</a>
                <?php endif; ?>
            <?php else: ?>
                <a class="btn-topbar" href="../../account.php">Connexion</a>
            <?php endif; ?>

            <button class="btn-topbar palette-btn" id="theme-palette-btn" title="Changer de thème">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/>
                    <circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/>
                    <circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/>
                    <circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/>
                    <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>
                </svg>
            </button>

            <button class="btn-topbar" id="btn-share-trigger" title="Partager ou créer une partie en ligne">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                    <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                </svg>
                <span>Partager</span>
            </button>

            <?php if ($user): ?>
                <button class="mg-rank-chip" id="rank-chip" type="button" title="Votre profil classé — cliquez pour le détail">
                    <span class="mg-rank-icon" id="rank-chip-icon"><?= e($rankedTier['icon']) ?></span>
                    <span class="mg-rank-body">
                        <span class="mg-rank-label" id="rank-chip-label"><?= e($rankedTier['label']) ?></span>
                        <span class="mg-rank-meta"><b id="rank-chip-points"><?= e($rankedTier['points']) ?></b> pts · Niv. <b id="rank-chip-level"><?= e($rankedLevel['level']) ?></b></span>
                    </span>
                    <span class="mg-xp-track" aria-hidden="true"><span class="mg-xp-fill" id="rank-chip-xp" style="width: <?= e($rankedLevel['progress']) ?>%"></span></span>
                </button>
            <?php else: ?>
                <a class="mg-rank-chip" id="rank-chip" href="../../account.php" title="Connectez-vous pour jouer en classé">
                    <span class="mg-rank-icon">🔒</span>
                    <span class="mg-rank-body">
                        <span class="mg-rank-label">Mode classé</span>
                        <span class="mg-rank-meta">Connexion requise</span>
                    </span>
                </a>
            <?php endif; ?>

            <a href="../../index.php" class="btn-topbar" title="Retourner à la liste des jeux">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                </svg>
                <span>Hub Jeux</span>
            </a>

            <button class="btn-topbar admin-btn" id="open-settings">
                <span>⚙️ Options</span>
            </button>
        </div>
    </header>

    <!-- VUE 1 : MENU PRINCIPAL -->
    <main class="main-container" id="menu-view">
        <div class="menu-header">
            <div class="menu-header-main">
                <div class="menu-pretitle">
                    <span>MORPION · 3X3 CLASSIQUE OU 5 À ALIGNER</span>
                </div>
                <h1 class="menu-title">MENU</h1>
            </div>

            <!-- Sélecteur rapide de règle (3x3 ou 5 à aligner) -->
            <div class="menu-rule-selector" id="menu-rule-selector" title="Sélectionnez la variante souhaitée">
                <span class="rule-selector-label">Format :</span>
                <div class="rule-selector-pills">
                    <button type="button" class="btn-rule-pill active" data-rule="3" id="btn-rule-3" title="Morpion traditionnel 3x3 avec 3 symboles à aligner">
                        <span class="rule-pill-badge">3×3</span>
                        <span class="rule-pill-name">Classique</span>
                    </button>
                    <button type="button" class="btn-rule-pill" data-rule="5" id="btn-rule-5" title="Morpion grand format avec 5 symboles à aligner">
                        <span class="rule-pill-badge">5 à aligner</span>
                        <span class="rule-pill-name">Grand plateau</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="modes-grid">
            <!-- CARTE 1 : HORS LIGNE / LOCAL -->
            <div class="mode-card">
                <div class="card-top">
                    <span class="card-step-num">01 · LOCAL</span>
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" opacity="0.7">
                        <line x1="8" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="16" y2="21"/>
                        <line x1="3" y1="8" x2="21" y2="8"/><line x1="3" y1="16" x2="21" y2="16"/>
                    </svg>
                </div>

                <div class="card-body">
                    <div class="card-illustration">
                        <svg width="130" height="65" viewBox="0 0 140 70" fill="none" stroke="currentColor">
                            <path d="M20 18 L120 18 M20 36 L120 36 M20 54 L120 54" stroke="var(--card-border)" stroke-width="1.8"/>
                            <path d="M45 10 L45 62 M85 10 L85 62" stroke="var(--card-border)" stroke-width="1.8"/>
                            <text x="25" y="31" fill="var(--color-x)" font-size="16" font-weight="900" font-family="sans-serif">X</text>
                            <text x="60" y="31" fill="var(--color-o)" font-size="16" font-weight="900" font-family="sans-serif">O</text>
                            <text x="98" y="31" fill="var(--color-x)" font-size="16" font-weight="900" font-family="sans-serif">X</text>
                            <text x="25" y="49" fill="var(--color-o)" font-size="16" font-weight="900" font-family="sans-serif">O</text>
                            <text x="60" y="49" fill="var(--color-x)" font-size="16" font-weight="900" font-family="sans-serif">X</text>
                            <text x="98" y="49" fill="var(--color-o)" font-size="16" font-weight="900" font-family="sans-serif">O</text>
                        </svg>
                    </div>

                    <h2 class="card-title">Hors ligne</h2>
                    <p class="card-desc">Une partie à deux, sur cet appareil.</p>

                    <div class="card-buttons-zone">
                        <button class="btn-primary-action" id="btn-local-quick">
                            Match amical
                        </button>
                        <button class="btn-secondary-action" id="btn-local-tournament">
                            Créer un tournoi
                        </button>
                    </div>

                    <div class="card-footer-note">Rapide à lancer, sans connexion.</div>
                </div>
            </div>

            <!-- CARTE 2 : INTELLIGENCE ARTIFICIELLE / BOT -->
            <div class="mode-card">
                <div class="card-top">
                    <span class="card-step-num">02 · INTELLIGENCE ARTIFICIELLE</span>
                    <span class="card-badge-pill">AI</span>
                </div>

                <div class="card-body">
                    <div class="card-illustration">
                        <svg width="130" height="65" viewBox="0 0 140 70" fill="none" stroke="currentColor">
                            <path d="M20 18 L120 18 M20 36 L120 36 M20 54 L120 54" stroke="var(--card-border)" stroke-width="1.8"/>
                            <path d="M45 10 L45 62 M85 10 L85 62" stroke="var(--card-border)" stroke-width="1.8"/>
                            <rect x="52" y="24" width="26" height="24" rx="6" fill="color-mix(in srgb, var(--primary) 18%, transparent)" stroke="var(--primary)" stroke-width="1.5"/>
                            <circle cx="59" cy="33" r="2.2" fill="var(--primary)"/>
                            <circle cx="71" cy="33" r="2.2" fill="var(--primary)"/>
                            <path d="M58 41 Q65 44 72 41" stroke="var(--primary)" stroke-width="1.5" stroke-linecap="round"/>
                            <text x="25" y="31" fill="var(--color-x)" font-size="16" font-weight="900" font-family="sans-serif">X</text>
                            <text x="98" y="31" fill="var(--color-o)" font-size="16" font-weight="900" font-family="sans-serif">O</text>
                            <text x="25" y="49" fill="var(--color-o)" font-size="16" font-weight="900" font-family="sans-serif">O</text>
                            <text x="98" y="49" fill="var(--color-x)" font-size="16" font-weight="900" font-family="sans-serif">X</text>
                        </svg>
                    </div>

                    <h2 class="card-title">Contre un bot</h2>
                    <p class="card-desc">Choisissez son niveau ou laissez-le s'adapter à votre style.</p>

                    <div class="card-buttons-zone bot-levels-grid">
                        <button class="btn-bot-level level-novice" data-level="novice">
                            <span>🐣</span> Novice
                        </button>
                        <button class="btn-bot-level level-medium" data-level="medium">
                            <span>🤖</span> Intermédiaire
                        </button>
                        <button class="btn-bot-level level-hard" data-level="advanced">
                            <span>🧠</span> Avancé
                        </button>
                        <button class="btn-bot-level level-legend" data-level="legend">
                            <span>👑</span> Légende
                        </button>
                        <button class="btn-bot-level level-adaptive" data-level="adaptive">
                            <span>🧬</span> Adaptatif
                        </button>
                    </div>

                    <div class="card-footer-note">Du joueur débutant au Minimax invincible.</div>
                </div>
            </div>

            <!-- CARTE 3 : DISTANCE / WI-FI -->
            <div class="mode-card">
                <div class="card-top">
                    <span class="card-step-num">03 · WI-FI / SALON</span>
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" opacity="0.8">
                        <path d="M5 12.55a11 11 0 0 1 14.08 0"/>
                        <path d="M1.42 9a16 16 0 0 1 21.16 0"/>
                        <path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>
                        <circle cx="12" cy="20" r="1"/>
                    </svg>
                </div>

                <div class="card-body">
                    <div class="card-illustration">
                        <svg width="130" height="65" viewBox="0 0 140 70" fill="none" stroke="currentColor">
                            <path d="M20 18 L120 18 M20 36 L120 36 M20 54 L120 54" stroke="var(--card-border)" stroke-width="1.8"/>
                            <path d="M45 10 L45 62 M85 10 L85 62" stroke="var(--card-border)" stroke-width="1.8"/>
                            <path d="M55 27 A 15 15 0 0 1 75 27" stroke="var(--primary)" stroke-width="1.8" stroke-linecap="round" fill="none"/>
                            <path d="M59 32 A 9 9 0 0 1 71 32" stroke="var(--primary)" stroke-width="1.8" stroke-linecap="round" fill="none"/>
                            <circle cx="65" cy="37" r="2.2" fill="var(--primary)"/>
                            <text x="25" y="31" fill="var(--color-x)" font-size="16" font-weight="900" font-family="sans-serif">X</text>
                            <text x="98" y="31" fill="var(--color-o)" font-size="16" font-weight="900" font-family="sans-serif">O</text>
                            <text x="25" y="49" fill="var(--color-o)" font-size="16" font-weight="900" font-family="sans-serif">O</text>
                            <text x="98" y="49" fill="var(--color-x)" font-size="16" font-weight="900" font-family="sans-serif">X</text>
                        </svg>
                    </div>

                    <h2 class="card-title">À distance</h2>
                    <p class="card-desc">Affrontez un autre joueur sur le même réseau Wi-Fi.</p>

                    <div class="card-buttons-zone">
                        <button class="btn-primary-action" id="btn-wifi-host">
                            Créer une salle
                        </button>
                        <button class="btn-secondary-action" id="btn-wifi-join">
                            Rejoindre avec un code
                        </button>
                    </div>

                    <div class="card-footer-note">Partagez l'IP locale ou le code à 4 lettres.</div>
                </div>
            </div>

            <!-- CARTE 4 : MATCH CLASSÉ (RANKED) -->
            <div class="mode-card is-ranked">
                <div class="card-top">
                    <span class="card-step-num">04 · CLASSÉ / RANKED</span>
                    <span class="card-badge-pill">ELO</span>
                </div>

                <div class="card-body">
                    <div class="ranked-banner">
                        <span class="rb-icon" id="ranked-card-icon"><?= e($rankedTier['icon'] ?? '🔒') ?></span>
                        <span class="rb-text">
                            <span class="rb-title" id="ranked-card-label"><?= e($rankedTier['label'] ?? 'Connexion requise') ?></span>
                            <span class="rb-sub" id="ranked-card-sub"><?= $user
                                ? e($rankedTier['points'] . ' pts · Niveau ' . $rankedLevel['level'] . ' · Saison ' . $rankedSeason)
                                : 'Créez un compte pour gagner des points' ?></span>
                        </span>
                    </div>

                    <div class="ranked-tiers" id="ranked-tiers">
                        <?php foreach (mg_rank_tiers() as $tier): ?>
                            <span class="ranked-tier<?= ($rankedTier && $rankedTier['key'] === $tier['key']) ? ' is-current' : '' ?>" data-tier="<?= e($tier['key']) ?>">
                                <?= e($tier['icon']) ?> <?= e($tier['name']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <h2 class="card-title">Match classé</h2>
                    <p class="card-desc">Chaque résultat fait varier vos points, votre rang et votre niveau.</p>

                    <div class="card-buttons-zone">
                        <button class="btn-primary-action" id="btn-ranked-start" <?= $user ? '' : 'disabled' ?>>
                            Lancer un match classé
                        </button>
                        <button class="btn-secondary-action" id="btn-ranked-guide">
                            Voir le barème
                        </button>
                    </div>

                    <div class="card-footer-note">PvP +25 / −15 / +5 · Bot de +5 / −10 à +40 / −2.</div>
                </div>
            </div>
        </div>
    </main>

    <!-- VUE 2 : ARÈNE DE JEU MORPION -->
    <div class="main-container game-arena" id="game-view">
        <div class="arena-heading">
            <h1>Morpion <span>by Space</span></h1>
            <p>Alignez vos symboles et défiez votre adversaire.</p>
        </div>
        <div class="arena-topbar">
            <button class="btn-back-menu" id="btn-back-menu">
                <span>←</span> Quitter la partie
            </button>

            <!-- Tableau des scores -->
            <div class="game-scoreboard">
                <div class="score-player" id="player-x-box">
                    <span id="symbol-x-score" style="color: var(--color-x);">✕</span>
                    <span id="name-x">Joueur 1</span>
                    <span id="score-x" style="color: var(--color-x); margin-left: 4px;">0</span>
                </div>
                <div class="score-ties-box">
                    Nuls: <span id="score-ties" class="tie-num">0</span>
                </div>
                <div class="score-player" id="player-o-box">
                    <span id="score-o" style="color: var(--color-o); margin-right: 4px;">0</span>
                    <span id="name-o">Joueur 2</span>
                    <span id="symbol-o-score" style="color: var(--color-o);">○</span>
                    <span class="bot-thinking" id="bot-thinking" aria-live="polite">
                        réfléchit<i></i><i></i><i></i>
                    </span>
                </div>
            </div>

            <button class="btn-topbar" id="btn-rematch" title="Rejouer la manche">
                <span>🔄 Rejouer</span>
            </button>
        </div>
        <div class="arena-controls" id="arena-controls">
            <button class="btn-secondary-action" data-game-action="undo">↶ Coup arrière</button>
            <button class="btn-secondary-action" data-game-action="next_round">↻ Recommencer la manche</button>
            <button class="btn-secondary-action" data-game-action="menu">⌂ Menu principal</button>
            <button class="btn-secondary-action" data-game-action="reset_all">⟲ Réinitialiser tout</button>
            <label class="arena-setting">Grille
                <select id="arena-grid-size" class="input-field">
                    <option value="3">3 × 3</option><option value="5">5 × 5</option><option value="10">10 × 10</option>
                    <option value="12">12 × 12</option><option value="15">15 × 15</option><option value="19">19 × 19</option>
                </select>
            </label>
            <label class="arena-setting">Thème
                <select class="input-field theme-selector-select" aria-label="Thème de couleur" onchange="window.setSpecificTheme(this.value)">
                    <?php foreach ($gameThemes as $theme): ?>
                        <option value="<?= e($theme["theme_key"]) ?>"><?= e($theme["name"]) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="arena-setting">Symbole X
                <select id="arena-symbol-x-select" class="input-field" aria-label="Symbole du joueur X">
                    <?php foreach ($gameSymbols as $symbol): ?>
                        <option value="<?= e($symbol["symbol"]) ?>"><?= e($symbol["symbol"]) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="arena-setting">Symbole O
                <select id="arena-symbol-o-select" class="input-field" aria-label="Symbole du joueur O">
                    <?php foreach ($gameSymbols as $symbol): ?>
                        <option value="<?= e($symbol["symbol"]) ?>"><?= e($symbol["symbol"]) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div id="turn-indicator" class="turn-indicator">À votre tour (✕)</div>
        <div id="status-banner" style="display: none; font-size: 1.25rem; font-weight: 800; color: var(--primary); margin-bottom: 0.8rem; text-align: center;"></div>
        <div class="board-container" id="board-container">
            <div id="game-board" class="board-grid"></div>
        </div>
    </div>

    <div class="modal-overlay" id="round-end-modal" role="dialog" aria-modal="true" aria-labelledby="round-end-title">
        <div class="modal-content round-end-content">
            <h2 class="modal-title" id="round-end-title">Manche terminée</h2>
            <p class="modal-subtitle" id="round-end-message"></p>
            <div class="round-end-actions">
                <button class="btn-primary-action" data-game-action="next_round">Manche suivante</button>
                <button class="btn-secondary-action" data-game-action="reset_all">Recommencer à zéro</button>
                <button class="btn-secondary-action" data-game-action="menu">Quitter la partie</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="action-request-modal" role="dialog" aria-modal="true" aria-labelledby="action-request-title">
        <div class="modal-content round-end-content">
            <h2 class="modal-title" id="action-request-title">Demande de partie</h2>
            <p class="modal-subtitle" id="action-request-message"></p>
            <div class="round-end-actions" id="action-request-buttons">
                <button class="btn-primary-action" id="action-request-accept">Accepter</button>
                <button class="btn-secondary-action" id="action-request-decline">Refuser</button>
                <button class="btn-secondary-action" id="action-request-close" hidden>Annuler la demande</button>
            </div>
        </div>
    </div>

    <!-- MODALE : CONFIGURATION MATCH LOCAL -->
    <div class="modal-overlay" id="local-setup-modal">
        <div class="modal-content">
            <button class="modal-close-btn">&times;</button>
            <h3 class="modal-title">Match Hors Ligne</h3>
            <p class="modal-subtitle">Paramétrez les joueurs et le format de la grille.</p>

            <div class="input-group" <?= e($user ? 'hidden' : '') ?>>
                <label class="input-label">Pseudo Joueur 1 (✕) :</label>
                <input type="text" id="local-p1-name" class="input-field" value="<?= e($playerDisplayName) ?>" maxlength="30" <?= e($user ? 'readonly' : '') ?>>
            </div>

            <div class="input-group">
                <label class="input-label">Pseudo Joueur 2 (○) :</label>
                <input type="text" id="local-p2-name" class="input-field" value="<?= e($user ? $playerDisplayName : 'Joueur 2') ?>" maxlength="30">
            </div>

            <div class="input-group">
                <label class="input-label">Type de grille :</label>
                <select id="local-grid-size" class="input-field">
                    <option value="3" selected>Classique 3x3 (3 pour gagner)</option><option value="5">5x5 (4 pour gagner)</option>
                    <option value="10">10x10 (5 pour gagner)</option><option value="12">12x12 (5 pour gagner)</option>
                    <option value="15">15x15 (5 pour gagner)</option><option value="19">19x19 (5 pour gagner)</option>
                </select>
            </div>

            <div style="margin-top: 1.5rem;">
                <button class="btn-primary-action" id="btn-confirm-local">
                    Lancer la partie 🎮
                </button>
            </div>
        </div>
    </div>

    <!-- MODALE : CONFIGURATION CONTRE UN BOT -->
    <div class="modal-overlay" id="bot-setup-modal">
        <div class="modal-content">
            <button class="modal-close-btn">&times;</button>
            <h3 class="modal-title">Duel contre le Bot</h3>
            <p class="modal-subtitle">Difficulté : <strong id="bot-modal-level-name" style="color: var(--primary);">Intermédiaire</strong></p>
            <input type="hidden" id="bot-level-selected" value="medium">

            <div class="input-group" <?= e($user ? 'hidden' : '') ?>>
                <label class="input-label">Votre Pseudo :</label>
                <input type="text" id="bot-player-name" class="input-field" value="<?= e($playerDisplayName) ?>" maxlength="30" <?= e($user ? 'readonly' : '') ?>>
            </div>

            <div class="input-group">
                <label class="input-label">Qui commence la partie ? :</label>
                <select id="bot-starter-select" class="input-field">
                    <option value="player" selected>Vous commencez (✕)</option>
                    <option value="bot">Le Bot commence (○)</option>
                    <option value="alternate">Alterné à chaque manche</option>
                </select>
            </div>

            <div class="input-group">
                <label class="input-label">Format de la grille :</label>
                <select id="bot-grid-size" class="input-field">
                    <option value="3" selected>Classique 3x3</option><option value="5">5x5 (4 alignés)</option>
                    <option value="10">10x10 (5 alignés)</option><option value="12">12x12 (5 alignés)</option>
                    <option value="15">15x15 (5 alignés)</option><option value="19">19x19 (5 alignés)</option>
                </select>
            </div>

            <div style="margin-top: 1.5rem;">
                <button class="btn-primary-action" id="btn-confirm-bot">
                    Défier le Bot ⚔️
                </button>
            </div>
        </div>
    </div>

    <!-- MODALE : CRÉATION SALON WI-FI -->
    <div class="modal-overlay" id="wifi-create-modal">
        <div class="modal-content">
            <button class="modal-close-btn">&times;</button>
            <h3 class="modal-title">Créer un salon Wi-Fi</h3>
            <p class="modal-subtitle">Hébergez une partie sur votre réseau local.</p>

            <div class="input-group" <?= e($user ? 'hidden' : '') ?>>
                <label class="input-label">Joueur X (Hôte) :</label>
                <input type="text" id="wifi-host-name" class="input-field" value="<?= e($playerDisplayName) ?>" maxlength="30" <?= e($user ? 'readonly' : '') ?>>
            </div>

            <div class="input-group">
                <label class="input-label">Format de la grille :</label>
                <select id="wifi-grid-size" class="input-field">
                    <option value="3" selected>Classique 3x3</option><option value="5">5x5 (4 alignés)</option>
                    <option value="10">10x10 (5 alignés)</option><option value="12">12x12 (5 alignés)</option>
                    <option value="15">15x15 (5 alignés)</option><option value="19">19x19 (5 alignés)</option>
                </select>
            </div>

            <div class="input-group">
                <label class="input-label">Objectif de la partie :</label>
                <select id="wifi-target-wins" class="input-field">
                    <option value="1" selected>Partie simple (manche par manche)</option>
                    <option value="2">Tournoi Bo3 (Premier à 2 victoires)</option>
                    <option value="3">Tournoi Bo5 (Premier à 3 victoires)</option>
                </select>
            </div>

            <div style="margin-top: 1.5rem;">
                <button class="btn-primary-action" id="btn-confirm-create-wifi">
                    Créer le salon Wi-Fi 🌐
                </button>
            </div>
        </div>
    </div>

    <!-- MODALE : SALON WI-FI EN ATTENTE -->
    <div class="modal-overlay" id="wifi-host-modal">
        <div class="modal-content">
            <button class="modal-close-btn">&times;</button>
            <h3 class="modal-title">Salon Wi-Fi Créé</h3>
            <p class="modal-subtitle">Donnez ce code ou ce lien à votre ami connecté sur le même Wi-Fi.</p>

            <div class="room-code-display">
                <div style="font-size: 0.8rem; color: #94a3b8; text-transform: uppercase; margin-bottom: 0.3rem;">Code du salon</div>
                <div class="room-code-val" id="wifi-room-code">----</div>
            </div>

            <div class="input-group">
                <label class="input-label">Lien direct pour mobile ou PC :</label>
                <div class="copy-link-box">
                    <input type="text" class="copy-link-input" id="wifi-share-link" readonly value="<?= e($lanBaseUrl) ?>">
                    <button class="btn-copy" id="btn-copy-wifi-link">Copier</button>
                </div>
            </div>

            <div style="display: flex; align-items: center; justify-content: center; gap: 8px; color: #94a3b8; font-size: 0.88rem; margin-top: 1.5rem;">
                <span class="lr-dot" style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#ef4444;"></span>
                <span>En attente de connexion du Joueur 2...</span>
            </div>
        </div>
    </div>

    <!-- MODALE : REJOINDRE SALON WI-FI -->
    <div class="modal-overlay" id="wifi-join-modal">
        <div class="modal-content">
            <button class="modal-close-btn">&times;</button>
            <h3 class="modal-title">Rejoindre une partie Wi-Fi</h3>
            <p class="modal-subtitle">Entrez le code à 4 lettres affiché sur l'écran de l'hôte.</p>

            <div class="input-group" <?= e($user ? 'hidden' : '') ?>>
                <label class="input-label">Votre pseudo :</label>
                <input type="text" id="join-player-name" class="input-field" placeholder="Ex: Alex" value="<?= e($playerDisplayName) ?>" maxlength="30" <?= e($user ? 'readonly' : '') ?>>
            </div>

            <div class="input-group">
                <label class="input-label">Code du Salon (4 lettres) :</label>
                <input type="text" id="join-room-code" class="input-field" placeholder="Ex: K89P" maxlength="4" style="text-transform: uppercase; font-family: monospace; font-size: 1.3rem; letter-spacing: 0.15em;">
            </div>

            <div style="margin-top: 1.5rem;">
                <button class="btn-primary-action" id="btn-confirm-join">
                    Rejoindre et Jouer
                </button>
            </div>
        </div>
    </div>

    <!-- MODALE : CRÉER UN TOURNOI LOCAL -->
    <div class="modal-overlay" id="tournament-modal">
        <div class="modal-content">
            <button class="modal-close-btn">&times;</button>
            <h3 class="modal-title">Configuration Tournoi</h3>
            <p class="modal-subtitle">Jouez en plusieurs manches avec tableau des scores conservé.</p>

            <div class="input-group" <?= e($user ? 'hidden' : '') ?>>
                <label class="input-label">Pseudo Joueur 1 :</label>
                <input type="text" id="tourney-p1-name" class="input-field" value="<?= e($playerDisplayName) ?>" maxlength="30" <?= e($user ? 'readonly' : '') ?>>
            </div>

            <div class="input-group">
                <label class="input-label">Pseudo Joueur 2 :</label>
                <input type="text" id="tourney-p2-name" class="input-field" value="<?= e($user ? $playerDisplayName : 'Joueur 2') ?>" maxlength="30">
            </div>

            <div class="input-group">
                <label class="input-label">Nombre de victoires requises :</label>
                <select id="tournament-target-select" class="input-field">
                    <option value="2">Au meilleur des 3 (Premier à 2 victoires)</option>
                    <option value="3" selected>Au meilleur des 5 (Premier à 3 victoires)</option>
                    <option value="5">Au meilleur des 9 (Premier à 5 victoires)</option>
                </select>
            </div>

            <div class="input-group">
                <label class="input-label">Type de grille :</label>
                <select id="tournament-grid-select" class="input-field">
                    <option value="3" selected>Classique 3x3</option><option value="5">5x5 (4 alignés)</option>
                    <option value="10">10x10 (5 alignés)</option><option value="12">12x12 (5 alignés)</option>
                    <option value="15">15x15 (5 alignés)</option><option value="19">19x19 (5 alignés)</option>
                </select>
            </div>

            <div style="margin-top: 1.8rem;">
                <button class="btn-primary-action" onclick="MorpionGame.startTournament(parseInt(document.getElementById('tournament-target-select').value), parseInt(document.getElementById('tournament-grid-select').value), document.getElementById('tourney-p1-name').value, document.getElementById('tourney-p2-name').value)">
                    Lancer le Tournoi 🏆
                </button>
            </div>
        </div>
    </div>

    <!-- MODALE : FIN DE TOURNOI -->
    <div class="modal-overlay" id="tournament-victory-modal">
        <div class="modal-content" style="text-align: center;">
            <div style="font-size: 3.5rem; margin-bottom: 0.5rem;">🏆</div>
            <h3 class="modal-title" style="font-size: 1.8rem;">TOURNOI REMPORTÉ !</h3>
            <p class="modal-subtitle" style="margin-bottom: 1rem;">Félicitations au champion de la compétition :</p>

            <div style="font-size: 1.6rem; font-weight: 800; color: var(--primary); margin-bottom: 0.5rem;" id="tournament-winner-name">Joueur 1</div>
            <div style="font-family: 'JetBrains Mono', monospace; font-size: 1.1rem; color: #a1a1aa; margin-bottom: 2rem;">
                Score final : <span id="tournament-final-scores">3 - 1</span>
            </div>

            <div style="display: flex; flex-direction: column; gap: 0.8rem;">
                <button class="btn-primary-action" onclick="document.getElementById('tournament-victory-modal').style.display='none'; MorpionGame.rematch();">
                    Nouveau Tournoi 🔄
                </button>
                <button class="btn-secondary-action" onclick="document.getElementById('tournament-victory-modal').style.display='none'; MorpionGame.exitToMenu();">
                    Retour au Menu Principal ➔
                </button>
            </div>
        </div>
    </div>

    <!-- MODALE : CONFIGURATION D'UN MATCH CLASSÉ -->
    <div class="modal-overlay" id="ranked-setup-modal">
        <div class="modal-content">
            <button class="modal-close-btn">&times;</button>
            <h3 class="modal-title">Match Classé</h3>
            <p class="modal-subtitle">Chaque résultat fait varier vos points classés.</p>

            <div class="ranked-banner">
                <span class="rb-icon" id="ranked-modal-icon"><?= e($rankedTier['icon'] ?? '🔒') ?></span>
                <span class="rb-text">
                    <span class="rb-title" id="ranked-modal-label"><?= e($rankedTier['label'] ?? 'Hors classement') ?></span>
                    <span class="rb-sub" id="ranked-modal-sub"><?= $user ? e($rankedTier['points'] . ' pts · Niveau ' . $rankedLevel['level']) : 'Connectez-vous pour être classé' ?></span>
                </span>
            </div>

            <div class="input-group">
                <label class="input-label" for="ranked-opponent">Adversaire :</label>
                <select id="ranked-opponent" class="input-field">
                    <option value="bot">🤖 Le bot Space</option>
                    <option value="local">🧑‍🤝‍🧑 Un joueur sur cet appareil</option>
                    <option value="wifi">📶 Un joueur en Wi-Fi</option>
                </select>
            </div>

            <div class="input-group" id="ranked-bot-level-group">
                <label class="input-label" for="ranked-bot-level">Difficulté du bot :</label>
                <select id="ranked-bot-level" class="input-field">
                    <option value="novice">🐣 Novice (+5 / −10)</option>
                    <option value="medium" selected>🤖 Intermédiaire (+15 / −5)</option>
                    <option value="advanced">🧠 Avancé (+25 / −5)</option>
                    <option value="legend">👑 Légende (+40 / −2)</option>
                    <option value="adaptive">🧬 Adaptatif (+30 / −5)</option>
                </select>
            </div>

            <div class="input-group" id="ranked-local-group" hidden>
                <label class="input-label" for="ranked-local-name">Pseudo de l'adversaire :</label>
                <input type="text" id="ranked-local-name" class="input-field" value="<?= e($user ? $playerDisplayName : 'Joueur 2') ?>" maxlength="30">
            </div>

            <div class="input-group">
                <label class="input-label" for="ranked-grid-size">Format de la grille :</label>
                <select id="ranked-grid-size" class="input-field">
                    <option value="3" selected>Classique 3x3</option>
                    <option value="5">5x5 (4 alignés)</option>
                    <option value="10">10x10 (5 alignés)</option>
                    <option value="12">12x12 (5 alignés)</option>
                    <option value="15">15x15 (5 alignés)</option>
                    <option value="19">19x19 (5 alignés)</option>
                </select>
            </div>

            <div class="input-group" id="ranked-starter-group">
                <label class="input-label" for="ranked-starter">Qui commence la partie ?</label>
                <select id="ranked-starter" class="input-field">
                    <option value="player" selected>Vous commencez (✕)</option>
                    <option value="opponent">L'adversaire commence (○)</option>
                    <option value="alternate">Alterné à chaque manche</option>
                </select>
            </div>

            <p class="card-footer-note" id="ranked-modal-note">Les points sont calculés par le serveur à la fin de la manche.</p>

            <div style="margin-top: 1.5rem;">
                <button class="btn-primary-action" id="btn-confirm-ranked">
                    Lancer le match classé 🏅
                </button>
            </div>
        </div>
    </div>

    <!-- MODALE : BARÈME ET RANGS (généré depuis le serveur, jamais désynchronisé) -->
    <div class="modal-overlay" id="ranked-guide-modal">
        <div class="modal-content" style="width: min(680px, 100%);">
            <button class="modal-close-btn">&times;</button>
            <h3 class="modal-title">Barème & Rangs</h3>
            <p class="modal-subtitle">Le gain de points dépend du mode et de la difficulté.</p>

            <div class="admin-table-wrap">
                <table class="ranked-table">
                    <thead><tr><th>Mode</th><th>Victoire</th><th>Défaite</th><th>Égalité</th></tr></thead>
                    <tbody>
                    <?php
                    $baremeRows = [
                        ['🤖 Bot Novice', 'bot', 'novice'],
                        ['🤖 Bot Intermédiaire', 'bot', 'medium'],
                        ['🧠 Bot Avancé', 'bot', 'advanced'],
                        ['👑 Bot Légende', 'bot', 'legend'],
                        ['🧬 Bot Adaptatif', 'bot', 'adaptive'],
                        ['🧑‍🤝‍🧑 Duel PvP (local ou Wi-Fi)', 'local', null],
                    ];
                    foreach ($baremeRows as [$baremeLabel, $baremeMode, $baremeLevel]):
                        $win = mg_ranked_bareme($baremeMode, $baremeLevel, 'win');
                        $loss = mg_ranked_bareme($baremeMode, $baremeLevel, 'loss');
                        $tie = mg_ranked_bareme($baremeMode, $baremeLevel, 'tie');
                        $sign = static fn(int $n): string => $n > 0 ? '+' . $n : (string)$n; ?>
                        <tr>
                            <td><?= e($baremeLabel) ?></td>
                            <td class="is-up"><?= e($sign($win['points'])) ?> pts</td>
                            <td class="is-down"><?= e($sign($loss['points'])) ?> pts</td>
                            <td><?= e($sign($tie['points'])) ?> pts</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h4 class="modal-title" style="font-size: 1rem; margin-top: 1.4rem;">Paliers de rang</h4>
            <div class="admin-table-wrap">
                <table class="ranked-table">
                    <thead><tr><th>Rang</th><th>Points requis</th></tr></thead>
                    <tbody>
                    <?php foreach (mg_rank_tiers() as $tier): ?>
                        <tr>
                            <td><?= e($tier['icon'] . ' ' . $tier['name']) ?></td>
                            <td><?= e($tier['max'] === null ? $tier['min'] . ' pts et plus' : $tier['min'] . ' – ' . $tier['max'] . ' pts') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="card-footer-note">
                Progression : environ 1 000 XP par niveau, jusqu'au niveau <?= e(MG_MAX_LEVEL) ?>.
                Les points ne descendent jamais sous zéro.
            </p>
        </div>
    </div>

    <!-- MODALE : RÉCOMPENSES DE FIN DE MATCH CLASSÉ -->
    <div class="modal-overlay" id="reward-modal" role="dialog" aria-modal="true" aria-labelledby="reward-title">
        <div class="modal-content">
            <h3 class="modal-title" id="reward-title">Match classé terminé</h3>
            <p class="modal-subtitle" id="reward-subtitle"></p>

            <div class="reward-grid">
                <div class="reward-box" id="reward-points-box">
                    <div class="rb-value" id="reward-points">0</div>
                    <div class="rb-label">Points classés</div>
                </div>
                <div class="reward-box">
                    <div class="rb-value" id="reward-xp">0</div>
                    <div class="rb-label">XP gagnée</div>
                </div>
                <div class="reward-box">
                    <div class="rb-value" id="reward-rank-icon">🥉</div>
                    <div class="rb-label" id="reward-rank-label">Bronze III</div>
                </div>
            </div>

            <div class="reward-progress" aria-hidden="true"><i id="reward-progress-fill" style="width: 0"></i></div>
            <p class="reward-note" id="reward-note"></p>
            <p class="reward-levelup" id="reward-levelup" hidden></p>

            <div class="round-end-actions">
                <button class="btn-primary-action" id="reward-next-round" type="button">Manche suivante</button>
                <button class="btn-secondary-action" id="reward-close" type="button">Fermer</button>
            </div>
        </div>
    </div>

    <!-- MODALE : STATISTIQUES DU JOUEUR -->
    <div class="modal-overlay" id="stats-modal">
        <div class="modal-content">
            <button class="modal-close-btn">&times;</button>
            <h3 class="modal-title">Vos Statistiques</h3>
            <p class="modal-subtitle">Profil classé et compteurs de la session.</p>

            <div class="ranked-banner" id="stats-ranked-banner">
                <span class="rb-icon" id="stats-rank-icon"><?= e($rankedTier['icon'] ?? '🔒') ?></span>
                <span class="rb-text">
                    <span class="rb-title" id="stats-rank-label"><?= e($rankedTier['label'] ?? 'Hors classement') ?></span>
                    <span class="rb-sub" id="stats-rank-sub"><?= $user
                        ? e($rankedTier['points'] . ' pts · Niveau ' . $rankedLevel['level'] . ' · ' . $rankedLevel['into'] . '/' . ($rankedLevel['next'] ?: '—') . ' XP')
                        : 'Connectez-vous pour être classé' ?></span>
                </span>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div style="background: var(--card-inner); padding: 1rem; border-radius: 0.8rem; text-align: center; border: 1px solid var(--card-border);">
                    <div style="font-size: 1.8rem; font-weight: 800; color: #34d399;" id="stat-wins">0</div>
                    <div style="font-size: 0.78rem; color: #a1a1aa; text-transform: uppercase;">Victoires</div>
                </div>
                <div style="background: var(--card-inner); padding: 1rem; border-radius: 0.8rem; text-align: center; border: 1px solid var(--card-border);">
                    <div style="font-size: 1.8rem; font-weight: 800; color: #f87171;" id="stat-losses">0</div>
                    <div style="font-size: 0.78rem; color: #a1a1aa; text-transform: uppercase;">Défaites</div>
                </div>
                <div style="background: var(--card-inner); padding: 1rem; border-radius: 0.8rem; text-align: center; border: 1px solid var(--card-border);">
                    <div style="font-size: 1.8rem; font-weight: 800; color: #facc15;" id="stat-ties">0</div>
                    <div style="font-size: 0.78rem; color: #a1a1aa; text-transform: uppercase;">Matchs Nuls</div>
                </div>
                <div style="background: var(--card-inner); padding: 1rem; border-radius: 0.8rem; text-align: center; border: 1px solid var(--card-border);">
                    <div style="font-size: 1.8rem; font-weight: 800; color: #fff;" id="stat-total">0</div>
                    <div style="font-size: 0.78rem; color: #a1a1aa; text-transform: uppercase;">Parties Jouées</div>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                <a href="../../leaderboard.php" class="btn-primary-action" style="text-decoration: none;">
                    Consulter le Classement Mondial 🏆
                </a>
                <button class="btn-secondary-action" onclick="localStorage.removeItem('mg_stat_wins'); localStorage.removeItem('mg_stat_losses'); localStorage.removeItem('mg_stat_ties'); localStorage.removeItem('mg_stat_total'); location.reload();">
                    Réinitialiser mes compteurs locaux
                </button>
            </div>
        </div>
    </div>

    <!-- MODALE : PARAMÈTRES / OPTIONS -->
    <div class="modal-overlay" id="settings-modal">
        <div class="modal-content">
            <button class="modal-close-btn">&times;</button>
            <h3 class="modal-title">Paramètres & Options</h3>
            <p class="modal-subtitle">Personnalisation graphique et réseau local.</p>

            <div class="input-group">
                <label class="input-label">Thème Couleur :</label>
                <select class="input-field theme-selector-select" onchange="window.setSpecificTheme(this.value)">
                    <?php foreach ($gameThemes as $theme): ?>
                        <option value="<?= e($theme["theme_key"]) ?>"><?= e($theme["name"]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group">
                <label class="input-label" for="symbol-x-select">Symbole du joueur X :</label>
                <select id="symbol-x-select" class="input-field">
                    <?php foreach ($gameSymbols as $symbol): ?>
                        <option value="<?= e($symbol["symbol"]) ?>"><?= e($symbol["symbol"] . ' — ' . $symbol["label"]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group">
                <label class="input-label" for="symbol-o-select">Symbole du joueur O :</label>
                <select id="symbol-o-select" class="input-field">
                    <?php foreach ($gameSymbols as $symbol): ?>
                        <option value="<?= e($symbol["symbol"]) ?>"><?= e($symbol["symbol"] . ' — ' . $symbol["label"]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group">
                <label class="input-label">Effets Sonores (Web Audio API) :</label>
                <button class="btn-secondary-action" id="sound-toggle-btn" onclick="const m = SoundEffects.toggleMute(); this.textContent = m ? '🔇 Sons Désactivés' : '🔊 Sons Activés';">
                    🔊 Sons Activés
                </button>
            </div>

            <div class="input-group" style="margin-top: 1rem;">
                <label class="input-label">Adresse IP Locale Détectée :</label>
                <div style="font-family: monospace; font-size: 0.9rem; color: var(--primary); padding: 0.5rem 0;">
                    <?= e($serverIp) ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const soundBtn = document.getElementById('sound-toggle-btn');
            if (soundBtn && typeof SoundEffects !== 'undefined') {
                soundBtn.textContent = SoundEffects.isMuted() ? '🔇 Sons Désactivés' : '🔊 Sons Activés';
            }

            const statsBtn = document.getElementById('stats-pill');
            if (statsBtn) {
                statsBtn.addEventListener('click', () => {
                    document.getElementById('stat-wins').textContent = localStorage.getItem('mg_stat_wins') || '0';
                    document.getElementById('stat-losses').textContent = localStorage.getItem('mg_stat_losses') || '0';
                    document.getElementById('stat-ties').textContent = localStorage.getItem('mg_stat_ties') || '0';
                    document.getElementById('stat-total').textContent = localStorage.getItem('mg_stat_total') || '0';
                });
            }
        });
    </script>
    <script src="../../assets/js/account-presence.js" data-api="api.php" defer></script>

    <footer class="footer">
        © 2026 Space. Tous droits réservés. Version 4.0
    </footer>

</body>
</html>
