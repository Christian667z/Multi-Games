<?php
/**
 * Tableau de bord administrateur.
 *
 * Stratégie : rendu serveur complet (utilisable et lisible sans JavaScript),
 * puis le script assets/js/admin.js reprend la main à partir du même jeu de
 * données exposé dans #admin-bootstrap. Aucun « Chargement… » visible, et le
 * HTML initial reste accessible si JS est désactivé.
 */

$summary = $summary ?? [];
$botLevels = $botLevels ?? [];
$popularModes = $popularModes ?? [];
$players = $players ?? [];
$themes = $themes ?? [];
$symbols = $symbols ?? [];
$rooms = $rooms ?? [];
$spaceKnowledge = $spaceKnowledge ?? [];
$spaceUsage = $spaceUsage ?? [];
$groqConfigured = $groqConfigured ?? false;
$now = $now ?? time();
// Présence temps réel par contexte : disponible seulement si le modèle de données
// est accessible depuis la vue (aucune requête supplémentaire n'est faite ici).
$livePlayers = $livePlayers ?? [];
if (!$livePlayers && isset($db) && is_object($db) && method_exists($db, 'getLivePlayers')) {
    $livePlayers = $db->getLivePlayers('morpion', 90);
}

/** Durée écoulée, format court. */
$relTime = static function (int $timestamp) use ($now): string {
    if ($timestamp <= 0) {
        return '—';
    }
    $diff = max(0, $now - $timestamp);
    if ($diff < 10) {
        return "à l'instant";
    }
    if ($diff < 60) {
        return 'il y a ' . $diff . ' s';
    }
    if ($diff < 3600) {
        return 'il y a ' . intdiv($diff, 60) . ' min';
    }
    if ($diff < 86400) {
        return 'il y a ' . intdiv($diff, 3600) . ' h';
    }
    return 'il y a ' . intdiv($diff, 86400) . ' j';
};

$modeLabels = ['local' => 'Hors ligne', 'bot' => 'Contre Space', 'wifi' => 'Wi-Fi'];

/** Libellés des contextes de présence renvoyés par l'API. */
$contextLabels = [
    'morpion' => 'Morpion',
    'space' => 'Space Core',
    'hub' => 'Hub Jeux',
    'admin' => 'Administration',
];

/** Paliers de rang affichables, avec leur icône et leur libellé. */
$rankMeta = [
    'bronze' => ['Bronze', '🥉'],
    'silver' => ['Argent', '🥈'],
    'gold' => ['Or', '🥇'],
    'platinum' => ['Platine', '🛡️'],
    'diamond' => ['Diamant', '💎'],
    'legend' => ['Légende', '👑'],
];

/** Valeurs dérivées de la liste des joueurs : aucune requête supplémentaire. */
$players = array_values($players);
$playerCount = count($players);
$rankTiers = array_map(
    static fn(string $key, array $meta): array => ['key' => $key, 'name' => $meta[0], 'icon' => $meta[1]],
    array_keys($rankMeta),
    array_values($rankMeta)
);
$topPlayer = $topPlayer ?? null;
$rankDistribution = $rankDistribution ?? array_map(
    static fn(array $tier): array => $tier + ['players' => 0],
    $rankTiers
);
$rankTotal = array_sum(array_map(static fn(array $row): int => (int)$row['players'], $rankDistribution));
$morpionGames = 0;
$morpionCompleted = 0;
$morpionRanked = 0;
foreach ($popularModes as $modeRow) {
    $modeGames = (int)($modeRow['games'] ?? 0);
    $morpionGames += $modeGames;
    $recorded = (int)$summary['games'] ?? 0;
    if ($recorded > 0) {
        $morpionCompleted += (int)round($modeGames * ((int)$summary['completed'] / $recorded));
    }
}

/** Échappement d'une clé de rang. */
$rankLabel = static function (array $player) use ($rankMeta): string {
    $key = (string)($player['rank'] ?? 'bronze');
    return (string)($player['rank_label'] ?? ($rankMeta[$key][0] ?? 'Bronze'));
};
$rankIcon = static function (array $player) use ($rankMeta): string {
    $key = (string)($player['rank'] ?? 'bronze');
    return (string)($player['rank_icon'] ?? ($rankMeta[$key][1] ?? '🥉'));
};

$bootstrap = [
    'api' => 'games/morpion/api.php',
    'csrf' => $csrfToken,
    'serverTime' => $now,
    'summary' => $summary,
    'botLevels' => $botLevels,
    'popularModes' => $popularModes,
    'players' => $players,
    'themes' => $themes,
    'symbols' => $symbols,
    'rooms' => $rooms,
    'spaceKnowledge' => $spaceKnowledge,
    'spaceUsage' => $spaceUsage,
    'groqConfigured' => (bool)$groqConfigured,
    // Valeurs dérivées côté serveur : le premier rendu est complet sans requête.
    'topPlayer' => $topPlayer,
    'rankDistribution' => $rankDistribution,
    'rankTiers' => $rankTiers,
    'morpion' => [
        'games' => $morpionGames,
        'completed' => $morpionCompleted,
        'ranked' => $morpionRanked,
    ],
    'contextLabels' => $contextLabels,
];
?>
<!DOCTYPE html>
<html lang="fr" data-theme="<?= e($currentTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Administration - Multi-Games</title>
    <link rel="stylesheet" href="assets/css/themes/themes.css">
    <link rel="stylesheet" href="assets/css/base.css">
    <style><?= $customThemeCss ?>
/* --- Tableau de bord administrateur : styles dédiés, pilotés par les variables de thème --- */
.admin-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
    padding: 0.9rem 1rem;
    border: 1px solid var(--card-border);
    border-radius: 0.9rem;
    background: var(--card-bg);
}
.admin-toolbar-end { display: flex; flex-wrap: wrap; align-items: end; gap: 0.75rem; margin-left: auto; }
.admin-field { display: grid; gap: 0.3rem; min-width: 0; }
.admin-field > span {
    color: var(--text-muted);
    font-size: 0.72rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
.admin-field input,
.admin-field select {
    min-height: 40px;
    min-width: 150px;
    padding: 0.5rem 0.7rem;
    border: 1px solid var(--card-border);
    border-radius: 0.55rem;
    background: var(--card-inner);
    color: var(--text-main);
    font: inherit;
    font-size: 0.88rem;
}
.admin-field input[type="search"] { min-width: 230px; }
.admin-field input:focus-visible,
.admin-field select:focus-visible,
.admin-action-button:focus-visible,
.admin-sort:focus-visible,
.admin-pager button:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 1px;
}
.admin-hint { color: var(--text-dim); font-size: 0.75rem; line-height: 1.5; }
.admin-hint kbd {
    padding: 0.05rem 0.35rem;
    border: 1px solid var(--card-border);
    border-radius: 0.3rem;
    background: var(--card-inner);
    color: var(--text-muted);
    font: inherit;
    font-size: 0.72rem;
}
.admin-heading-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 0.7rem; }
.admin-live {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.45rem 0.8rem;
    border: 1px solid var(--card-border);
    border-radius: 999px;
    background: var(--card-inner);
    color: var(--text-muted);
    font-size: 0.8rem;
    white-space: nowrap;
}
.admin-live-dot { width: 0.5rem; height: 0.5rem; border-radius: 50%; background: var(--text-dim); flex: 0 0 auto; }
.admin-live[data-state="loading"] .admin-live-dot { background: #f59e0b; animation: admin-pulse 1s ease-in-out infinite; }
.admin-live[data-state="ok"] .admin-live-dot { background: #34d399; box-shadow: 0 0 9px rgba(52, 211, 153, 0.65); }
.admin-live[data-state="error"] .admin-live-dot { background: #ef4444; }
@keyframes admin-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.25; } }
@media (prefers-reduced-motion: reduce) {
    .admin-live[data-state="loading"] .admin-live-dot { animation: none; }
}
.admin-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 0.75rem;
    margin-bottom: 0.9rem;
    padding: 0.85rem 1rem;
    border: 1px solid var(--card-border);
    border-radius: 0.9rem;
    background: var(--card-inner);
}
.admin-filters-end { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; margin-left: auto; }
.admin-result-count { margin: 0 0 0.6rem; color: var(--text-muted); font-size: 0.82rem; }
.admin-bulkbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.8rem;
    padding: 0.6rem 0.85rem;
    border: 1px solid var(--primary);
    border-radius: 0.7rem;
    background: var(--card-inner);
}
.admin-bulkbar[hidden] { display: none; }
.admin-bulkbar strong { font-size: 0.85rem; color: var(--text-main); }
.admin-table tbody tr { transition: background 0.12s ease; }
.admin-table tbody tr:hover { background: var(--card-inner); }
.admin-table tbody tr.is-selected { background: var(--card-inner); box-shadow: inset 3px 0 0 var(--primary); }
.admin-table tbody tr.is-banned td { opacity: 0.65; }
.admin-table th.admin-col-select,
.admin-table td.admin-col-select { width: 2.6rem; padding-right: 0; }
.admin-col-select input { width: 1.05rem; height: 1.05rem; accent-color: var(--primary); cursor: pointer; }
.admin-sort {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0;
    border: 0;
    background: none;
    color: inherit;
    font: inherit;
    font-size: inherit;
    letter-spacing: inherit;
    text-transform: inherit;
    cursor: pointer;
}
.admin-sort:hover { color: var(--text-main); }
.admin-sort-ind { opacity: 0.35; font-size: 0.7rem; }
.admin-sort[data-dir] .admin-sort-ind { opacity: 1; color: var(--primary); }
.admin-cell-main { display: block; }
.admin-cell-sub { display: block; margin-top: 0.15rem; color: var(--text-dim); font-size: 0.72rem; }
.admin-tag {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    border: 1px solid var(--card-border);
    border-radius: 999px;
    background: var(--card-inner);
    color: var(--text-muted);
    font-size: 0.7rem;
}
.admin-tag.is-danger { border-color: var(--primary); color: var(--primary); }
.admin-swatch {
    display: inline-block;
    width: 1rem;
    height: 1rem;
    margin-right: 0.35rem;
    border: 1px solid var(--card-border);
    border-radius: 0.25rem;
    vertical-align: -0.15rem;
}
.admin-symbol { font-size: 1.15rem; }
.admin-actions { display: flex; flex-wrap: nowrap; gap: 0.25rem; }
.admin-action-button.is-danger:hover { border-color: var(--primary); color: var(--primary); }
.admin-action-button:disabled { opacity: 0.45; cursor: progress; }
.admin-action-button.is-busy { position: relative; color: transparent; }
.admin-action-button.is-busy::after {
    content: "";
    position: absolute;
    inset: 0;
    margin: auto;
    width: 0.85rem;
    height: 0.85rem;
    border: 2px solid var(--text-muted);
    border-top-color: transparent;
    border-radius: 50%;
    animation: admin-spin 0.7s linear infinite;
}
@keyframes admin-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .admin-action-button.is-busy::after { animation-duration: 2s; } }
.admin-pager { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; margin-top: 0.8rem; color: var(--text-muted); font-size: 0.82rem; }
.admin-pager button {
    min-height: 34px;
    padding: 0.3rem 0.7rem;
    border: 1px solid var(--card-border);
    border-radius: 0.5rem;
    background: var(--card-inner);
    color: var(--text-main);
    font: inherit;
    font-size: 0.8rem;
    cursor: pointer;
}
.admin-pager button:disabled { opacity: 0.4; cursor: default; }
.admin-skeleton { display: block; height: 0.85rem; border-radius: 0.3rem; background: var(--card-inner); }
.admin-page[aria-busy="true"] .admin-table tbody { opacity: 0.55; }
.admin-toasts {
    position: fixed;
    right: 1rem;
    bottom: 1rem;
    z-index: 60;
    display: grid;
    gap: 0.5rem;
    width: min(22rem, calc(100vw - 2rem));
}
.admin-toast {
    display: flex;
    align-items: start;
    gap: 0.6rem;
    padding: 0.7rem 0.85rem;
    border: 1px solid var(--card-border);
    border-left: 3px solid var(--primary);
    border-radius: 0.6rem;
    background: var(--card-bg);
    color: var(--text-main);
    font-size: 0.85rem;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
    animation: admin-toast-in 0.18s ease-out;
}
.admin-toast.is-success { border-left-color: #34d399; }
.admin-toast.is-error { border-left-color: #ef4444; }
.admin-toast p { margin: 0; flex: 1 1 auto; }
.admin-toast button {
    flex: 0 0 auto;
    padding: 0 0.25rem;
    border: 0;
    background: none;
    color: var(--text-muted);
    font: inherit;
    font-size: 1rem;
    line-height: 1;
    cursor: pointer;
}
@keyframes admin-toast-in { from { opacity: 0; transform: translateY(0.5rem); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .admin-toast { animation: none; } }
.admin-dialog {
    width: min(30rem, calc(100vw - 2rem));
    padding: 0;
    border: 1px solid var(--card-border);
    border-radius: 0.9rem;
    background: var(--card-bg);
    color: var(--text-main);
}
.admin-dialog::backdrop { background: rgba(0, 0, 0, 0.6); }
.admin-dialog-form { display: grid; gap: 0.9rem; padding: 1.1rem 1.2rem 1.2rem; }
.admin-dialog-form h2 { margin: 0; font-size: 1.15rem; }
.admin-dialog-form > p { margin: 0; color: var(--text-muted); font-size: 0.88rem; line-height: 1.5; }
.admin-dialog-fields { display: grid; gap: 0.75rem; }
.admin-dialog-fields label { display: grid; gap: 0.3rem; color: var(--text-muted); font-size: 0.78rem; }
.admin-dialog-fields input {
    min-height: 42px;
    padding: 0.55rem 0.7rem;
    border: 1px solid var(--card-border);
    border-radius: 0.55rem;
    background: var(--card-inner);
    color: var(--text-main);
    font: inherit;
}
.admin-dialog-fields input:focus-visible { outline: 2px solid var(--primary); outline-offset: 1px; }
.admin-dialog-error { margin: 0; color: #f87171; font-size: 0.82rem; }
.admin-dialog-error[hidden] { display: none; }
.admin-dialog-actions { display: flex; flex-wrap: wrap; justify-content: end; gap: 0.5rem; }
.admin-dialog-actions .is-primary { border-color: var(--primary); background: var(--primary); color: var(--primary-text, #fff); }
.admin-dialog-actions .is-danger { border-color: var(--primary); color: var(--primary); }
/* L'attribut hidden doit primer sur les display: flex/grid définis dans ce bloc,
   sinon un paginateur ou une barre d'actions masqués restent affichés. */
.admin-page [hidden] { display: none !important; }
@media (max-width: 720px) {
    .admin-toolbar-end, .admin-filters-end { margin-left: 0; }
    .admin-field input, .admin-field select, .admin-field input[type="search"] { min-width: 100%; }
    .admin-field { width: 100%; }
    .admin-toasts { left: 1rem; right: 1rem; width: auto; }
}

/* --- Navigation par onglets ---------------------------------------- */
.admin-tablist {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin: 0.4rem 0 1.1rem;
    padding: 0.4rem;
    border: 1px solid var(--card-border);
    border-radius: 1rem;
    background: var(--card-bg);
}
.admin-tab {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    min-height: 42px;
    padding: 0.5rem 1rem;
    border: 1px solid transparent;
    border-radius: 0.75rem;
    background: transparent;
    color: var(--text-muted);
    font: inherit;
    font-size: 0.9rem;
    cursor: pointer;
}
.admin-tab:hover { color: var(--text-main); background: var(--card-inner); }
.admin-tab[aria-selected="true"] {
    border-color: var(--primary);
    background: var(--badge-bg);
    color: var(--text-main);
}
.admin-tab:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.admin-tab.is-disabled,
.admin-tab:disabled { opacity: 0.45; cursor: not-allowed; }
.admin-tab-badge {
    padding: 0.05rem 0.45rem;
    border: 1px solid var(--badge-border, var(--card-border));
    border-radius: 999px;
    background: var(--badge-bg);
    color: var(--text-dim);
    font-size: 0.68rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.admin-tab-panel { display: block; }
.admin-tab-panel[hidden] { display: none !important; }
.space-knowledge-form { display: grid; gap: 0.85rem; max-width: 760px; }
.space-knowledge-form textarea {
    min-height: 150px;
    padding: 0.65rem 0.75rem;
    border: 1px solid var(--card-border);
    border-radius: 0.55rem;
    background: var(--card-inner);
    color: var(--text-main);
    font: inherit;
    resize: vertical;
}
.space-knowledge-form textarea:focus-visible { outline: 2px solid var(--primary); outline-offset: 1px; }
.space-knowledge-content { max-width: 38rem; white-space: pre-wrap; overflow-wrap: anywhere; }
.space-source-tag { white-space: nowrap; }

/* --- Grilles de cartes --------------------------------------------- */
.admin-grid-2 {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 1.2rem;
    align-items: start;
}
.admin-grid-2 > .admin-panel { margin-top: 1.2rem; }
.admin-mini-title {
    margin: 1.2rem 0 0.6rem;
    color: var(--text-muted);
    font-size: 0.78rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
.admin-stat-note { color: var(--text-dim); font-size: 0.72rem; }

/* --- Carte du joueur n°1 ------------------------------------------- */
.admin-top-card {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 1rem 1.3rem;
    align-items: center;
    padding: 1.1rem 1.2rem;
    border: 1px solid var(--primary);
    border-radius: 1rem;
    background: radial-gradient(circle at 0% 0%, var(--badge-bg) 0%, var(--card-inner) 70%);
}
.admin-top-avatar {
    display: grid;
    place-items: center;
    width: 4.2rem;
    height: 4.2rem;
    border: 1px solid var(--card-border);
    border-radius: 50%;
    background: var(--card-bg);
    font-size: 2.1rem;
    line-height: 1;
}
.admin-top-name { margin: 0; font-size: 1.3rem; }
.admin-top-meta { margin: 0.25rem 0 0; color: var(--text-muted); font-size: 0.85rem; }
.admin-top-values {
    grid-column: 1 / -1;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 0.7rem;
    margin: 0;
}
.admin-top-value {
    display: grid;
    gap: 0.15rem;
    padding: 0.6rem 0.7rem;
    border: 1px solid var(--card-border);
    border-radius: 0.7rem;
    background: var(--card-bg);
}
.admin-top-value dt {
    color: var(--text-dim);
    font-size: 0.68rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
.admin-top-value dd { margin: 0; font-size: 1rem; font-weight: 600; }

/* --- Barres de progression ----------------------------------------- */
.admin-progress {
    display: block;
    width: 100%;
    min-width: 90px;
    height: 0.42rem;
    margin-top: 0.3rem;
    border-radius: 999px;
    background: var(--secondary-bg);
    overflow: hidden;
}
.admin-progress-bar {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: var(--primary);
}
.admin-share {
    display: block;
    width: 8rem;
    padding: 0.35rem 0.5rem;
    border: 1px solid var(--card-border);
    border-radius: 0.45rem;
    background: var(--card-inner);
    overflow: hidden;
}
.admin-rank-cell { display: inline-flex; align-items: center; gap: 0.35rem; white-space: nowrap; }

/* --- Interrupteurs -------------------------------------------------- */
.admin-switch {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    cursor: pointer;
    color: var(--text-main);
    font-size: 0.95rem;
}
.admin-switch input {
    width: 1.15rem;
    height: 1.15rem;
    accent-color: var(--primary);
    cursor: pointer;
}
.admin-status-line { margin: 0.7rem 0 0; color: var(--text-muted); font-size: 0.85rem; }
.admin-status-line[data-state="on"] { color: #34d399; }
.admin-status-line[data-state="off"] { color: var(--primary); }

/* --- Gravité du journal -------------------------------------------- */
.admin-severity { font-size: 1rem; line-height: 1; }
.admin-severity[data-severity="success"] { color: #34d399; }
.admin-severity[data-severity="warning"] { color: #f59e0b; }
.admin-severity[data-severity="danger"] { color: #ef4444; }
.admin-cell-wrap { display: block; max-width: 34rem; white-space: normal; overflow-wrap: anywhere; }
.admin-event-actor { display: block; color: var(--text-muted); font-size: 0.8rem; }
.admin-event-time { display: block; margin-top: 0.15rem; color: var(--text-dim); font-size: 0.72rem; }
.admin-live-name { display: inline-flex; align-items: center; gap: 0.4rem; }
.admin-live-avatar { font-size: 1.05rem; }
.admin-danger-zone { border-color: var(--primary); }
.admin-inline-form { display: flex; flex-wrap: wrap; align-items: end; gap: 0.6rem; }
.admin-inline-form .admin-field { min-width: 200px; }
@media (max-width: 720px) {
    .admin-top-card { grid-template-columns: 1fr; }
    .admin-share { width: 100%; }
    .admin-tab { flex: 1 1 auto; justify-content: center; }
}
</style>
    <script src="assets/js/theme.js"></script>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="brand-wrapper">
            <div class="brand-title">Multi-Games <span>Administration</span></div>
        </a>
        <div class="topbar-actions">
            <span class="btn-topbar">👑 <?= e($user["display_name"]) ?></span>
            <a class="btn-topbar" href="leaderboard.php">Classement 🏆</a>
            <a class="btn-topbar" href="account.php">Mon compte</a>
            <a class="btn-topbar" href="index.php">Hub Jeux</a>
            <form method="post" action="account.php" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="btn-topbar" type="submit">Déconnexion</button>
            </form>
        </div>
    </header>

    <main class="main-container admin-page" id="admin-page">
        <div class="admin-heading">
            <div>
                <div class="hub-tag">Panneau administrateur</div>
                <h1 class="hub-title">Pilotage de la plateforme</h1>
                <p class="hub-subtitle">Vue globale de Multi-Games, univers du Morpion, mode Classé et journal des événements.</p>
            </div>
            <div class="admin-heading-actions">
                <span class="admin-live" id="admin-live" data-state="loading">
                    <span class="admin-live-dot" aria-hidden="true"></span>
                    <span id="admin-live-label">Chargement…</span>
                </span>
                <button class="btn-primary-action admin-refresh" id="admin-refresh" type="button">Actualiser</button>
            </div>
        </div>

        <div class="admin-toolbar" role="group" aria-label="Options du tableau de bord">
            <label class="admin-field">
                <span>Actualisation auto</span>
                <select id="admin-autorefresh">
                    <option value="0">Désactivée</option>
                    <option value="10">Toutes les 10 s</option>
                    <option value="15" selected>Toutes les 15 s</option>
                    <option value="30">Toutes les 30 s</option>
                    <option value="60">Toutes les 60 s</option>
                </select>
            </label>
            <div class="admin-toolbar-end">
                <p class="admin-hint">
                    Raccourcis : <kbd>/</kbd> rechercher · <kbd>R</kbd> actualiser · <kbd>Échap</kbd> désélectionner.<br>
                    L’actualisation se met en pause quand l’onglet est masqué.
                </p>
            </div>
        </div>

        <p class="account-alert" id="admin-error" role="alert" hidden></p>

        <!-- Onglets : le premier rendu serveur affiche tous les panneaux (donc lisible
             sans JavaScript) ; admin.js masque ensuite les onglets inactifs. -->
        <div class="admin-tablist" role="tablist" aria-label="Sections du panneau d’administration" id="admin-tabs">
            <button class="admin-tab" type="button" role="tab" id="admin-tab-global" data-tab="global"
                    aria-controls="admin-panel-global" aria-selected="true" tabindex="0">Global</button>
            <button class="admin-tab" type="button" role="tab" id="admin-tab-morpion" data-tab="morpion"
                    aria-controls="admin-panel-morpion" aria-selected="false" tabindex="-1">Morpion</button>
            <button class="admin-tab" type="button" role="tab" id="admin-tab-space" data-tab="space"
                    aria-controls="admin-panel-space" aria-selected="false" tabindex="-1">Space IA</button>
            <button class="admin-tab" type="button" role="tab" id="admin-tab-social" data-tab="social"
                    aria-controls="admin-panel-social" aria-selected="false" tabindex="-1">Social</button>
            <button class="admin-tab is-disabled" type="button" role="tab" id="admin-tab-connect4" data-tab="connect4"
                    aria-controls="admin-panel-connect4" aria-selected="false" tabindex="-1" disabled
                    title="Univers Puissance 4 à venir">Puissance 4 <span class="admin-tab-badge">Bientôt</span></button>
            <button class="admin-tab is-disabled" type="button" role="tab" id="admin-tab-pong" data-tab="pong"
                    aria-controls="admin-panel-pong" aria-selected="false" tabindex="-1" disabled
                    title="Univers Pong à venir">Pong <span class="admin-tab-badge">Bientôt</span></button>
            <button class="admin-tab is-disabled" type="button" role="tab" id="admin-tab-naval" data-tab="naval"
                    aria-controls="admin-panel-naval" aria-selected="false" tabindex="-1" disabled
                    title="Univers Bataille Navale à venir">Bataille Navale <span class="admin-tab-badge">Bientôt</span></button>
        </div>

        <div class="admin-tab-panel" id="admin-panel-social" role="tabpanel" aria-labelledby="admin-tab-social"
             tabindex="0" hidden data-tab-panel="social">
            <section class="account-stat-grid admin-summary" aria-label="Statistiques sociales">
                <div class="account-stat"><strong id="social-friendships">—</strong><span>Amitiés</span></div>
                <div class="account-stat"><strong id="social-pending">—</strong><span>Demandes en attente</span></div>
                <div class="account-stat"><strong id="social-blocked">—</strong><span>Joueurs bloqués</span></div>
                <div class="account-stat"><strong id="social-lonely">—</strong><span>Joueurs sans ami</span></div>
            </section>
            <section class="admin-panel">
                <div class="admin-section-heading"><div><h2>Réglages sociaux</h2><p>Contrôles appliqués immédiatement aux profils et aux demandes d’amitié.</p></div></div>
                <div id="social-settings" class="admin-inline-form"></div>
            </section>
            <section class="admin-panel">
                <div class="admin-section-heading"><div><h2>Modération des profils</h2><p>Ces sanctions sociales n’interdisent pas la connexion et ne bannissent pas le compte.</p></div></div>
                <label class="admin-field"><span>Rechercher un joueur</span><input type="search" id="social-player-search" placeholder="Pseudo, nom public ou bio"></label>
                <div class="admin-table-wrap"><table class="admin-table">
                    <thead><tr><th>Joueur</th><th>Nom public</th><th>Bio</th><th>Visibilité</th><th>État social</th><th>Actions</th></tr></thead>
                    <tbody id="social-player-rows"><tr><td colspan="6" class="admin-empty">Chargement…</td></tr></tbody>
                </table></div>
            </section>
            <section class="admin-panel">
                <div class="admin-section-heading"><div><h2>Signalements en attente</h2><p>Traitez les signalements ou suspendez les fonctions sociales du profil.</p></div></div>
                <div class="admin-table-wrap"><table class="admin-table">
                    <thead><tr><th>Signalé par</th><th>Profil</th><th>Motif</th><th>Date</th><th>Action</th></tr></thead>
                    <tbody id="social-report-rows"><tr><td colspan="5" class="admin-empty">Aucun signalement en attente.</td></tr></tbody>
                </table></div>
            </section>
            <section class="admin-panel">
                <div class="admin-section-heading"><div><h2>Succès et badges</h2><p>Activez les succès automatiques ou attribuez-les manuellement à un joueur.</p></div></div>
                <div class="admin-table-wrap"><table class="admin-table">
                    <thead><tr><th>Succès</th><th>Description</th><th>État</th><th>Attribuer à un joueur</th></tr></thead>
                    <tbody id="social-achievement-rows"><tr><td colspan="4" class="admin-empty">Chargement…</td></tr></tbody>
                </table></div>
            </section>
        </div>

        <div class="admin-tab-panel" id="admin-panel-morpion" role="tabpanel" aria-labelledby="admin-tab-morpion"
             tabindex="0" hidden data-tab-panel="morpion">
            <section class="account-stat-grid admin-summary" aria-label="Statistiques du Morpion">
                <div class="account-stat" data-stat-card="morpion_games">
                    <strong data-stat="morpion_games"><span id="morpion-games"><?= e($morpionGames) ?></span></strong>
                    <span>Parties Morpion</span>
                </div>
                <div class="account-stat" data-stat-card="morpion_completed">
                    <strong data-stat="morpion_completed"><span id="morpion-completed"><?= e($morpionCompleted) ?></span></strong>
                    <span>Parties terminées</span>
                    <span class="admin-stat-note" id="morpion-completed-note">Estimation d’après les modes enregistrés</span>
                </div>
                <div class="account-stat" data-stat-card="morpion_ranked">
                    <strong data-stat="morpion_ranked" id="morpion-ranked">—</strong>
                    <span>Parties classées</span>
                </div>
                <div class="account-stat" data-stat-card="morpion_playing">
                    <strong data-stat="morpion_playing" id="morpion-playing">—</strong>
                    <span>Joueurs dans le Morpion</span>
                </div>
                <div class="account-stat" data-stat-card="morpion_hour">
                    <strong data-stat="morpion_hour" id="morpion-sessions-hour">—</strong>
                    <span>Sessions sur 1 h</span>
                </div>
                <div class="account-stat" data-stat-card="morpion_day">
                    <strong data-stat="morpion_day" id="morpion-sessions-day">—</strong>
                    <span>Sessions sur 24 h</span>
                </div>
                <div class="account-stat" data-stat-card="morpion_points">
                    <strong data-stat="morpion_points" id="morpion-points">—</strong>
                    <span>Points distribués</span>
                </div>
            </section>

            <div class="admin-grid-2">
                <section class="admin-panel">
                    <div class="admin-section-heading">
                        <div>
                            <h2>Joueurs connectés dans le Morpion</h2>
                            <p>Présence déclarée par le jeu (contexte « morpion »), sur les 90 dernières secondes.</p>
                        </div>
                    </div>
                    <div class="admin-table-wrap"><table class="admin-table">
                        <thead><tr><th>Joueur</th><th>Contexte</th><th>Activité</th><th>Rang</th></tr></thead>
                        <tbody id="morpion-live-rows">
                        <?php if (!$livePlayers): ?>
                            <tr><td colspan="4" class="admin-empty">Aucun joueur connecté dans le Morpion pour le moment.</td></tr>
                        <?php else: ?>
                            <?php foreach ($livePlayers as $livePlayer):
                                $liveKey = (string)($livePlayer['last_context'] ?? 'morpion');
                                $liveRank = $rankMeta[(string)($livePlayer['rank'] ?? 'bronze')] ?? $rankMeta['bronze']; ?>
                                <tr data-live-id="<?= e($livePlayer['id']) ?>">
                                    <td><span class="admin-live-name"><span class="admin-live-avatar" aria-hidden="true"><?= e($livePlayer['avatar'] ?: '🚀') ?></span><?= e($livePlayer['display_name']) ?></span></td>
                                    <td><?= e($contextLabels[$liveKey] ?? ($liveKey !== '' ? $liveKey : 'Hub Jeux')) ?></td>
                                    <td>
                                        <span class="admin-cell-main"><?= e($relTime((int)($livePlayer['last_context_at'] ?? 0))) ?></span>
                                        <span class="admin-cell-sub">vu <?= e($relTime((int)$livePlayer['last_seen_at'])) ?></span>
                                    </td>
                                    <td><span class="admin-rank-cell"><?= e($rankIcon($livePlayer)) ?> <?= e($rankLabel($livePlayer)) ?></span>
                                        <span class="admin-cell-sub"><?= e($livePlayer['ranked_points']) ?> pts</span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table></div>
                </section>

                <section class="admin-panel">
                    <div class="admin-section-heading">
                        <div><h2>Modes les plus joués</h2><p>Répartition des parties enregistrées par mode de jeu.</p></div>
                    </div>
                    <div class="admin-table-wrap"><table class="admin-table">
                        <thead><tr><th>Mode</th><th>Parties</th><th>Part</th></tr></thead>
                        <tbody id="popular-mode-rows">
                        <?php if (!$popularModes): ?>
                            <tr><td colspan="3" class="admin-empty">Aucune partie enregistrée.</td></tr>
                        <?php else:
                            $modeTotal = array_sum(array_map(static fn(array $row): int => (int)$row['games'], $popularModes));
                            foreach ($popularModes as $mode):
                                $games = (int)$mode['games'];
                                $share = $modeTotal > 0 ? (int)round(($games / $modeTotal) * 100) : 0; ?>
                                <tr>
                                    <td><?= e($modeLabels[$mode['mode']] ?? $mode['mode']) ?></td>
                                    <td><?= e($games) ?></td>
                                    <td><span class="admin-tag"><?= e($share) ?> %</span></td>
                                </tr>
                            <?php endforeach;
                        endif; ?>
                        </tbody>
                    </table></div>
                </section>
            </div>

            <section class="admin-panel">
                <div class="admin-section-heading">
                    <div><h2>Usage des tailles de grille</h2><p>Répartition des parties Morpion par dimension de plateau.</p></div>
                </div>
                <div class="admin-table-wrap"><table class="admin-table">
                    <thead><tr><th>Grille</th><th>Parties</th><th>Part</th></tr></thead>
                    <tbody id="grid-usage-rows">
                        <tr><td colspan="3" class="admin-empty">Aucune donnée de grille pour le moment.</td></tr>
                    </tbody>
                </table></div>
            </section>

            <section class="admin-panel">
                <div class="admin-section-heading">
                    <div>
                        <h2>Activité du bot</h2>
                        <p>Résultats par difficulté. X désigne le joueur, O le bot.</p>
                    </div>
                </div>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Difficulté</th><th>Parties</th><th>Victoires du bot</th><th>Victoires joueur</th><th>Nuls</th><th>Taux bot</th></tr></thead>
                        <tbody id="bot-level-rows">
                        <?php if (!$botLevels): ?>
                            <tr><td colspan="6" class="admin-empty">Aucune partie contre le bot pour le moment.</td></tr>
                        <?php else: ?>
                            <?php foreach ($botLevels as $row):
                                $games = (int)$row['games'];
                                $rate = $games > 0 ? (int)round(((int)$row['bot_wins'] / $games) * 100) : 0; ?>
                                <tr>
                                    <td><?= e($row["bot_level"] ?: 'non précisé') ?></td>
                                    <td><?= e($games) ?></td>
                                    <td><?= e($row["bot_wins"]) ?></td>
                                    <td><?= e($row["player_wins"]) ?></td>
                                    <td><?= e($row["ties"]) ?></td>
                                    <td><span class="admin-tag"><?= e($rate) ?> %</span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="admin-panel">
                <div class="admin-section-heading">
                    <div><h2>Salons Wi-Fi actifs</h2><p>Les salons inactifs sont automatiquement nettoyés après une heure.</p></div>
                </div>
                <div class="admin-table-wrap"><table class="admin-table">
                    <thead><tr><th>Code</th><th>État</th><th>Joueurs</th><th>Dernière activité</th><th>Action</th></tr></thead>
                    <tbody id="room-rows">
                    <?php if (!$rooms): ?>
                        <tr><td colspan="5" class="admin-empty">Aucun salon actif.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rooms as $room): ?>
                            <tr data-room-id="<?= e($room['id']) ?>" data-row-key="<?= e($room['id']) ?>">
                                <td><strong><?= e($room['id']) ?></strong></td>
                                <td><?= e($room['status']) ?></td>
                                <td><?= e($room['players']) ?>/2</td>
                                <td><?= e($relTime((int)$room['last_seen'])) ?></td>
                                <td><button class="admin-action-button is-danger" type="button" data-operation="delete-room" data-id="<?= e($room['id']) ?>">Supprimer</button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table></div>
            </section>

            <section class="admin-panel">
                <div class="admin-section-heading">
                    <div><h2>Classement Morpion</h2><p>Top 15 des joueurs classés (points classés, puis victoires et XP).</p></div>
                </div>
                <div class="admin-table-wrap"><table class="admin-table">
                    <thead><tr><th>#</th><th>Joueur</th><th>Rang</th><th>Points</th><th>V / D / N</th><th>Victoires</th><th>Niveau</th></tr></thead>
                    <tbody id="morpion-leaderboard-rows">
                        <tr><td colspan="7" class="admin-empty">Classement en cours de chargement…</td></tr>
                    </tbody>
                </table></div>
            </section>
        </div>

        <div class="admin-tab-panel" id="admin-panel-space" role="tabpanel" aria-labelledby="admin-tab-space"
             tabindex="0" hidden data-tab-panel="space">
            <section class="admin-panel">
                <div class="admin-section-heading">
                    <div>
                        <h2>Mémoire de Space</h2>
                        <p>Space ne réentraîne pas le modèle Groq. Il consulte un catalogue de fonctionnalités et utilise des statistiques d’usage agrégées pour mieux orienter ses réponses.</p>
                    </div>
                    <span class="admin-tag space-source-tag"><?= $groqConfigured ? 'Groq configuré' : 'Groq non configuré' ?></span>
                </div>
                <p class="admin-hint">Les statistiques d’usage n’enregistrent ni le texte des conversations ni les identifiants des joueurs ; elles sont conservées 365 jours. L’extraction de connaissances depuis une réponse de Space est désactivée par défaut et ne démarre que si le joueur l’active dans l’interface. Les fiches Groq extraites sont ajoutées automatiquement ici, limitées à 2 par échange et conservées au plus 365 jours (500 fiches cloud maximum). Les thèmes et symboles configurés sont détectés automatiquement ; décrivez les autres nouvelles options ci-dessous.</p>
                <?php if (!$groqConfigured): ?>
                    <p class="admin-hint">Pour activer les réponses Groq, configurez GROQ_API_KEY dans l’environnement du serveur PHP. Sans clé, Space utilise sa mémoire locale et ses règles intégrées.</p>
                <?php endif; ?>
                <form class="space-knowledge-form" id="space-knowledge-form">
                    <label class="admin-field">
                        <span>Fonctionnalité ou sujet</span>
                        <input type="text" name="keyword" maxlength="255" required placeholder="Ex. nouveau mode de jeu">
                    </label>
                    <label class="admin-field">
                        <span>Description pour Space (5 000 caractères maximum)</span>
                        <textarea name="content" maxlength="5000" required placeholder="Expliquez où trouver cette option, son fonctionnement et ses limites."></textarea>
                    </label>
                    <div><button class="admin-action-button is-primary" type="submit">Ajouter au catalogue Space</button></div>
                </form>
            </section>

            <section class="admin-panel">
                <div class="admin-section-heading">
                    <div><h2>Ce que les joueurs demandent</h2><p>Comptages anonymes par sujet, cumulés sur les 365 derniers jours. Aucun message n’est conservé dans ces statistiques.</p></div>
                </div>
                <div class="admin-table-wrap"><table class="admin-table">
                    <thead><tr><th>Fonctionnalité</th><th>Demandes</th></tr></thead>
                    <tbody id="space-usage-rows">
                    <?php
                    $spaceUsageLabels = [
                        'account' => 'Compte et profil', 'bot' => 'Bot du Morpion',
                        'games' => 'Jeux et parties', 'hub' => 'Accueil et navigation',
                        'leaderboard' => 'Classement et progression', 'morpion' => 'Morpion et grilles',
                        'other' => 'Autres sujets', 'space' => 'Space IA',
                        'themes' => 'Thèmes et apparence', 'wifi' => 'Salons Wi-Fi',
                    ];
                    if (!$spaceUsage): ?>
                        <tr><td colspan="2" class="admin-empty">Aucune statistique d’usage pour le moment.</td></tr>
                    <?php else: foreach ($spaceUsage as $usage): ?>
                        <tr>
                            <td><?= e($spaceUsageLabels[$usage['feature_key']] ?? 'Autres sujets') ?></td>
                            <td><?= e($usage['usage_count']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table></div>
            </section>

            <section class="admin-panel">
                <div class="admin-section-heading">
                    <div><h2>Catalogue des fonctionnalités</h2><p>Les thèmes et symboles enregistrés sont découverts automatiquement. Ajoutez ici les options nouvelles ou spécifiques, ainsi que les faits extraits automatiquement des discussions auxquelles les joueurs ont choisi de contribuer.</p></div>
                </div>
                <div class="admin-table-wrap"><table class="admin-table">
                    <thead><tr><th>Fonctionnalité</th><th>Description</th><th>Source</th><th>Mise à jour</th><th>Action</th></tr></thead>
                    <tbody id="space-knowledge-rows">
                    <?php if (!$spaceKnowledge): ?>
                        <tr><td colspan="5" class="admin-empty">Aucune connaissance enregistrée.</td></tr>
                    <?php else: foreach ($spaceKnowledge as $entry): ?>
                        <tr data-knowledge-id="<?= e($entry['id']) ?>">
                            <td><?= e($entry['keyword']) ?></td>
                            <td class="space-knowledge-content"><?= e($entry['content']) ?></td>
                            <td><span class="admin-tag space-source-tag"><?= e(match ($entry['source']) {
                                'admin' => 'Admin',
                                'groq_extracted' => 'Groq · auto',
                                'groq' => 'Groq',
                                'gemini' => 'Gemini',
                                default => 'Autre',
                            }) ?></span></td>
                            <td><?= e($relTime((int)$entry['updated_at'])) ?></td>
                            <td><button class="admin-action-button is-danger" type="button" data-knowledge-delete="<?= e($entry['id']) ?>">Supprimer</button></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table></div>
            </section>
        </div>

        <div class="admin-tab-panel" id="admin-panel-global" role="tabpanel" aria-labelledby="admin-tab-global"
             tabindex="0" data-tab-panel="global">
        <section class="account-stat-grid admin-summary" id="admin-summary" aria-label="Statistiques générales">
            <?php
            $statCards = [
                'players' => 'Comptes joueurs',
                'online' => 'Joueurs connectés',
                'games' => 'Parties lancées',
                'completed' => 'Parties terminées',
                'active_games' => 'En cours (dernière heure)',
                'bot_games' => 'Duels contre le bot',
                'trained_accounts' => 'Profils de bot entraînés',
            ];
            foreach ($statCards as $statKey => $statLabel): ?>
                <div class="account-stat" data-stat-card="<?= e($statKey) ?>">
                    <strong data-stat="<?= e($statKey) ?>"><?= e($summary[$statKey] ?? 0) ?></strong>
                    <span><?= e($statLabel) ?></span>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="admin-panel" id="admin-users-panel">
            <div class="admin-section-heading">
                <div>
                    <h2>Gestion centralisée des utilisateurs</h2>
                    <p>Recherchez, filtrez, triez puis agissez individuellement ou par lot : comptes, points classés, niveau/XP et accès à Space Core.</p>
                </div>
            </div>

            <section class="account-stat-grid" aria-label="Joueurs connectés en temps réel">
                <div class="account-stat" data-stat-card="live_now">
                    <strong data-stat="live_now" id="live-online">—</strong>
                    <span>Joueurs actifs en temps réel</span>
                    <span class="admin-stat-note">Vus depuis moins de 90 s</span>
                </div>
                <div class="account-stat" data-stat-card="live_playing">
                    <strong data-stat="live_playing" id="live-playing">—</strong>
                    <span>En partie de Morpion</span>
                </div>
                <div class="account-stat" data-stat-card="live_hub">
                    <strong data-stat="live_hub" id="live-hub">—</strong>
                    <span>Dans le hub</span>
                </div>
                <div class="account-stat" data-stat-card="live_space">
                    <strong data-stat="live_space" id="live-space">—</strong>
                    <span>Dans Space Core</span>
                </div>
            </section>

            <h3 class="admin-mini-title">Joueurs actifs en temps réel</h3>
            <div class="admin-table-wrap"><table class="admin-table">
                <thead><tr><th>Joueur</th><th>Contexte</th><th>Activité</th><th>Niveau</th></tr></thead>
                <tbody id="live-player-rows">
                    <tr><td colspan="4" class="admin-empty">Présence en cours de chargement…</td></tr>
                </tbody>
            </table></div>

            <label class="admin-field">
                <span>Jeu des statistiques de rang</span>
                <select id="admin-ranking-game">
                    <option value="morpion">Morpion</option>
                    <option value="connect4">Puissance 4</option>
                    <option value="pong">Pong</option>
                    <option value="naval">Bataille Navale</option>
                </select>
            </label>
            <h3 class="admin-mini-title" id="admin-top-player-title">Joueur n°1 — Morpion</h3>
            <div class="admin-top-card" id="top-player-card" data-player-id="<?= $topPlayer ? e($topPlayer['id']) : '' ?>">
                <?php if (!$topPlayer): ?>
                    <p class="admin-empty" id="top-player-empty">Aucun match classé joué pour le moment : le classement est encore ouvert.</p>
                <?php else: ?>
                    <div class="admin-top-avatar" id="top-player-avatar" aria-hidden="true"><?= e($topPlayer['avatar'] ?: '🚀') ?></div>
                    <div>
                        <h3 class="admin-top-name" id="top-player-name"><?= e($topPlayer['display_name']) ?></h3>
                        <p class="admin-top-meta">
                            <span class="admin-rank-cell" id="top-player-rank"><?= e($rankIcon($topPlayer)) ?> <?= e($rankLabel($topPlayer)) ?></span>
                            · Saison <span id="top-player-season">—</span>
                        </p>
                        <span class="admin-progress" role="presentation"><span class="admin-progress-bar" id="top-player-rankbar" style="width: <?= e(max(0, min(100, (int)($topPlayer['rank_progress'] ?? 0)))) ?>%"></span></span>
                        <span class="admin-cell-sub">Progression dans le palier</span>
                    </div>
                    <dl class="admin-top-values">
                        <div class="admin-top-value"><dt>Points classés</dt><dd id="top-player-points"><?= e($topPlayer['ranked_points']) ?></dd></div>
                        <div class="admin-top-value"><dt>Victoires</dt><dd id="top-player-wins"><?= e($topPlayer['ranked_wins']) ?></dd></div>
                        <div class="admin-top-value"><dt>Taux de victoire</dt><dd id="top-player-winrate"><?php
                            $topGames = (int)$topPlayer['ranked_games'];
                            echo e($topGames > 0 ? (int)round(((int)$topPlayer['ranked_wins'] / $topGames) * 100) : 0); ?> %</dd></div>
                        <div class="admin-top-value"><dt>Parties classées</dt><dd id="top-player-games"><?= e($topGames) ?></dd></div>
                        <div class="admin-top-value"><dt>Niveau</dt><dd id="top-player-level"><?= e($topPlayer['level']) ?></dd></div>
                        <div class="admin-top-value"><dt>XP</dt><dd id="top-player-xp"><?= e($topPlayer['xp']) ?></dd></div>
                    </dl>
                <?php endif; ?>
            </div>

            <h3 class="admin-mini-title" id="admin-rank-distribution-title">Répartition des joueurs par palier — Morpion</h3>
            <div class="admin-table-wrap"><table class="admin-table">
                <thead><tr><th>Palier</th><th>Joueurs</th><th>Part</th><th>Répartition</th></tr></thead>
                <tbody id="rank-distribution-rows">
                <?php foreach ($rankDistribution as $rankRow):
                    $rankShare = $rankTotal > 0 ? (int)round(($rankRow['players'] / $rankTotal) * 100) : 0; ?>
                    <tr data-rank-key="<?= e($rankRow['key']) ?>" data-row-key="<?= e($rankRow['key']) ?>">
                        <td><span class="admin-rank-cell"><?= e($rankRow['icon']) ?> <?= e($rankRow['name']) ?></span></td>
                        <td data-rank-players><?= e($rankRow['players']) ?></td>
                        <td><span class="admin-tag" data-rank-share><?= e($rankShare) ?> %</span></td>
                        <td><span class="admin-progress" role="presentation"><span class="admin-progress-bar" data-rank-bar style="width: <?= e($rankShare) ?>%"></span></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>

            <div class="admin-filters" role="group" aria-label="Filtres de la liste des joueurs">
                <label class="admin-field">
                    <span>Jeu pour les actions XP / rang</span>
                    <select id="admin-player-game">
                        <option value="morpion">Morpion</option>
                        <option value="connect4">Puissance 4</option>
                        <option value="pong">Pong</option>
                        <option value="naval">Bataille Navale</option>
                    </select>
                </label>
                <label class="admin-field">
                    <span>Rechercher</span>
                    <input type="search" id="player-search" placeholder="Pseudo, e-mail, id…" autocomplete="off" spellcheck="false">
                </label>
                <label class="admin-field">
                    <span>Statut</span>
                    <select id="player-status">
                        <option value="all">Tous les comptes</option>
                        <option value="online">En ligne</option>
                        <option value="offline">Hors ligne</option>
                        <option value="banned">Bannis</option>
                        <option value="trained">Bot entraîné</option>
                        <option value="ai_blocked">Space Core bloqué</option>
                    </select>
                </label>
                <label class="admin-field">
                    <span>Palier</span>
                    <select id="player-rank">
                        <option value="all">Tous les paliers</option>
                        <option value="bronze">🥉 Bronze</option>
                        <option value="silver">🥈 Argent</option>
                        <option value="gold">🥇 Or</option>
                        <option value="platinum">🛡️ Platine</option>
                        <option value="diamond">💎 Diamant</option>
                        <option value="legend">👑 Légende</option>
                    </select>
                </label>
                <label class="admin-field">
                    <span>Tri</span>
                    <select id="player-sort-preset">
                        <option value="activity">Activité récente</option>
                        <option value="ranked_points">Points classés</option>
                        <option value="ranked_games">Parties classées</option>
                        <option value="xp">XP</option>
                        <option value="level">Niveau</option>
                        <option value="created_at">Inscription</option>
                        <option value="display_name">Pseudo</option>
                    </select>
                </label>
                <label class="admin-field">
                    <span>Lignes par page</span>
                    <select id="player-pagesize">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="0">Toutes</option>
                    </select>
                </label>
                <div class="admin-filters-end">
                    <button class="admin-action-button" id="player-export" type="button">Exporter CSV</button>
                    <button class="admin-action-button" id="player-select-all" type="button">Tout sélectionner</button>
                </div>
            </div>

            <div class="admin-bulkbar" id="player-bulkbar" hidden>
                <strong id="player-bulk-count">0 compte sélectionné</strong>
                <button class="admin-action-button" type="button" data-bulk="ban">Bannir</button>
                <button class="admin-action-button" type="button" data-bulk="unban">Débannir</button>
                <button class="admin-action-button" type="button" data-bulk="ai_block">Bloquer Space Core</button>
                <button class="admin-action-button" type="button" data-bulk="ai_unblock">Débloquer Space Core</button>
                <button class="admin-action-button" type="button" data-bulk="reset_bot">Réinitialiser l’IA</button>
                <button class="admin-action-button is-danger" type="button" data-bulk="delete">Supprimer</button>
                <button class="admin-action-button" type="button" id="player-bulk-clear">Annuler la sélection</button>
            </div>

            <p class="admin-result-count" id="player-count" role="status" aria-live="polite"></p>

            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="admin-col-select"><input type="checkbox" id="player-check-all" aria-label="Sélectionner tous les joueurs affichés"></th>
                            <th><button class="admin-sort" type="button" data-sort="last_seen_at">Statut <span class="admin-sort-ind" aria-hidden="true">↕</span></button></th>
                            <th><button class="admin-sort" type="button" data-sort="display_name">Pseudo <span class="admin-sort-ind" aria-hidden="true">↕</span></button></th>
                            <th>E-mail</th>
                            <th><button class="admin-sort" type="button" data-sort="ranked_points">Points / rang <span class="admin-sort-ind" aria-hidden="true">↕</span></button></th>
                            <th><button class="admin-sort" type="button" data-sort="level">Niveau / XP <span class="admin-sort-ind" aria-hidden="true">↕</span></button></th>
                            <th><button class="admin-sort" type="button" data-sort="games">Parties <span class="admin-sort-ind" aria-hidden="true">↕</span></button></th>
                            <th><button class="admin-sort" type="button" data-sort="learning_level">Niveau appris <span class="admin-sort-ind" aria-hidden="true">↕</span></button></th>
                            <th><button class="admin-sort" type="button" data-sort="games_learned">Parties apprises <span class="admin-sort-ind" aria-hidden="true">↕</span></button></th>
                            <th><button class="admin-sort" type="button" data-sort="created_at">Inscription <span class="admin-sort-ind" aria-hidden="true">↕</span></button></th>
                            <th>Pseudo public</th>
                            <th>Visibilité</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="player-rows">
                    <?php if (!$players): ?>
                        <tr><td colspan="13" class="admin-empty">Aucun joueur inscrit.</td></tr>
                    <?php else: ?>
                        <?php foreach ($players as $player):
                            $isBanned = (bool)$player['is_banned'];
                            $aiBlocked = (bool)($player['ai_blocked'] ?? 0);
                            $isOnline = !$isBanned && (int)$player['last_seen_at'] >= $now - 90;
                            $playerRankedGames = (int)$player['ranked_games']; ?>
                            <tr data-player-id="<?= e($player['id']) ?>" data-row-key="<?= e($player['id']) ?>"<?= $isBanned ? ' class="is-banned"' : '' ?>>
                                <td class="admin-col-select"><input type="checkbox" class="player-select" value="<?= e($player['id']) ?>" aria-label="Sélectionner <?= e($player['display_name']) ?>"></td>
                                <td>
                                    <span class="presence-dot <?= $isOnline ? 'is-online' : '' ?>"></span><?= e($isBanned ? 'Banni' : ($isOnline ? 'En ligne' : 'Hors ligne')) ?>
                                    <span class="admin-cell-sub"><?= e($relTime((int)$player['last_seen_at'])) ?></span>
                                    <?php if ($aiBlocked): ?><span class="admin-cell-sub">Space Core bloqué</span><?php endif; ?>
                                </td>
                                <td>
                                    <span class="admin-live-name"><span class="admin-live-avatar" aria-hidden="true"><?= e($player['avatar'] ?: '🚀') ?></span><?= e($player["display_name"]) ?></span>
                                </td>
                                <td><?= e($player["email"]) ?></td>
                                <td>
                                    <span class="admin-cell-main"><?= e($player['ranked_points']) ?> pts<?= $playerRankedGames > 0 ? ' · ' . e($playerRankedGames) . ' classée(s)' : '' ?></span>
                                    <span class="admin-rank-cell admin-cell-sub"><?= e($rankIcon($player)) ?> <?= e($rankLabel($player)) ?></span>
                                </td>
                                <td>
                                    <span class="admin-cell-main">Niv. <?= e($player['level']) ?></span>
                                    <span class="admin-cell-sub"><?= e($player['xp']) ?> XP</span>
                                </td>
                                <td><?= e($player["games"]) ?></td>
                                <td><?= e($player["learning_level"]) ?></td>
                                <td><?= e($player["games_learned"]) ?></td>
                                <td><?= e(date('d/m/Y', (int) $player['created_at'])) ?></td>
                                <td><?= e('@' . ($player['username'] ?? '')) ?></td>
                                <td><?= e($player['profile_visibility'] ?? 'public') ?></td>
                                <td>
                                    <div class="admin-actions">
                                        <button class="admin-action-button" type="button" data-operation="edit" data-id="<?= e($player['id']) ?>" data-focus-key="player-<?= e($player['id']) ?>-edit">Modifier</button>
                                        <button class="admin-action-button" type="button" data-operation="set_points" data-id="<?= e($player['id']) ?>" data-focus-key="player-<?= e($player['id']) ?>-set_points">Points</button>
                                        <button class="admin-action-button" type="button" data-operation="reset_ranked" data-id="<?= e($player['id']) ?>" data-focus-key="player-<?= e($player['id']) ?>-reset_ranked">RAZ points</button>
                                        <button class="admin-action-button" type="button" data-operation="set_xp" data-id="<?= e($player['id']) ?>" data-focus-key="player-<?= e($player['id']) ?>-set_xp">XP</button>
                                        <button class="admin-action-button<?= $aiBlocked ? '' : ' is-danger' ?>" type="button" data-operation="<?= $aiBlocked ? 'ai_unblock' : 'ai_block' ?>" data-id="<?= e($player['id']) ?>" data-focus-key="player-<?= e($player['id']) ?>-ai"><?= $aiBlocked ? 'Débloquer SC' : 'Bloquer SC' ?></button>
                                        <button class="admin-action-button" type="button" data-operation="reset_bot" data-id="<?= e($player['id']) ?>" data-focus-key="player-<?= e($player['id']) ?>-reset_bot">Réinit. IA</button>
                                        <button class="admin-action-button<?= $isBanned ? '' : ' is-danger' ?>" type="button" data-operation="<?= $isBanned ? 'unban' : 'ban' ?>" data-id="<?= e($player['id']) ?>" data-focus-key="player-<?= e($player['id']) ?>-ban"><?= $isBanned ? 'Débannir' : 'Bannir' ?></button>
                                        <button class="admin-action-button is-danger" type="button" data-operation="delete" data-id="<?= e($player['id']) ?>" data-focus-key="player-<?= e($player['id']) ?>-delete">Supprimer</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="admin-pager" id="player-pager" hidden>
                <button type="button" data-page="prev">‹ Précédent</button>
                <span id="player-pager-info"></span>
                <button type="button" data-page="next">Suivant ›</button>
            </div>
            <noscript><p class="admin-hint">JavaScript est désactivé : le tableau affiche l’état au chargement de la page et les actions nécessitent JS.</p></noscript>
        </section>

        <div class="admin-grid-2">
            <section class="admin-panel">
                <div class="admin-section-heading">
                    <div><h2>Thèmes globaux</h2><p>Les thèmes personnalisés sont partagés par tous les joueurs.</p></div>
                </div>
                <form id="admin-theme-form" class="admin-management-form" novalidate>
                    <label>Nom<input name="name" required maxlength="30" autocomplete="off"></label>
                    <label>Identifiant<input name="key" required pattern="[a-z][a-z0-9-]{1,24}" placeholder="mon-theme" autocomplete="off"></label>
                    <label>Couleur principale<input name="primary_color" type="color" value="#dc2626"></label>
                    <label>Arrière-plan<input name="background_color" type="color" value="#09090b"></label>
                    <button class="btn-primary-action" type="submit">Ajouter le thème</button>
                </form>
                <div class="admin-table-wrap"><table class="admin-table">
                    <thead><tr><th>Nom</th><th>Identifiant</th><th>Couleurs</th><th>Type</th><th>Action</th></tr></thead>
                    <tbody id="theme-rows">
                    <?php if (!$themes): ?>
                        <tr><td colspan="5" class="admin-empty">Aucun thème disponible.</td></tr>
                    <?php else: ?>
                        <?php foreach ($themes as $theme):
                            $primarySafe = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$theme['primary_color']) ? $theme['primary_color'] : '#dc2626';
                            $bgSafe = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$theme['background_color']) ? $theme['background_color'] : '#09090b'; ?>
                            <tr data-theme-id="<?= e($theme['id']) ?>" data-row-key="<?= e($theme['id']) ?>">
                                <td><?= e($theme['name']) ?></td>
                                <td><?= e($theme['key']) ?></td>
                                <td>
                                    <span class="admin-swatch" style="background: <?= e($primarySafe) ?>"></span><?= e($primarySafe) ?>
                                    <span class="admin-swatch" style="background: <?= e($bgSafe) ?>"></span><?= e($bgSafe) ?>
                                </td>
                                <td><?= e($theme['custom'] ? 'Personnalisé' : 'Intégré') ?></td>
                                <td>
                                    <?php if ($theme['custom']): ?>
                                        <button class="admin-action-button is-danger" type="button" data-operation="delete-theme" data-id="<?= e($theme['id']) ?>">Supprimer</button>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table></div>
            </section>

            <section class="admin-panel">
                <div class="admin-section-heading">
                    <div><h2>Symboles personnalisés</h2><p>Ajoutez des emojis ou caractères disponibles dans les options de partie.</p></div>
                </div>
                <form id="admin-symbol-form" class="admin-management-form" novalidate>
                    <label>Symbole<input name="symbol" required maxlength="8" placeholder="⭐" autocomplete="off"></label>
                    <label>Nom<input name="label" required maxlength="30" placeholder="Étoile" autocomplete="off"></label>
                    <button class="btn-primary-action" type="submit">Ajouter le symbole</button>
                </form>
                <div class="admin-table-wrap"><table class="admin-table">
                    <thead><tr><th>Symbole</th><th>Nom</th><th>Type</th><th>Action</th></tr></thead>
                    <tbody id="symbol-rows">
                    <?php if (!$symbols): ?>
                        <tr><td colspan="4" class="admin-empty">Aucun symbole disponible.</td></tr>
                    <?php else: ?>
                        <?php foreach ($symbols as $symbol): ?>
                            <tr data-symbol-id="<?= e($symbol['id']) ?>" data-row-key="<?= e($symbol['id']) ?>">
                                <td><span class="admin-symbol"><?= e($symbol['symbol']) ?></span></td>
                                <td><?= e($symbol['label']) ?></td>
                                <td><?= e($symbol['custom'] ? 'Personnalisé' : 'Intégré') ?></td>
                                <td>
                                    <?php if ($symbol['custom']): ?>
                                        <button class="admin-action-button is-danger" type="button" data-operation="delete-symbol" data-id="<?= e($symbol['id']) ?>">Supprimer</button>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table></div>
            </section>
        </div>

        <section class="admin-panel admin-danger-zone">
            <div class="admin-section-heading">
                <div>
                    <h2>Contrôle global de l’IA (Space Core)</h2>
                    <p>Coupe ou rétablit l’accès à Space Core pour l’ensemble des joueurs.</p>
                </div>
            </div>
            <form id="admin-ai-form" class="admin-inline-form" novalidate>
                <label class="admin-switch">
                    <input type="checkbox" id="admin-ai-toggle" name="ai_enabled" value="1">
                    <span>Space Core activé</span>
                </label>
                <button class="btn-primary-action" type="submit" id="admin-ai-submit">Appliquer</button>
            </form>
            <p class="admin-status-line" id="admin-ai-status" data-state="unknown">État en cours de chargement…</p>
        </section>

        <section class="admin-panel admin-danger-zone">
            <div class="admin-section-heading">
                <div>
                    <h2>Commandes d’urgence (« Cataclysmes »)</h2>
                    <p>Actions irréversibles ou visibles par tous : à utiliser en connaissance de cause.</p>
                </div>
            </div>

            <h3 class="admin-mini-title">Saison classée</h3>
            <p class="admin-hint" id="admin-season-line">Saison en cours : <strong id="admin-season-value">—</strong> · démarrée <span id="admin-season-start">—</span></p>
            <form id="admin-season-form" class="admin-inline-form" novalidate>
                <label class="admin-field">
                    <span>Jeu</span>
                    <select id="admin-season-game">
                        <option value="morpion">Morpion</option>
                        <option value="connect4">Puissance 4</option>
                        <option value="pong">Pong</option>
                        <option value="naval">Bataille Navale</option>
                    </select>
                </label>
                <label class="admin-field">
                    <span>Mot de confirmation</span>
                    <input type="text" id="admin-season-confirm" placeholder="SAISON" autocomplete="off" spellcheck="false">
                </label>
                <button class="admin-action-button is-danger" type="submit" id="admin-season-submit">Réinitialiser la saison</button>
            </form>

            <h3 class="admin-mini-title">Mode maintenance</h3>
            <form id="admin-maintenance-form" class="admin-inline-form" novalidate>
                <label class="admin-switch">
                    <input type="checkbox" id="admin-maintenance-toggle" name="maintenance" value="1">
                    <span>Site en maintenance</span>
                </label>
                <button class="btn-primary-action" type="submit" id="admin-maintenance-submit">Appliquer</button>
                <button class="admin-action-button" type="button" id="admin-maintenance-message">Message de maintenance</button>
            </form>
            <p class="admin-status-line" id="admin-maintenance-status" data-state="unknown">État en cours de chargement…</p>
            <p class="admin-hint">Message actuel : <em id="admin-maintenance-message-value">—</em></p>

            <h3 class="admin-mini-title">Journal des événements</h3>
            <form id="admin-purge-form" class="admin-inline-form" novalidate>
                <label class="admin-field">
                    <span>Mot de confirmation</span>
                    <input type="text" id="admin-purge-confirm" placeholder="PURGER" autocomplete="off" spellcheck="false">
                </label>
                <button class="admin-action-button is-danger" type="submit" id="admin-purge-submit">Purger le journal</button>
            </form>
            <div id="admin-emergency-status" class="admin-hint" role="status" aria-live="polite"></div>
        </section>

        <section class="admin-panel">
            <div class="admin-section-heading">
                <div><h2>Journal des événements</h2><p>Derniers événements enregistrés par la plateforme (comptes, parties classées, administration).</p></div>
            </div>
            <div class="admin-filters" role="group" aria-label="Filtres du journal">
                <label class="admin-field">
                    <span>Catégorie</span>
                    <select id="event-category">
                        <option value="all">Toutes les catégories</option>
                    </select>
                </label>
                <label class="admin-field">
                    <span>Entrées</span>
                    <select id="event-limit">
                        <option value="25">25</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                        <option value="150">150</option>
                    </select>
                </label>
                <div class="admin-filters-end">
                    <button class="admin-action-button" type="button" id="event-refresh">Rafraîchir le journal</button>
                </div>
            </div>
            <p class="admin-result-count" id="event-count" role="status" aria-live="polite"></p>
            <div class="admin-table-wrap"><table class="admin-table">
                <thead><tr><th>Horodatage</th><th>Catégorie</th><th>Gravité</th><th>Auteur</th><th>Message</th></tr></thead>
                <tbody id="event-rows">
                    <tr><td colspan="5" class="admin-empty">Journal en cours de chargement…</td></tr>
                </tbody>
            </table></div>
        </section>
        </div>
    </main>
    <footer class="footer">© 2026 Space &amp; Multi-Games · Tableau de bord administrateur</footer>

    <div class="admin-toasts" id="admin-toasts" role="region" aria-live="polite" aria-label="Notifications"></div>

    <dialog class="admin-dialog" id="admin-dialog" aria-labelledby="admin-dialog-title">
        <form class="admin-dialog-form" id="admin-dialog-form" method="dialog">
            <h2 id="admin-dialog-title">Confirmation</h2>
            <p id="admin-dialog-message"></p>
            <div class="admin-dialog-fields" id="admin-dialog-fields"></div>
            <p class="admin-dialog-error" id="admin-dialog-error" role="alert" hidden></p>
            <div class="admin-dialog-actions">
                <button class="admin-action-button" type="button" id="admin-dialog-cancel">Annuler</button>
                <button class="admin-action-button is-primary" type="submit" id="admin-dialog-submit">Confirmer</button>
            </div>
        </form>
    </dialog>

    <script type="application/json" id="admin-bootstrap"><?= js_json($bootstrap) ?></script>
    <script>
        window.MG_ADMIN_API = 'games/morpion/api.php';
        window.MG_CSRF = <?= js_json($csrfToken) ?>;
    </script>
    <script src="assets/js/admin.js" defer></script>
    <script src="assets/js/account-presence.js" data-api="games/morpion/api.php" defer></script>
</body>
</html>
