<?php
declare(strict_types=1);

/**
 * Multi-Games — référentiel du mode Classé (Ranked) et de la progression.
 *
 * Toutes les formules qui font autorité vivent ici : le serveur calcule, le
 * client ne fait qu'afficher. Chaque valeur envoyée par l'API (points, XP,
 * niveau, rang, progression) provient donc de ces fonctions, ce qui évite
 * toute divergence entre PHP et JavaScript.
 */

/** Niveau maximum atteignable. */
const MG_MAX_LEVEL = 100;

/** XP gagnés par une partie amicale terminée (hors classé). */
const MG_XP_FRIENDLY = 5;

/** XP de base pour une partie classée terminée. */
const MG_XP_RANKED_BASE = 10;

/**
 * XP nécessaires pour passer du niveau $level au niveau suivant.
 * Courbe linéaire douce : 24, 28, 32… soit ~21 800 XP pour atteindre le niveau 100.
 */
function mg_xp_for_level(int $level): int
{
    if ($level >= MG_MAX_LEVEL) {
        return 0;
    }
    return 20 + ($level * 4);
}

/**
 * Convertit un total d'XP en niveau et progression dans le niveau.
 *
 * @return array{level:int,xp:int,into:int,next:int,progress:int,max:int}
 */
function mg_level_from_xp(int $xp): array
{
    $xp = max(0, $xp);
    $level = 1;
    $remaining = $xp;
    while ($level < MG_MAX_LEVEL) {
        $need = mg_xp_for_level($level);
        if ($remaining < $need) {
            break;
        }
        $remaining -= $need;
        $level++;
    }
    $need = mg_xp_for_level($level);

    return [
        'level' => $level,
        'xp' => $xp,
        'into' => $remaining,
        'next' => $need,
        'progress' => $need > 0 ? (int)round(($remaining / $need) * 100) : 100,
        'max' => MG_MAX_LEVEL,
    ];
}

/**
 * Paliers de rang, du plus bas au plus haut. `max` null = palier ouvert.
 */
function mg_rank_tiers(): array
{
    return [
        ['key' => 'bronze', 'name' => 'Bronze', 'icon' => '🥉', 'min' => 0, 'max' => 499],
        ['key' => 'silver', 'name' => 'Argent', 'icon' => '🥈', 'min' => 500, 'max' => 999],
        ['key' => 'gold', 'name' => 'Or', 'icon' => '🥇', 'min' => 1000, 'max' => 1999],
        ['key' => 'platinum', 'name' => 'Platine', 'icon' => '🛡️', 'min' => 2000, 'max' => 3499],
        ['key' => 'diamond', 'name' => 'Diamant', 'icon' => '💎', 'min' => 3500, 'max' => 4999],
        ['key' => 'legend', 'name' => 'Légende', 'icon' => '👑', 'min' => 5000, 'max' => null],
    ];
}

/** Libellés de division, du bas vers le haut. */
function mg_rank_divisions(): array
{
    return ['III', 'II', 'I'];
}

/**
 * Rang correspondant à un nombre de points, avec division et progression.
 *
 * @return array{key:string,name:string,icon:string,division:?int,division_label:?string,label:string,min:int,max:?int,nextAt:?int,progress:int,points:int}
 */
function mg_rank_for_points(int $points): array
{
    $points = max(0, $points);
    $tiers = mg_rank_tiers();
    $divisions = mg_rank_divisions();

    $current = $tiers[0];
    $next = null;
    foreach ($tiers as $index => $tier) {
        if ($points >= $tier['min']) {
            $current = $tier;
            $next = $tiers[$index + 1] ?? null;
        }
    }

    $span = $current['max'] !== null ? ($current['max'] - $current['min'] + 1) : 0;
    $into = $points - $current['min'];
    $division = null;
    if ($span > 0) {
        $division = min(count($divisions), intdiv($into * count($divisions), $span) + 1);
    }

    return [
        'key' => $current['key'],
        'name' => $current['name'],
        'icon' => $current['icon'],
        'division' => $division,
        'division_label' => $division !== null ? $divisions[$division - 1] : null,
        'label' => $current['name'] . ($division !== null ? ' ' . $divisions[$division - 1] : ''),
        'min' => $current['min'],
        'max' => $current['max'],
        'nextAt' => $next['min'] ?? null,
        'progress' => $span > 0 ? (int)round(($into / $span) * 100) : 100,
        'points' => $points,
    ];
}

/**
 * Barème officiel d'un match classé.
 *
 * @param string      $mode     'bot' | 'local' | 'wifi'
 * @param string|null $botLevel novice|medium|advanced|legend|adaptive
 * @param string      $outcome  'win' | 'loss' | 'tie' (point de vue du joueur suivi)
 *
 * @return array{points:int,xp:int,label:string}
 */
function mg_ranked_bareme(string $mode, ?string $botLevel, string $outcome): array
{
    if ($mode === 'bot') {
        $table = [
            'novice' => ['win' => 5, 'loss' => -10, 'tie' => 1, 'label' => 'Novice 🐣'],
            'medium' => ['win' => 15, 'loss' => -5, 'tie' => 2, 'label' => 'Intermédiaire 🤖'],
            'advanced' => ['win' => 25, 'loss' => -5, 'tie' => 3, 'label' => 'Avancé 🧠'],
            'legend' => ['win' => 40, 'loss' => -2, 'tie' => 5, 'label' => 'Légende 👑'],
            'adaptive' => ['win' => 30, 'loss' => -5, 'tie' => 3, 'label' => 'Adaptatif 🧬'],
        ];
        $row = $table[$botLevel ?: 'medium'] ?? $table['medium'];
        $points = (int)($row[$outcome] ?? 0);
        $xp = MG_XP_RANKED_BASE + match ($outcome) {
            'win' => (int)round(($row['win'] ?? 0) * 0.6),
            'tie' => (int)round(max(1, $row['tie'] ?? 1) * 2),
            default => 2,
        };
        return ['points' => $points, 'xp' => max(1, $xp), 'label' => $row['label']];
    }

    // PvP : joueur contre joueur (même appareil ou salon Wi-Fi).
    $table = ['win' => 25, 'loss' => -15, 'tie' => 5];
    $points = (int)($table[$outcome] ?? 0);
    $xp = MG_XP_RANKED_BASE + match ($outcome) {
        'win' => 25,
        'tie' => 8,
        default => 2,
    };

    return [
        'points' => $points,
        'xp' => max(1, $xp),
        'label' => $mode === 'wifi' ? 'Duel Wi-Fi classé' : 'Duel local classé',
    ];
}

/**
 * Issue du match du point de vue du joueur suivi.
 */
function mg_match_outcome(string $mode, string $result, string $userSymbol): string
{
    if ($result === 'TIE') {
        return 'tie';
    }
    return $result === $userSymbol ? 'win' : 'loss';
}
