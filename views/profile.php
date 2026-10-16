<?php
$profile = $profile ?? null;
$friends = $friends ?? [];
$friendRequests = $friendRequests ?? [];
$friendOutgoingRequests = $friendOutgoingRequests ?? [];
$friendshipState = $friendshipState ?? 'none';
$error = $error ?? '';
$saved = $saved ?? false;
$headerSubtitle = 'Profil joueur';
?>
<!DOCTYPE html>
<html lang="fr" data-theme="<?= e($currentTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $profile ? e($profile['display_name']) . ' — Profil Multi-Games' : 'Profil introuvable — Multi-Games' ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/themes/themes.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/base.css')) ?>">
    <style><?= $customThemeCss ?>
        .profile-layout { max-width: 1440px; margin: 2rem auto; padding: 0 1rem; }
        .profile-card { background: var(--card-bg, rgba(18,20,26,.9)); border: 1px solid var(--card-border); border-radius: 1rem; padding: 1.4rem; margin-bottom: 1rem; }
        .profile-hero { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .profile-avatar { font-size: 3rem; width: 5rem; height: 5rem; display: grid; place-items: center; border-radius: 1rem; background: rgba(255,255,255,.07); }
        .profile-name { margin: 0; display: flex; align-items: center; gap: .55rem; flex-wrap: wrap; }
        .profile-muted { color: var(--text-muted); }
        .profile-badge { display: inline-flex; border-radius: 999px; border: 1px solid var(--card-border); padding: .15rem .55rem; font-size: .75rem; }
        .profile-owner { color: #facc15; border-color: rgba(250,204,21,.4); background: rgba(250,204,21,.12); }
        .profile-status { margin-left: auto; }
        .profile-columns { display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); gap: 1rem; align-items: start; }
        .profile-column { min-width: 0; }
        .profile-stats-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(145px,1fr)); gap: .8rem; margin-top: 1rem; }
        .profile-stat { background: rgba(255,255,255,.04); border: 1px solid var(--card-border); border-radius: .8rem; padding: .85rem; }
        .profile-stat strong { display: block; font-size: 1.2rem; margin-top: .2rem; }
        .profile-card h2 { margin: 0 0 1rem; }
        .profile-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 1rem 1.25rem; }
        .profile-form-grid .span-2 { grid-column: 1 / -1; }
        .profile-form .input-group { margin-bottom: 0; }
        .profile-form textarea.input-field { min-height: 96px; resize: vertical; }
        .profile-form .avatar-grid { margin: .35rem 0 0; grid-template-columns: repeat(auto-fill,minmax(56px,1fr)); }
        .profile-check { grid-column: 1 / -1; display: flex; align-items: center; gap: .7rem; cursor: pointer; }
        .profile-check input { width: 18px; height: 18px; accent-color: var(--primary); flex: none; }
        .input-hint { color: #94a3b8; font-size: .78rem; }
        .profile-search .input-group { margin-bottom: .5rem; }
        .profile-search-row { display: flex; gap: .5rem; align-items: stretch; }
        .profile-search-row .input-field { flex: 1; min-width: 0; }
        .profile-search-row button { width: auto; }
        .profile-friend-row { display: flex; align-items: center; gap: .7rem; padding: .65rem 0; border-bottom: 1px solid var(--card-border); }
        .profile-friend-row:last-child { border-bottom: 0; }
        .profile-friend-info { display: flex; align-items: center; gap: .6rem; min-width: 0; flex: 1; color: inherit; text-decoration: none; }
        .profile-friend-avatar { font-size: 1.5rem; flex: none; }
        .profile-friend-meta { min-width: 0; display: grid; gap: .15rem; }
        .profile-friend-meta small { color: var(--text-muted); }
        .profile-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .8rem; }
        .profile-result { margin-top: .75rem; }
        .profile-edit-panel[hidden] { display: none; }
        .profile-edit-panel { scroll-margin-top: 1rem; }
        .profile-form-actions { grid-column: 1 / -1; display: grid; gap: .6rem; }
        .profile-form-actions button { width: 100%; }
        .profile-form-message { min-height: 1.2em; margin: 0; font-size: .88rem; }
        .profile-form-message.success { color: #34d399; }
        .profile-form-message.error { color: #f87171; }
        .history-table { min-width: 520px; }
        .history-outcome-win { color: #34d399 !important; font-weight: 700; }
        .history-outcome-loss { color: #f87171 !important; font-weight: 700; }
        .history-outcome-tie { color: #94a3b8 !important; font-weight: 700; }
        .profile-online { color: #34d399; } .profile-offline { color: var(--text-muted); }
        .profile-achievement { display: inline-flex; margin: .25rem; padding: .5rem .7rem; border-radius: .7rem; background: rgba(250,204,21,.1); border: 1px solid rgba(250,204,21,.25); }
        @media (max-width: 800px) { .profile-columns { grid-template-columns: 1fr; } }
        @media (max-width: 700px) {
            .profile-form-grid { grid-template-columns: 1fr; }
            .profile-form-grid .span-2, .profile-check, .profile-form-actions { grid-column: auto; }
            .profile-friend-row { flex-wrap: wrap; }
            .profile-friend-info { flex-basis: 100%; }
        }
    </style>
    <script src="<?= e(app_url('/assets/js/theme.js')) ?>"></script>
    <script src="<?= e(app_url('/assets/js/audio.js')) ?>" defer></script>
</head>
<body>
    <?php require __DIR__ . '/partials/header.php'; ?>
    <main class="profile-layout">
        <?php if ($error !== ''): ?><div class="account-alert" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if ($saved): ?><div class="account-success" role="status">Profil mis à jour.</div><?php endif; ?>
        <?php if (!$profile): ?>
            <section class="profile-card"><h1>Profil introuvable</h1><p><?= e($error ?: 'Ce profil n’existe pas ou n’est pas accessible.') ?></p></section>
        <?php else: ?>
            <div class="profile-columns">
                <div class="profile-column">
            <section class="profile-card">
                <div class="profile-hero">
                    <div class="profile-avatar"><?= e($profile['avatar'] ?: '🚀') ?></div>
                    <div>
                        <h1 class="profile-name"><?= e($profile['display_name']) ?>
                            <?php if (!empty($profile['is_owner'])): ?><span class="profile-badge profile-owner">👑 Owner</span>
                            <?php elseif (!empty($profile['is_admin'])): ?><span class="profile-badge">ADMIN</span><?php endif; ?>
                        </h1>
                        <div class="profile-muted">@<?= e($profile['username']) ?></div>
                        <?php if (isset($profile['bio'])): ?><p><?= nl2br(e($profile['bio'])) ?></p><?php endif; ?>
                        <?php if (!empty($profile['favorite_game'])): ?><div class="profile-muted">Jeu favori : <?= e(match ($profile['favorite_game']) { 'morpion' => 'Morpion', 'connect4' => 'Puissance 4', 'pong' => 'Pong', 'naval' => 'Bataille Navale', default => $profile['favorite_game'] }) ?></div><?php endif; ?>
                    </div>
                    <?php if (array_key_exists('online', $profile)): ?>
                        <div class="profile-status <?= $profile['online'] ? 'profile-online' : 'profile-offline' ?>">
                            <?= $profile['online'] ? '● En ligne' : '○ Hors ligne' ?>
                            <?php if ($profile['online'] && $profile['last_context'] !== ''): ?> · <?= e($profile['last_context']) ?><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($profile['is_owner_profile']): ?>
                        <button class="btn-secondary-action profile-edit-toggle" type="button" aria-expanded="<?= ($saved || $error !== '') ? 'true' : 'false' ?>" aria-controls="profile-edit-panel">Modifier le profil</button>
                    <?php endif; ?>
                </div>
                <?php if (isset($profile['global_level'])): ?>
                    <div class="profile-card profile-global-level" style="margin: 1rem 0 0; padding: 1rem;">
                        <strong>Niveau global <?= e($profile['global_level']['level']) ?></strong>
                        <span class="profile-muted"> · <?= e($profile['global_level']['xp']) ?> XP tous jeux confondus</span>
                        <div style="height:8px;border-radius:99px;background:rgba(255,255,255,.1);overflow:hidden;margin-top:.6rem">
                            <div style="height:100%;width:<?= e($profile['global_level']['progress']) ?>%;background:var(--primary)"></div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!$profile['is_owner_profile'] && $user): ?>
                    <div class="profile-actions">
                        <?php if ($friendshipState === 'none'): ?>
                            <button class="btn-primary-action" type="button" data-friend-action="friend_request" data-target-id="<?= e($profile['id']) ?>">Ajouter en ami</button>
                        <?php elseif ($friendshipState === 'pending_outgoing'): ?>
                            <span class="profile-badge">Demande envoyée</span>
                        <?php elseif ($friendshipState === 'pending_incoming'): ?>
                            <button class="btn-primary-action" type="button" data-friend-action="friend_accept" data-target-id="<?= e($profile['id']) ?>">Accepter</button>
                            <button class="btn-secondary-action" type="button" data-friend-action="friend_decline" data-target-id="<?= e($profile['id']) ?>">Refuser</button>
                        <?php elseif ($friendshipState === 'friends'): ?>
                            <span class="profile-badge">✓ Amis</span>
                        <?php endif; ?>
                        <?php if (!in_array($friendshipState, ['self', 'blocked', 'blocked_by_you'], true)): ?>
                            <button class="btn-secondary-action" type="button" data-friend-action="friend_block" data-target-id="<?= e($profile['id']) ?>">Bloquer</button>
                        <?php endif; ?>
                        <button class="btn-secondary-action" type="button" id="report-profile" data-target-id="<?= e($profile['id']) ?>">Signaler</button>
                    </div>
                <?php elseif (!$user): ?>
                    <p><a href="<?= e(app_url('/account.php')) ?>">Connectez-vous</a> pour ajouter ce joueur en ami.</p>
                <?php endif; ?>
            </section>

            <?php if (isset($profile['stats'])): ?>
                <section>
                    <h2>Progression par jeu</h2>
                    <?php foreach ($profile['stats'] as $gameStats): ?>
                        <article class="profile-card">
                            <h3><?= e(match ($gameStats['game_id']) { 'morpion' => '✕ Morpion', 'connect4' => '🔴 Puissance 4', 'pong' => '🏓 Pong', 'naval' => '🚢 Bataille Navale', default => $gameStats['game_id'] }) ?></h3>
                            <div class="profile-stats-grid">
                                <div class="profile-stat">Niveau<strong><?= e($gameStats['level']) ?></strong></div>
                                <div class="profile-stat">Rang<strong><?= e($gameStats['rank']['icon'] . ' ' . $gameStats['rank']['label']) ?></strong></div>
                                <div class="profile-stat">Points classés<strong><?= e($gameStats['ranked_points']) ?></strong></div>
                                <div class="profile-stat">Pic de points<strong><?= e($gameStats['peak_ranked_points']) ?></strong></div>
                                <div class="profile-stat">Victoires / Défaites<strong><?= e($gameStats['wins']) ?> / <?= e($gameStats['losses']) ?></strong></div>
                                <div class="profile-stat">Ratio<strong><?= e($gameStats['ratio']) ?></strong></div>
                                <div class="profile-stat">Meilleure série<strong><?= e($gameStats['best_streak']) ?></strong></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <?php foreach (['connect4' => 'Puissance 4', 'pong' => 'Pong', 'naval' => 'Bataille Navale'] as $gameId => $gameName): ?>
                        <?php if (!array_filter($profile['stats'], static fn(array $stats): bool => $stats['game_id'] === $gameId)): ?>
                            <article class="profile-card"><h3><?= e($gameName) ?></h3><span class="profile-muted">Bientôt disponible.</span></article>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </section>
                <section class="profile-card">
                    <h2>Succès</h2>
                    <?php if ($profile['achievements']): ?>
                        <?php foreach ($profile['achievements'] as $achievement): ?>
                            <span class="profile-achievement" title="<?= e(date('d/m/Y', $achievement['unlocked_at'])) ?>">🏅 <?= e($achievement['name']) ?></span>
                        <?php endforeach; ?>
                    <?php else: ?><p class="profile-muted">Aucun succès débloqué pour le moment.</p><?php endif; ?>
                </section>
                    </div>
                    <div class="profile-column">
                <section class="profile-card">
                    <h2>Dernières parties</h2>
                    <?php if ($profile['history']): ?>
                        <div class="history-table-wrapper"><table class="history-table"><thead><tr><th>Jeu</th><th>Mode</th><th>Résultat</th><th>Date</th></tr></thead><tbody>
                            <?php foreach ($profile['history'] as $game): ?>
                                <?php $outcomeClass = match ($game['outcome']) { 'win' => 'history-outcome-win', 'loss' => 'history-outcome-loss', 'tie' => 'history-outcome-tie', default => '' }; ?>
                                <tr>
                                    <td><?= e(match ($game['game_id']) { 'morpion' => 'Morpion', 'connect4' => 'Puissance 4', 'pong' => 'Pong', 'naval' => 'Bataille Navale', default => $game['game_id'] }) ?></td>
                                    <td><?= e($game['modeName']) ?></td>
                                    <td class="<?= e($outcomeClass) ?>"><?= e($game['outcome'] === 'tie' ? 'Égalité' : $game['outcomeLabel']) ?></td>
                                    <td><?= e($game['formatted_date']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody></table></div>
                    <?php else: ?><p class="profile-muted">Aucune partie enregistrée.</p><?php endif; ?>
                </section>
                <?php if ($profile['is_owner_profile']): ?>
                    <section class="profile-card">
                        <h2>Amis</h2>
                        <form id="friend-search-form" class="profile-search">
                            <div class="input-group">
                                <label class="input-label" for="friend-search">Trouver un joueur</label>
                                <div class="profile-search-row">
                                    <input class="input-field" id="friend-search" type="search" minlength="2" maxlength="30" placeholder="Pseudo ou nom unique…">
                                    <button class="btn-primary-action" type="submit">Rechercher</button>
                                </div>
                            </div>
                        </form>
                        <div id="friend-search-results" class="profile-result" aria-live="polite"></div>
                        <h3>Demandes reçues</h3>
                        <?php if ($friendRequests): foreach ($friendRequests as $request): ?>
                            <div class="profile-friend-row">
                                <a class="profile-friend-info" href="<?= e(app_url('/profile.php?u=' . rawurlencode($request['username']))) ?>">
                                    <span class="profile-friend-avatar"><?= e($request['avatar'] ?: '🚀') ?></span>
                                    <span class="profile-friend-meta"><?= e($request['display_name']) ?><small>@<?= e($request['username']) ?></small></span>
                                </a>
                                <button class="btn-primary-action" type="button" data-friend-action="friend_accept" data-target-id="<?= e($request['id']) ?>">Accepter</button>
                                <button class="btn-secondary-action" type="button" data-friend-action="friend_decline" data-target-id="<?= e($request['id']) ?>">Refuser</button>
                            </div>
                        <?php endforeach; else: ?><p class="profile-muted">Aucune demande reçue.</p><?php endif; ?>
                        <h3>Demandes envoyées</h3>
                        <?php if ($friendOutgoingRequests): foreach ($friendOutgoingRequests as $request): ?>
                            <div class="profile-friend-row">
                                <a class="profile-friend-info" href="<?= e(app_url('/profile.php?u=' . rawurlencode($request['username']))) ?>">
                                    <span class="profile-friend-avatar"><?= e($request['avatar'] ?: '🚀') ?></span>
                                    <span class="profile-friend-meta"><?= e($request['display_name']) ?><small>@<?= e($request['username']) ?></small></span>
                                </a>
                                <span class="profile-muted">En attente</span>
                                <button class="btn-secondary-action" type="button" data-friend-action="friend_remove" data-target-id="<?= e($request['id']) ?>">Annuler</button>
                            </div>
                        <?php endforeach; else: ?><p class="profile-muted">Aucune demande envoyée.</p><?php endif; ?>
                        <h3>Liste d’amis</h3>
                        <?php if ($friends): foreach ($friends as $friend): ?>
                            <div class="profile-friend-row">
                                <a class="profile-friend-info" href="<?= e(app_url('/profile.php?u=' . rawurlencode($friend['username']))) ?>">
                                    <span class="profile-friend-avatar"><?= e($friend['avatar'] ?: '🚀') ?></span>
                                    <span class="profile-friend-meta"><?= e($friend['display_name']) ?><small class="<?= $friend['online'] ? 'profile-online' : 'profile-offline' ?>"><?= $friend['online'] ? '● En ligne' : '○ Hors ligne' ?></small></span>
                                </a>
                                <?php if ($friend['last_context'] !== ''): ?><span class="profile-muted"><?= e($friend['last_context']) ?></span><?php endif; ?>
                                <button class="btn-secondary-action" type="button" data-friend-action="friend_remove" data-target-id="<?= e($friend['id']) ?>">Retirer</button>
                                <button class="btn-secondary-action" type="button" data-friend-action="friend_block" data-target-id="<?= e($friend['id']) ?>">Bloquer</button>
                            </div>
                        <?php endforeach; else: ?><p class="profile-muted">Aucun ami pour l’instant.</p><?php endif; ?>
                    </section>
                <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                    </div>
                    <div class="profile-column">
                        <section class="profile-card"><p>Ce profil est privé ou réservé aux amis.</p></section>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($profile['is_owner_profile']): ?>
                <section class="profile-card profile-edit-panel" id="profile-edit-panel" <?= ($saved || $error !== '') ? '' : 'hidden' ?>>
                    <h2>Modifier mon profil</h2>
                    <form method="post" action="<?= e(app_url('/profile.php')) ?>" class="profile-form">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <div class="profile-form-grid">
                            <div class="input-group">
                                <label class="input-label" for="pf-name">Pseudo public</label>
                                <input class="input-field" id="pf-name" name="display_name" maxlength="30" required value="<?= e($profile['display_name']) ?>">
                                <small class="input-hint">Le nom affiché aux autres joueurs.</small>
                            </div>
                            <div class="input-group">
                                <label class="input-label" for="pf-slug">Nom unique (URL)</label>
                                <input class="input-field" id="pf-slug" name="username" minlength="3" maxlength="20" pattern="[a-z0-9_]{3,20}" required value="<?= e($profile['username']) ?>">
                                <small class="input-hint">Identifiant unique pour trouver et partager ton profil : profile.php?u=<span id="slug-preview"><?= e($profile['username']) ?></span>.</small>
                            </div>
                            <div class="input-group span-2">
                                <span class="input-label">Avatar</span>
                                <div class="avatar-grid">
                                    <?php foreach (Database::AVAILABLE_AVATARS as $avatar): ?>
                                        <label class="avatar-radio">
                                            <input type="radio" name="avatar" value="<?= e($avatar) ?>" <?= $profile['avatar'] === $avatar ? 'checked' : '' ?>>
                                            <span class="avatar-box"><?= e($avatar) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="input-group span-2">
                                <label class="input-label" for="pf-bio">Bio</label>
                                <textarea class="input-field" id="pf-bio" name="bio" rows="3" maxlength="280" placeholder="Parle un peu de toi…"><?= e($profile['bio']) ?></textarea>
                                <small class="input-hint"><span id="bio-count"><?= e(mb_strlen((string)$profile['bio'], 'UTF-8')) ?></span> / 280</small>
                            </div>
                            <div class="input-group">
                                <label class="input-label" for="pf-vis">Visibilité du profil</label>
                                <select class="input-field" id="pf-vis" name="profile_visibility">
                                    <option value="public" <?= $profile['profile_visibility'] === 'public' ? 'selected' : '' ?>>Public</option>
                                    <option value="friends" <?= $profile['profile_visibility'] === 'friends' ? 'selected' : '' ?>>Amis seulement</option>
                                    <option value="private" <?= $profile['profile_visibility'] === 'private' ? 'selected' : '' ?>>Privé</option>
                                </select>
                            </div>
                            <div class="input-group">
                                <label class="input-label" for="pf-fav">Jeu favori</label>
                                <select class="input-field" id="pf-fav" name="favorite_game">
                                    <option value="">Aucun</option>
                                    <?php foreach (['morpion' => 'Morpion', 'connect4' => 'Puissance 4', 'pong' => 'Pong', 'naval' => 'Bataille Navale'] as $gameId => $gameName): ?>
                                        <option value="<?= e($gameId) ?>" <?= ($profile['favorite_game'] ?? '') === $gameId ? 'selected' : '' ?>><?= e($gameName) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <label class="profile-check">
                                <input type="checkbox" name="show_online" value="1" <?= $profile['show_online'] ? 'checked' : '' ?>>
                                <span>Afficher ma présence en ligne</span>
                            </label>
                            <div class="profile-form-actions">
                                <p class="profile-form-message <?= $saved ? 'success' : ($error !== '' ? 'error' : '') ?>" id="profile-form-message" role="status"><?= $saved ? 'Profil enregistré.' : e($error) ?></p>
                                <button class="btn-primary-action" type="submit">Enregistrer le profil</button>
                            </div>
                        </div>
                    </form>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    <?php if ($profile && $profile['is_owner_profile']): ?>
        <script>
            (() => {
                const endpoint = <?= js_json(app_url('/api/social')) ?>;
                const csrf = <?= js_json($csrfToken) ?>;
                async function request(payload) {
                    const response = await fetch(endpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify(payload)
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success) throw new Error(result.error || 'Action impossible.');
                    return result;
                }
                document.getElementById('friend-search-form')?.addEventListener('submit', async event => {
                    event.preventDefault();
                    const container = document.getElementById('friend-search-results');
                    container.textContent = 'Recherche…';
                    try {
                        const result = await request({ action: 'friend_search', query: document.getElementById('friend-search').value });
                        container.replaceChildren();
                        if (!result.results.length) { container.textContent = 'Aucun joueur trouvé.'; return; }
                        result.results.forEach(player => {
                            const row = document.createElement('div');
                            row.className = 'profile-friend-row';
                            const link = document.createElement('a');
                            link.className = 'profile-friend-info';
                            link.href = <?= js_json(app_url('/profile.php?u=')) ?> + encodeURIComponent(player.username);
                            const avatar = document.createElement('span');
                            avatar.className = 'profile-friend-avatar';
                            avatar.textContent = player.avatar || '🚀';
                            const meta = document.createElement('span');
                            meta.className = 'profile-friend-meta';
                            meta.append(document.createTextNode(player.display_name));
                            const detail = document.createElement('small');
                            detail.textContent = `@${player.username} · Niveau ${player.level}`;
                            meta.append(detail);
                            link.append(avatar, meta);
                            row.append(link);
                            if (player.relationship === 'none') {
                                const button = document.createElement('button');
                                button.className = 'btn-primary-action';
                                button.type = 'button';
                                button.textContent = 'Ajouter';
                                button.addEventListener('click', () => friendAction('friend_request', player.id));
                                row.append(button);
                            } else {
                                const status = document.createElement('span');
                                status.className = 'profile-muted';
                                status.textContent = ({
                                    friends: 'Déjà amis',
                                    pending_outgoing: 'Demande envoyée',
                                    pending_incoming: 'Demande reçue',
                                    blocked: 'Bloqué',
                                    blocked_by_you: 'Bloqué par vous'
                                })[player.relationship] || 'Indisponible';
                                row.append(status);
                            }
                            container.append(row);
                        });
                    } catch (error) { container.textContent = error.message; }
                });
                async function friendAction(action, targetId) {
                    try { await request({ action, target_id: targetId }); window.location.reload(); }
                    catch (error) { window.alert(error.message); }
                }
                document.getElementById('report-profile')?.addEventListener('click', async event => {
                    const reason = window.prompt('Décrivez le motif du signalement (5 à 1 000 caractères) :');
                    if (reason === null) return;
                    try {
                        await request({ action: 'report_profile', target_id: Number(event.currentTarget.dataset.targetId), reason });
                        window.alert('Signalement transmis à l’administration.');
                    } catch (error) { window.alert(error.message); }
                });
                document.querySelectorAll('[data-friend-action]').forEach(button => {
                    button.addEventListener('click', () => friendAction(button.dataset.friendAction, Number(button.dataset.targetId)));
                });
                const editPanel = document.getElementById('profile-edit-panel');
                const editToggle = document.querySelector('.profile-edit-toggle');
                editToggle?.addEventListener('click', () => {
                    const isHidden = editPanel.hidden;
                    editPanel.hidden = !isHidden;
                    editToggle.setAttribute('aria-expanded', String(isHidden));
                    if (isHidden) editPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
                if (editPanel && !editPanel.hidden) {
                    editToggle?.setAttribute('aria-expanded', 'true');
                    editPanel.scrollIntoView({ block: 'start' });
                }
                const slugInput = document.getElementById('pf-slug');
                const slugPreview = document.getElementById('slug-preview');
                slugInput?.addEventListener('input', () => {
                    if (slugPreview) slugPreview.textContent = slugInput.value;
                });
                const bioInput = document.getElementById('pf-bio');
                const bioCount = document.getElementById('bio-count');
                bioInput?.addEventListener('input', () => {
                    if (bioCount) bioCount.textContent = String(bioInput.value.length);
                });
            })();
        </script>
    <?php elseif ($profile && $user && !$profile['is_owner_profile']): ?>
        <script>
            (() => {
                const endpoint = <?= js_json(app_url('/api/social')) ?>;
                const csrf = <?= js_json($csrfToken) ?>;
                document.querySelectorAll('[data-friend-action]').forEach(button => button.addEventListener('click', async () => {
                    try {
                        const response = await fetch(endpoint, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                            body: JSON.stringify({ action: button.dataset.friendAction, target_id: Number(button.dataset.targetId) })
                        });
                        const result = await response.json();
                        if (!response.ok || !result.success) throw new Error(result.error || 'Action impossible.');
                        window.location.reload();
                    } catch (error) { window.alert(error.message); }
                }));
            })();
        </script>
    <?php endif; ?>
</body>
</html>
