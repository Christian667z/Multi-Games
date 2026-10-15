<?php
/**
 * Multi-Games - Jeu de Morpion
 * Interface Haute Définition 4K avec Thèmes Dynamiques & Sans Néon
 */
require_once dirname(dirname(__DIR__)) . '/includes/app.php';
$user = mg_current_user();
$playerDisplayName = $user ? $user['display_name'] : 'Joueur 1';
$requestedGridSize = filter_var($_GET['grid_size'] ?? null, FILTER_VALIDATE_INT);
if (!in_array($requestedGridSize, [3, 5, 10, 12, 15, 19], true)) {
    $requestedGridSize = null;
}
$gameThemes = mg_db()->query('SELECT theme_key, name FROM game_themes ORDER BY custom, name')->fetchAll();
$gameSymbols = mg_db()->query('SELECT symbol, label FROM game_symbols ORDER BY custom, id')->fetchAll();

// Détection de l'adresse IP locale pour le partage Wi-Fi automatique
$serverIp = gethostbyname(gethostname());
if ($serverIp === '127.0.0.1' || empty($serverIp) || str_starts_with($serverIp, '127.')) {
    $serverIp = $_SERVER['SERVER_ADDR'] ?? '172.20.10.4';
}
$port = $_SERVER['SERVER_PORT'] ?? '80';
$portStr = ($port === '80' || $port === 80) ? '' : ':' . $port;
$lanBaseUrl = "http://{$serverIp}{$portStr}/Multi-Games/games/morpion/";
?>
<!DOCTYPE html>
<html lang="fr" data-theme="red">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Morpion by Space - Multi-Games 4K</title>

    <!-- Injection de l'URL LAN fiable calculée en PHP -->
    <script>
        window.LAN_BASE_URL = <?= json_encode($lanBaseUrl) ?>;
        window.MG_CSRF = <?= json_encode(mg_csrf_token()) ?>;
        window.SPACE_CORE_GRID_SIZE = <?= json_encode($requestedGridSize) ?>;
        window.MG_PLAYER_NAME = <?= json_encode($playerDisplayName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>

    <!-- Stylesheets -->
    <link rel="stylesheet" href="../../assets/css/themes/themes.css">
    <link rel="stylesheet" href="../../assets/css/base.css">
    <style><?= mg_custom_theme_css() ?></style>

    <!-- Scripts Principaux -->
    <script src="../../assets/js/theme.js"></script>
    <script src="../../assets/js/audio.js" defer></script>
    <script src="bot.js" defer></script>
    <script src="games.js?v=space-core" defer></script>
    <script src="../../dev/livereload.js" defer></script>
</head>
<body>

    <!-- Barre supérieure de navigation / Statut Space -->
    <header class="topbar">
        <a href="../../index.php" class="brand-wrapper" title="Retour à l'accueil Multi-Games">
            <div class="brand-icon">
                <span class="x-sym">✕</span>
                <span class="o-sym">○</span>
            </div>
            <div class="brand-title">Morpion <span>by Space</span></div>
        </a>

        <div class="topbar-actions">
            <a class="btn-topbar" href="../../space-core/">Parler à Space</a>
            <?php if ($user): ?>
                <a class="btn-topbar" href="../../account.php"><?= mg_escape($user['display_name']) ?></a>
                <?php if ($user['role'] === 'admin'): ?>
                    <a class="btn-topbar" href="../../admin.php">Administration</a>
                <?php endif; ?>
            <?php else: ?>
                <a class="btn-topbar" href="../../account.php">Connexion</a>
            <?php endif; ?>

            <!-- Bouton Sélecteur Thème -->
            <button class="btn-topbar palette-btn" id="theme-palette-btn" title="Changer de thème (Rouge, Ambre, Violet, Cyan, Émeraude)">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/>
                    <circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/>
                    <circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/>
                    <circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/>
                    <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>
                </svg>
            </button>

            <!-- Bouton Partager -->
            <button class="btn-topbar" id="btn-share-trigger" title="Partager ou créer une partie en ligne">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                    <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                </svg>
                <span>Partager</span>
            </button>

            <!-- Statistiques Persistantes -->
            <div class="level-badge" id="stats-pill" style="cursor: pointer;" title="Voir vos statistiques" onclick="document.getElementById('stats-modal').style.display='flex'">
                <span>🏆</span>
                <div id="stats-pill-text">0 V | 0 D</div>
            </div>

            <!-- Retour Hub -->
            <a href="../../index.php" class="btn-topbar" title="Retourner à la liste des jeux">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                </svg>
                <span>Hub Jeux</span>
            </a>

            <!-- Options / Paramètres -->
            <button class="btn-topbar admin-btn" id="open-settings">
                <span>⚙️ Options</span>
            </button>
        </div>
    </header>

    <!-- VUE 1 : MENU PRINCIPAL -->
    <main class="main-container" id="menu-view">
        <div class="menu-header">
            <div class="menu-pretitle">
                <span>MORPION · 3X3 CLASSIQUE OU 5 À ALIGNER</span>
            </div>
            <h1 class="menu-title">MENU</h1>
        </div>

        <div class="modes-grid">
            <!-- CARTE 1 : HORS LIGNE / LOCAL -->
            <div class="mode-card">
                <div class="card-top">
                    <span class="card-step-num">01 · LOCAL</span>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" opacity="0.6">
                        <line x1="8" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="16" y2="21"/>
                        <line x1="3" y1="8" x2="21" y2="8"/><line x1="3" y1="16" x2="21" y2="16"/>
                    </svg>
                </div>

                <div class="card-body">
                    <div class="card-illustration">
                        <svg width="130" height="75" viewBox="0 0 140 80" fill="none" stroke="currentColor">
                            <path d="M20 20 L120 20 M20 40 L120 40 M20 60 L120 60" stroke="var(--card-border)" stroke-width="2"/>
                            <path d="M45 10 L45 70 M85 10 L85 70" stroke="var(--card-border)" stroke-width="2"/>
                            <!-- X et O -->
                            <text x="25" y="36" fill="var(--color-x)" font-size="18" font-weight="900" font-family="sans-serif">X</text>
                            <text x="60" y="36" fill="var(--color-o)" font-size="18" font-weight="900" font-family="sans-serif">O</text>
                            <text x="98" y="36" fill="var(--color-x)" font-size="18" font-weight="900" font-family="sans-serif">X</text>
                            <text x="25" y="56" fill="var(--color-o)" font-size="18" font-weight="900" font-family="sans-serif">O</text>
                            <text x="60" y="56" fill="var(--color-x)" font-size="18" font-weight="900" font-family="sans-serif">X</text>
                            <text x="98" y="56" fill="var(--color-o)" font-size="18" font-weight="900" font-family="sans-serif">O</text>
                        </svg>
                    </div>

                    <h2 class="card-title">Hors ligne</h2>
                    <p class="card-desc">Une partie à deux, sur cet appareil.</p>

                    <div class="card-actions">
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
                    <h2 class="card-title" style="margin-top: 1.5rem;">Contre un bot</h2>
                    <p class="card-desc">Choisissez son niveau ou laissez-le s'adapter à votre style.</p>

                    <div class="bot-levels-grid">
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
                            <span>🧬</span> Évolutif
                        </button>
                    </div>

                    <div class="card-footer-note" style="margin-top: 1.4rem;">Du joueur débutant au Minimax invincible.</div>
                </div>
            </div>

            <!-- CARTE 3 : DISTANCE / WI-FI -->
            <div class="mode-card">
                <div class="card-top">
                    <span class="card-step-num">03 · WI-FI</span>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" opacity="0.8">
                        <path d="M5 12.55a11 11 0 0 1 14.08 0"/>
                        <path d="M1.42 9a16 16 0 0 1 21.16 0"/>
                        <path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>
                        <circle cx="12" cy="20" r="1"/>
                    </svg>
                </div>

                <div class="card-body">
                    <div class="card-illustration">
                        <svg width="120" height="70" viewBox="0 0 120 70" fill="none">
                            <circle cx="45" cy="35" r="24" stroke="var(--card-border)" stroke-width="2" stroke-dasharray="4 4"/>
                            <circle cx="75" cy="35" r="24" stroke="var(--card-border)" stroke-width="2" stroke-dasharray="4 4"/>
                            <text x="35" y="44" fill="var(--color-x)" font-size="24" font-weight="900" font-family="sans-serif">X</text>
                            <text x="70" y="44" fill="var(--color-o)" font-size="24" font-weight="900" font-family="sans-serif">O</text>
                        </svg>
                    </div>

                    <h2 class="card-title">À distance</h2>
                    <p class="card-desc">Affrontez un autre joueur sur le même réseau Wi-Fi.</p>

                    <div class="card-actions">
                        <button class="btn-primary-action" id="btn-wifi-host">
                            Match amical en ligne
                        </button>
                        <button class="btn-secondary-action" id="btn-wifi-join">
                            Rejoindre une partie
                        </button>
                    </div>

                    <div class="card-footer-note">Partagez l'IP locale ou le code de salon.</div>
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

            <!-- Tableau des scores complet (avec nuls) -->
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
                        <option value="<?= mg_escape($theme['theme_key']) ?>"><?= mg_escape($theme['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="arena-setting">Symbole X
                <select id="arena-symbol-x-select" class="input-field" aria-label="Symbole du joueur X">
                    <?php foreach ($gameSymbols as $symbol): ?>
                        <option value="<?= mg_escape($symbol['symbol']) ?>"><?= mg_escape($symbol['symbol']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="arena-setting">Symbole O
                <select id="arena-symbol-o-select" class="input-field" aria-label="Symbole du joueur O">
                    <?php foreach ($gameSymbols as $symbol): ?>
                        <option value="<?= mg_escape($symbol['symbol']) ?>"><?= mg_escape($symbol['symbol']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <!-- Indicateur clair de tour -->
        <div id="turn-indicator" class="turn-indicator">À votre tour (✕)</div>

        <!-- Bannière d'état (Victoire, Égalité, Déconnexion) -->
        <div id="status-banner" style="display: none; font-size: 1.25rem; font-weight: 800; color: var(--primary); margin-bottom: 0.8rem; text-align: center;"></div>

        <!-- Conteneur de la grille -->
        <div class="board-container" id="board-container">
            <div id="game-board" class="board-grid-3x3"></div>
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

            <div class="input-group" <?= $user ? 'hidden' : '' ?>>
                <label class="input-label">Pseudo Joueur 1 (✕) :</label>
                <input type="text" id="local-p1-name" class="input-field" value="<?= mg_escape($playerDisplayName) ?>" maxlength="30" <?= $user ? 'readonly' : '' ?>>
            </div>

            <div class="input-group">
                <label class="input-label">Pseudo Joueur 2 (○) :</label>
                <input type="text" id="local-p2-name" class="input-field" value="Joueur 2" maxlength="20">
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

            <div class="input-group" <?= $user ? 'hidden' : '' ?>>
                <label class="input-label">Votre Pseudo :</label>
                <input type="text" id="bot-player-name" class="input-field" value="<?= mg_escape($playerDisplayName) ?>" maxlength="30" <?= $user ? 'readonly' : '' ?>>
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

            <div class="input-group" <?= $user ? 'hidden' : '' ?>>
                <label class="input-label">Joueur X (Hôte) :</label>
                <input type="text" id="wifi-host-name" class="input-field" value="<?= mg_escape($playerDisplayName) ?>" maxlength="30" <?= $user ? 'readonly' : '' ?>>
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
                    <input type="text" class="copy-link-input" id="wifi-share-link" readonly value="<?= htmlspecialchars($lanBaseUrl) ?>">
                    <button class="btn-copy" id="btn-copy-wifi-link">Copier</button>
                </div>
            </div>

            <div style="display: flex; align-items: center; justify-content: center; gap: 8px; color: #94a3b8; font-size: 0.88rem; margin-top: 1.5rem;">
                <span class="lr-dot" style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#ef4444;"></span>
                <span>En attente de connexion du Joueur 2...</span>
            </div>
        </div>
    </div>

    <!-- MODALE : REJOINDRRE SALON WI-FI -->
    <div class="modal-overlay" id="wifi-join-modal">
        <div class="modal-content">
            <button class="modal-close-btn">&times;</button>
            <h3 class="modal-title">Rejoindre une partie Wi-Fi</h3>
            <p class="modal-subtitle">Entrez le code à 4 lettres affiché sur l'écran de l'hôte.</p>

            <div class="input-group" <?= $user ? 'hidden' : '' ?>>
                <label class="input-label">Votre pseudo :</label>
                <input type="text" id="join-player-name" class="input-field" placeholder="Ex: Alex" value="<?= mg_escape($playerDisplayName) ?>" maxlength="30" <?= $user ? 'readonly' : '' ?>>
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

            <div class="input-group" <?= $user ? 'hidden' : '' ?>>
                <label class="input-label">Pseudo Joueur 1 :</label>
                <input type="text" id="tourney-p1-name" class="input-field" value="<?= mg_escape($playerDisplayName) ?>" maxlength="30" <?= $user ? 'readonly' : '' ?>>
            </div>

            <div class="input-group">
                <label class="input-label">Pseudo Joueur 2 :</label>
                <input type="text" id="tourney-p2-name" class="input-field" value="Joueur 2" maxlength="20">
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

    <!-- MODALE : STATISTIQUES DU JOUEUR -->
    <div class="modal-overlay" id="stats-modal">
        <div class="modal-content">
            <button class="modal-close-btn">&times;</button>
            <h3 class="modal-title">Vos Statistiques</h3>
            <p class="modal-subtitle">Historique sauvegardé dans votre navigateur.</p>

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

            <button class="btn-secondary-action" onclick="localStorage.removeItem('mg_stat_wins'); localStorage.removeItem('mg_stat_losses'); localStorage.removeItem('mg_stat_ties'); localStorage.removeItem('mg_stat_total'); location.reload();">
                Réinitialiser les statistiques
            </button>
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
                        <option value="<?= mg_escape($theme['theme_key']) ?>"><?= mg_escape($theme['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
                <div class="input-group">
                    <label class="input-label" for="symbol-x-select">Symbole du joueur X :</label>
                    <select id="symbol-x-select" class="input-field">
                        <?php foreach ($gameSymbols as $symbol): ?>
                            <option value="<?= mg_escape($symbol['symbol']) ?>"><?= mg_escape($symbol['symbol'] . ' — ' . $symbol['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label class="input-label" for="symbol-o-select">Symbole du joueur O :</label>
                    <select id="symbol-o-select" class="input-field">
                        <?php foreach ($gameSymbols as $symbol): ?>
                            <option value="<?= mg_escape($symbol['symbol']) ?>"><?= mg_escape($symbol['symbol'] . ' — ' . $symbol['label']) ?></option>
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
                    <?= htmlspecialchars($serverIp) ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Synchroniser le libellé audio dès l'ouverture
        document.addEventListener('DOMContentLoaded', () => {
            const soundBtn = document.getElementById('sound-toggle-btn');
            if (soundBtn && typeof SoundEffects !== 'undefined') {
                soundBtn.textContent = SoundEffects.isMuted() ? '🔇 Sons Désactivés' : '🔊 Sons Activés';
            }

            // Mettre à jour stats modal quand ouvert
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

    <!-- Pied de page -->
    <footer class="footer">
        © 2026 Space. Tous droits réservés. Version 4.0
    </footer>

</body>
</html>
