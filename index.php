<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'rooms.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'space.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'friends.php';

function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function js_json(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

function render_view(string $name, array $data = []): never
{
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . $name . '.php';
    if (!is_file($file)) {
        throw new RuntimeException('Vue introuvable : ' . $name);
    }
    extract($data, EXTR_SKIP);
    require $file;
    exit;
}

function redirect_to(string $location, int $status = 302): never
{
    if (str_starts_with($location, '/')) {
        $basePath = app_base_path();
        if ($basePath !== '' && $basePath !== '/' && !str_starts_with($location, $basePath . '/')) {
            $location = $basePath . $location;
        }
    }
    header('Location: ' . $location, true, $status);
    exit;
}

function app_base_path(): string
{
    $applicationRoot = realpath(__DIR__);
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($applicationRoot === false || $documentRoot === false) {
        return '';
    }
    $rootPrefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with(strtolower($applicationRoot), strtolower($rootPrefix))) {
        return '';
    }
    $relative = substr($applicationRoot, strlen($rootPrefix));
    return '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
}

function app_url(string $path): string
{
    return rtrim(app_base_path(), '/') . '/' . ltrim($path, '/');
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_data(): array
{
    $data = $_POST;
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return $data;
        }
        try {
            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            json_response(['success' => false, 'error' => 'Corps JSON invalide.'], 400);
        }
        if (!is_array($parsed)) {
            json_response(['success' => false, 'error' => 'Corps JSON invalide.'], 400);
        }
        return array_merge($data, $parsed);
    }
    return $data;
}

function verify_csrf(?string $candidate = null): bool
{
    $candidate ??= $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
    return is_string($_SESSION['csrfToken'] ?? null) && is_string($candidate) &&
        $candidate !== '' && hash_equals($_SESSION['csrfToken'], $candidate);
}

function effective_theme(?array $user): string
{
    $candidate = $_COOKIE['mg_theme'] ?? $user['theme_preference'] ?? 'red';
    return is_string($candidate) && preg_match('/^[a-z0-9_-]{1,30}$/', $candidate) ? $candidate : 'red';
}

function current_user(Database $db): ?array
{
    $userId = filter_var($_SESSION['userId'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId) {
        return null;
    }
    $user = $db->getUserById($userId);
    if (!$user || $user['is_banned']) {
        unset($_SESSION['userId'], $_SESSION['username']);
        return null;
    }
    return $user;
}

function render_account_page(Database $db, ?array $user, string $error = '', string $success = ''): never
{
    $playerStats = [
        'total' => 0, 'completed' => 0, 'wins' => 0, 'losses' => 0, 'ties' => 0,
        'win_rate' => 0, 'points' => 0, 'bot_games' => 0, 'wifi_games' => 0,
        'local_games' => 0, 'bot_level_breakdown' => [],
    ];
    $gameHistory = [];
    $botStats = ['learning_level' => 0, 'games_learned' => 0];
    $userRank = 1;
    $leaderboard = $db->getLeaderboard(10, 'morpion', $user ? (int)$user['id'] : null);
    if ($user) {
        $playerStats = $db->getUserDetailedStats($user['id']);
        $gameHistory = $db->getUserGameHistory($user['id'], 40);
        $memory = $db->getBotMemory($user['id']);
        $botStats = ['learning_level' => $memory['learning_level'], 'games_learned' => $memory['games_learned']];
        $userRank = $db->getUserRank($user['id']);
    }
    render_view('account', [
        'user' => $user,
        'csrfToken' => $_SESSION['csrfToken'],
        'customThemeCss' => $db->getCustomThemeCss(),
        'currentTheme' => effective_theme($user),
        'error' => $error,
        'success' => $success,
        'playerStats' => $playerStats,
        'gameHistory' => $gameHistory,
        'botStats' => $botStats,
        'leaderboard' => $leaderboard,
        'userRank' => $userRank,
        'availableAvatars' => Database::AVAILABLE_AVATARS,
        'gameThemes' => $db->getThemes(),
    ]);
}

function api_morpion(Database $db, ?array $user, array $input): never
{
    $action = (string)($input['action'] ?? '');
    switch ($action) {
        case 'game_options':
            json_response([
                'success' => true,
                'themes' => array_map(static fn(array $theme): array => [
                    'key' => $theme['theme_key'], 'name' => $theme['name'],
                    'primaryColor' => $theme['primary_color'], 'backgroundColor' => $theme['background_color'],
                ], $db->getThemes()),
                'symbols' => array_map(static fn(array $symbol): array => [
                    'symbol' => $symbol['symbol'], 'label' => $symbol['label'],
                ], $db->getSymbols()),
            ]);

        case 'admin_stats':
            require_admin_api($user);
            json_response([
                'success' => true,
                // Permet au navigateur de corriger une éventuelle dérive d'horloge
                // pour le calcul « en ligne / hors ligne » et les temps relatifs.
                'server_time' => time(),
                'summary' => $db->getSummaryStats(),
                'bot_levels' => $db->getBotLevelsStats('morpion'),
                'popular_modes' => $db->getPopularModes('morpion'),
                'players' => admin_player_payload($db->getPlayersAdminList()),
                'themes' => admin_theme_payload($db->getThemes()),
                'symbols' => $db->getSymbols(),
            ]);

        case 'admin_rooms':
            require_admin_api($user);
            json_response(['success' => true, 'server_time' => time(), 'rooms' => list_all_rooms()]);

        case 'admin_delete_room':
            require_admin_csrf($user);
            $roomId = strtoupper((string)($input['room_id'] ?? ''));
            if (!preg_match('/^[A-Z0-9]{4}$/', $roomId)) {
                json_response(['success' => false, 'error' => 'Code de salon invalide.'], 400);
            }
            if (!delete_room($roomId)) {
                json_response(['success' => false, 'error' => 'Impossible de supprimer ce salon.'], 404);
            }
            json_response(['success' => true]);

        case 'admin_manage_user':
            require_admin_csrf($user);
            $operation = (string)($input['operation'] ?? '');

            // Actions groupées : traitées en une seule requête HTTP.
            if ($operation === 'bulk') {
                $bulkOperation = (string)($input['bulk_operation'] ?? '');
                if (!in_array($bulkOperation, ['ban', 'unban', 'delete', 'reset_bot', 'ai_block', 'ai_unblock'], true)) {
                    json_response(['success' => false, 'error' => 'Action groupée invalide.'], 400);
                }
                $rawIds = $input['ids'] ?? [];
                if (!is_array($rawIds) || $rawIds === []) {
                    json_response(['success' => false, 'error' => 'Aucun compte sélectionné.'], 400);
                }
                $affected = 0;
                $skipped = 0;
                foreach (array_slice(array_values($rawIds), 0, 200) as $rawId) {
                    $targetId = filter_var($rawId, FILTER_VALIDATE_INT);
                    if (!$targetId || $targetId === $user['id']) {
                        $skipped++;
                        continue;
                    }
                    $target = $db->getUserById($targetId);
                    if (!$target || $target['role'] === 'admin') {
                        $skipped++;
                        continue;
                    }
                    if ($bulkOperation === 'delete') {
                        $affected += $db->deleteUser($targetId) ? 1 : 0;
                    } elseif ($bulkOperation === 'reset_bot') {
                        $db->updateBotMemory($targetId, ['learning_level' => 0, 'mistakes' => [], 'games_learned' => 0]);
                        $affected++;
                    } elseif ($bulkOperation === 'ai_block' || $bulkOperation === 'ai_unblock') {
                        $db->updateUser($targetId, ['ai_blocked' => $bulkOperation === 'ai_block' ? 1 : 0]);
                        $affected++;
                    } else {
                        $db->updateUser($targetId, ['is_banned' => $bulkOperation === 'ban' ? 1 : 0]);
                        $affected++;
                    }
                }
                $db->logEvent('admin', sprintf(
                    '%s : action groupée « %s » sur %d compte(s)%s',
                    $user['display_name'],
                    $bulkOperation,
                    $affected,
                    $skipped > 0 ? ' (' . $skipped . ' ignoré(s))' : ''
                ), ['operation' => $bulkOperation, 'affected' => $affected, 'skipped' => $skipped], (int)$user['id'], $user['display_name'], 'warning');
                json_response(['success' => true, 'affected' => $affected, 'skipped' => $skipped, 'operation' => $bulkOperation]);
            }

            $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$userId || $userId === $user['id']) {
                json_response(['success' => false, 'error' => 'Compte cible invalide.'], 400);
            }
            $target = $db->getUserById($userId);
            if (!$target || $target['role'] === 'admin') {
                json_response(['success' => false, 'error' => 'Ce compte ne peut pas être modifié ici.'], 403);
            }
            if ($operation === 'delete') {
                $db->deleteUser($userId);
                $db->logEvent('admin', $user['display_name'] . ' a supprimé le compte ' . $target['display_name'], ['user_id' => $userId, 'email' => $target['email']], (int)$user['id'], $user['display_name'], 'danger');
            } elseif ($operation === 'ban' || $operation === 'unban') {
                $db->updateUser($userId, ['is_banned' => $operation === 'ban' ? 1 : 0]);
                $db->logEvent('admin', sprintf('%s a %s le compte %s', $user['display_name'], $operation === 'ban' ? 'banni' : 'débanni', $target['display_name']), ['user_id' => $userId], (int)$user['id'], $user['display_name'], $operation === 'ban' ? 'danger' : 'info');
            } elseif ($operation === 'ai_block' || $operation === 'ai_unblock') {
                $db->updateUser($userId, ['ai_blocked' => $operation === 'ai_block' ? 1 : 0]);
                $db->logEvent('admin', sprintf('%s a %s l’accès à Space Core pour %s', $user['display_name'], $operation === 'ai_block' ? 'bloqué' : 'rétabli', $target['display_name']), ['user_id' => $userId], (int)$user['id'], $user['display_name'], 'warning');
            } elseif ($operation === 'reset_bot') {
                $db->updateBotMemory($userId, ['learning_level' => 0, 'mistakes' => [], 'games_learned' => 0]);
                $db->logEvent('admin', $user['display_name'] . ' a réinitialisé l’IA de ' . $target['display_name'], ['user_id' => $userId], (int)$user['id'], $user['display_name'], 'info');
            } elseif ($operation === 'reset_ranked') {
                $gameId = (string)($input['game_id'] ?? 'morpion');
                if (!in_array($gameId, ['morpion', 'connect4', 'pong', 'naval'], true)) {
                    json_response(['success' => false, 'error' => 'Jeu invalide.'], 400);
                }
                $db->setRankedPoints($userId, 0, true, $gameId);
                $db->logEvent('admin', $user['display_name'] . ' a remis à zéro les points classés de ' . $target['display_name'], ['user_id' => $userId, 'game_id' => $gameId], (int)$user['id'], $user['display_name'], 'warning');
            } elseif ($operation === 'edit') {
                $name = trim((string)($input['display_name'] ?? ''));
                $email = strtolower(trim((string)($input['email'] ?? '')));
                if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 30 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    json_response(['success' => false, 'error' => 'Pseudo ou e-mail invalide.'], 400);
                }
                $existing = $db->getUserByEmail($email);
                if ($existing && $existing['id'] !== $userId) {
                    json_response(['success' => false, 'error' => 'Cet e-mail est déjà utilisé.'], 409);
                }
                if (!$db->isDisplayNameAvailable($name, (int)$userId)) {
                    json_response(['success' => false, 'error' => 'Ce pseudo est déjà utilisé.'], 409);
                }
                $db->updateUser($userId, ['display_name' => $name, 'email' => $email]);
                $db->logEvent('admin', sprintf('%s a modifié le compte %s (%s)', $user['display_name'], $name, $email), ['user_id' => $userId], (int)$user['id'], $user['display_name'], 'info');
            } else {
                json_response(['success' => false, 'error' => 'Opération invalide.'], 400);
            }
            json_response(['success' => true]);

        case 'admin_manage_theme':
            require_admin_csrf($user);
            $operation = (string)($input['operation'] ?? '');
            if ($operation === 'add') {
                $name = trim((string)($input['name'] ?? ''));
                $primary = trim((string)($input['primary_color'] ?? ''));
                $background = trim((string)($input['background_color'] ?? ''));
                $key = strtolower(trim((string)($input['key'] ?? '')));
                if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 30 ||
                    !preg_match('/^[a-z][a-z0-9-]{1,24}$/', $key) ||
                    !preg_match('/^#[0-9a-fA-F]{6}$/', $primary) ||
                    !preg_match('/^#[0-9a-fA-F]{6}$/', $background)) {
                    json_response(['success' => false, 'error' => 'Nom, identifiant ou couleur de thème invalide.'], 400);
                }
                if (array_filter($db->getThemes(), static fn(array $theme): bool => $theme['theme_key'] === $key)) {
                    json_response(['success' => false, 'error' => 'Cet identifiant de thème existe déjà.'], 409);
                }
                $db->addTheme($key, $name, $primary, $background);
            } elseif ($operation === 'delete') {
                $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || !$db->deleteTheme($id)) {
                    json_response(['success' => false, 'error' => 'Seuls les thèmes personnalisés peuvent être supprimés.'], 400);
                }
            } else {
                json_response(['success' => false, 'error' => 'Opération de thème invalide.'], 400);
            }
            json_response(['success' => true]);

        case 'admin_manage_symbol':
            require_admin_csrf($user);
            $operation = (string)($input['operation'] ?? '');
            if ($operation === 'add') {
                $symbol = trim((string)($input['symbol'] ?? ''));
                $label = trim((string)($input['label'] ?? ''));
                if ($symbol === '' || mb_strlen($symbol, 'UTF-8') > 8 || $label === '' || mb_strlen($label, 'UTF-8') > 30) {
                    json_response(['success' => false, 'error' => 'Symbole ou nom invalide.'], 400);
                }
                if (array_filter($db->getSymbols(), static fn(array $item): bool => $item['symbol'] === $symbol)) {
                    json_response(['success' => false, 'error' => 'Ce symbole existe déjà.'], 409);
                }
                $db->addSymbol($symbol, $label);
            } elseif ($operation === 'delete') {
                $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || !$db->deleteSymbol($id)) {
                    json_response(['success' => false, 'error' => 'Seuls les symboles personnalisés peuvent être supprimés.'], 400);
                }
            } else {
                json_response(['success' => false, 'error' => 'Opération de symbole invalide.'], 400);
            }
            json_response(['success' => true]);

        /* ---------------- Panneau administrateur : nouvelles sections --------- */

        case 'admin_platform_stats':
            require_admin_api($user);
            $payload = [
                'success' => true,
                'platform' => $db->getPlatformStats(),
                'leaderboard' => array_slice($db->getLeaderboard(10, 'morpion', $user ? (int)$user['id'] : null), 0, 10),
                'live_players' => $db->getLivePlayers(null, 300),
                'events' => $db->getRecentEvents(25),
                'event_categories' => $db->getEventCategories(),
                'bot_levels' => $db->getBotLevelsStats(),
                'popular_modes' => $db->getPopularModes(),
                'players' => admin_player_payload($db->getPlayersAdminList()),
                'themes' => admin_theme_payload($db->getThemes()),
                'symbols' => $db->getSymbols(),
                'rooms' => list_all_rooms(),
                'settings' => $db->getSettings(),
                'summary' => $db->getSummaryStats(),
            ];
            json_response($payload);

        case 'admin_social_data':
            require_admin_api($user);
            json_response(['success' => true] + $db->getSocialAdminData());

        case 'admin_social_action':
            require_admin_csrf($user);
            $operation = (string)($input['operation'] ?? '');
            if ($operation === 'profile') {
                $targetId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
                $target = $targetId ? $db->getUserById((int)$targetId) : null;
                if (!$target || $target['role'] === 'admin') {
                    json_response(['success' => false, 'error' => 'Profil cible invalide.'], 404);
                }
                $updates = [];
                foreach (['bio', 'avatar', 'display_name', 'profile_visibility'] as $field) {
                    if (array_key_exists($field, $input)) {
                        $updates[$field] = $input[$field];
                    }
                }
                if (!empty($input['reset_bio'])) {
                    $updates['bio'] = '';
                }
                if (!empty($input['reset_avatar'])) {
                    $updates['avatar'] = '🚀';
                }
                if (!empty($input['reset_username'])) {
                    $suffix = 1;
                    do {
                        $suffixText = $suffix > 1 ? '_' . $suffix : '';
                        $candidateName = 'Joueur ' . $targetId . ($suffix > 1 ? ' ' . $suffix : '');
                        $candidateUsername = substr('joueur_' . $targetId, 0, 20 - strlen($suffixText)) . $suffixText;
                        $suffix++;
                    } while (!$db->isDisplayNameAvailable($candidateName, (int)$targetId) ||
                        !$db->isUsernameAvailable($candidateUsername, (int)$targetId));
                    $updates['display_name'] = $candidateName;
                    $updates['username'] = $candidateUsername;
                }
                if (!empty($input['force_private'])) {
                    $updates['profile_visibility'] = 'private';
                }
                if (array_key_exists('social_suspended', $input)) {
                    $updates['social_suspended'] = filter_var($input['social_suspended'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                }
                if ($updates) {
                    try {
                        $db->updateUser((int)$targetId, $updates);
                    } catch (InvalidArgumentException $error) {
                        json_response(['success' => false, 'error' => $error->getMessage()], 400);
                    }
                }
                $db->logEvent('social', 'Modification admin du profil de ' . $target['display_name'], [
                    'target_id' => (int)$targetId, 'fields' => array_keys($updates),
                ], (int)$user['id'], $user['display_name'], 'warning');
            } elseif ($operation === 'report') {
                $reportId = filter_var($input['report_id'] ?? null, FILTER_VALIDATE_INT);
                $status = (string)($input['status'] ?? '');
                if (!$reportId || !in_array($status, ['resolved', 'sanctioned'], true)) {
                    json_response(['success' => false, 'error' => 'Signalement ou traitement invalide.'], 400);
                }
                $reports = $db->getProfileReports();
                $report = null;
                foreach ($reports as $item) {
                    if ((int)$item['id'] === (int)$reportId) { $report = $item; break; }
                }
                if (!$report) {
                    json_response(['success' => false, 'error' => 'Signalement introuvable ou déjà traité.'], 404);
                }
                if ($status === 'sanctioned') {
                    $db->updateUser((int)$report['target_id'], ['social_suspended' => 1, 'profile_visibility' => 'private']);
                }
                $db->handleProfileReport((int)$reportId, (int)$user['id'], $status);
                $db->logEvent('social', 'Signalement traité pour ' . $report['target_name'], [
                    'report_id' => (int)$reportId, 'target_id' => (int)$report['target_id'], 'status' => $status,
                ], (int)$user['id'], $user['display_name'], $status === 'sanctioned' ? 'danger' : 'info');
            } elseif ($operation === 'setting') {
                $key = (string)($input['key'] ?? '');
                $allowed = [
                    'social_friends_enabled', 'social_public_profiles_enabled',
                    'social_default_visibility', 'social_friend_limit', 'social_daily_request_limit',
                ];
                if (!in_array($key, $allowed, true)) {
                    json_response(['success' => false, 'error' => 'Réglage social inconnu.'], 400);
                }
                if ($key === 'social_default_visibility') {
                    $value = (string)($input['value'] ?? '');
                    if (!in_array($value, ['public', 'friends', 'private'], true)) {
                        json_response(['success' => false, 'error' => 'Visibilité par défaut invalide.'], 400);
                    }
                } elseif (in_array($key, ['social_friend_limit', 'social_daily_request_limit'], true)) {
                    $value = filter_var($input['value'] ?? null, FILTER_VALIDATE_INT);
                    if ($value === false || $value < 1 || $value > ($key === 'social_friend_limit' ? 10000 : 500)) {
                        json_response(['success' => false, 'error' => 'Limite sociale invalide.'], 400);
                    }
                    $value = (string)$value;
                } else {
                    $value = filter_var($input['value'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
                }
                $db->setSetting($key, (string)$value);
                $db->logEvent('social', $user['display_name'] . ' a modifié un réglage social', [
                    'key' => $key, 'value' => $value,
                ], (int)$user['id'], $user['display_name'], 'warning');
            } elseif ($operation === 'achievement') {
                $key = (string)($input['key'] ?? '');
                try {
                    if (isset($input['enabled'])) {
                        $db->setAchievementEnabled($key, filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN));
                    } elseif (isset($input['user_id'])) {
                        $targetId = filter_var($input['user_id'], FILTER_VALIDATE_INT);
                        if (!$targetId) {
                            json_response(['success' => false, 'error' => 'Joueur cible invalide.'], 400);
                        }
                        $db->awardAchievement((int)$targetId, $key);
                    } else {
                        json_response(['success' => false, 'error' => 'Action de succès invalide.'], 400);
                    }
                } catch (InvalidArgumentException $error) {
                    json_response(['success' => false, 'error' => $error->getMessage()], 400);
                } catch (RuntimeException $error) {
                    json_response(['success' => false, 'error' => $error->getMessage()], 404);
                }
                $db->logEvent('social', $user['display_name'] . ' a modifié un succès', [
                    'key' => $key, 'user_id' => $input['user_id'] ?? null, 'enabled' => $input['enabled'] ?? null,
                ], (int)$user['id'], $user['display_name'], 'info');
            } else {
                json_response(['success' => false, 'error' => 'Action sociale admin inconnue.'], 400);
            }
            json_response(['success' => true, 'social' => $db->getSocialAdminData()]);

        case 'admin_game_rank_stats':
            require_admin_api($user);
            $gameId = (string)($input['game_id'] ?? 'morpion');
            try {
                json_response(['success' => true] + $db->getGameRankAdminStats($gameId));
            } catch (InvalidArgumentException $error) {
                json_response(['success' => false, 'error' => $error->getMessage()], 400);
            }

        case 'admin_player_game_progress':
            require_admin_api($user);
            $targetId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
            $gameId = (string)($input['game_id'] ?? 'morpion');
            if (!$targetId || !$db->getUserById((int)$targetId)) {
                json_response(['success' => false, 'error' => 'Joueur introuvable.'], 404);
            }
            try {
                json_response(['success' => true, 'progress' => $db->getUserGameProgress((int)$targetId, $gameId)]);
            } catch (InvalidArgumentException $error) {
                json_response(['success' => false, 'error' => $error->getMessage()], 400);
            }

        case 'admin_space_knowledge':
            $operation = (string)($input['operation'] ?? 'list');
            if ($operation === 'list') {
                require_admin_api($user);
                json_response([
                    'success' => true,
                    'knowledge' => $db->listSpaceKnowledge(),
                    'usage' => $db->getSpaceUsage(365),
                ]);
            }
            require_admin_csrf($user);
            if ($operation === 'save') {
                $keyword = trim((string)($input['keyword'] ?? ''));
                $content = trim((string)($input['content'] ?? ''));
                if ($keyword === '' || mb_strlen($keyword, 'UTF-8') > 255 ||
                    $content === '' || mb_strlen($content, 'UTF-8') > 5000) {
                    json_response(['success' => false, 'error' => 'Le sujet (255 caractères) et le contenu (5 000 caractères) sont obligatoires.'], 400);
                }
                $id = $db->saveSpaceKnowledge($keyword, $content, 'admin');
                $db->logEvent(
                    'admin',
                    $user['display_name'] . ' a ajouté une connaissance à Space',
                    ['knowledge_id' => $id, 'keyword' => $keyword],
                    (int)$user['id'],
                    $user['display_name'],
                    'info'
                );
                json_response([
                    'success' => true,
                    'id' => $id,
                    'knowledge' => $db->listSpaceKnowledge(),
                    'usage' => $db->getSpaceUsage(365),
                ]);
            }
            if ($operation === 'delete') {
                $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || !$db->deleteSpaceKnowledge($id)) {
                    json_response(['success' => false, 'error' => 'Cette connaissance Space est introuvable.'], 404);
                }
                $db->logEvent(
                    'admin',
                    $user['display_name'] . ' a supprimé une connaissance de Space',
                    ['knowledge_id' => $id],
                    (int)$user['id'],
                    $user['display_name'],
                    'warning'
                );
                json_response([
                    'success' => true,
                    'knowledge' => $db->listSpaceKnowledge(),
                    'usage' => $db->getSpaceUsage(365),
                ]);
            }
            json_response(['success' => false, 'error' => 'Opération de connaissance Space invalide.'], 400);

        case 'admin_morpion_stats':
            require_admin_api($user);
            $gameId = (string)($input['game_id'] ?? 'morpion');
            if ($gameId !== 'morpion') {
                json_response(['success' => false, 'error' => 'Les statistiques détaillées ne sont disponibles que pour le Morpion actuellement.'], 400);
            }
            json_response([
                'success' => true,
                'server_time' => time(),
                'morpion' => $db->getMorpionAdminStats(),
                'bot_levels' => $db->getBotLevelsStats('morpion'),
                'popular_modes' => $db->getPopularModes('morpion'),
                'leaderboard' => array_slice($db->getLeaderboard(15, 'morpion', $user ? (int)$user['id'] : null), 0, 15),
                'top_player' => $db->getTopPlayer(),
                'distribution' => $db->getRankDistribution(),
                'recent_ranked' => $db->getRecentEvents(20, 'ranked'),
            ]);

        case 'admin_events':
            require_admin_api($user);
            json_response([
                'success' => true,
                'server_time' => time(),
                'events' => $db->getRecentEvents((int)($input['limit'] ?? 80), (string)($input['category'] ?? 'all')),
                'categories' => $db->getEventCategories(),
            ]);

        case 'admin_set_points':
            require_admin_csrf($user);
            $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
            $points = filter_var($input['points'] ?? null, FILTER_VALIDATE_INT);
            if (!$userId || $points === false || $points === null) {
                json_response(['success' => false, 'error' => 'Joueur ou nombre de points invalide.'], 400);
            }
            if ($userId === (int)$user['id']) {
                json_response(['success' => false, 'error' => 'Vous ne pouvez pas modifier vos propres points ici.'], 400);
            }
            $target = $db->getUserById($userId);
            if (!$target) {
                json_response(['success' => false, 'error' => 'Joueur introuvable.'], 404);
            }
            $gameId = (string)($input['game_id'] ?? 'morpion');
            if (!in_array($gameId, ['morpion', 'connect4', 'pong', 'naval'], true)) {
                json_response(['success' => false, 'error' => 'Jeu invalide.'], 400);
            }
            $change = $db->setRankedPoints($userId, (int)$points, false, $gameId);
            $db->logEvent(
                'admin',
                sprintf('%s a fixé les points de %s : %d → %d', $user['display_name'], $target['display_name'], $change['before'], $change['after']),
                ['user_id' => $userId, 'game_id' => $gameId, 'before' => $change['before'], 'after' => $change['after']],
                (int)$user['id'],
                $user['display_name'],
                'warning'
            );
            json_response(['success' => true, 'change' => $change]);

        case 'admin_set_xp':
            require_admin_csrf($user);
            $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
            $xp = filter_var($input['xp'] ?? null, FILTER_VALIDATE_INT);
            if (!$userId || $xp === false || $xp === null) {
                json_response(['success' => false, 'error' => 'Joueur ou XP invalide.'], 400);
            }
            $target = $db->getUserById($userId);
            if (!$target) {
                json_response(['success' => false, 'error' => 'Joueur introuvable.'], 404);
            }
            $gameId = (string)($input['game_id'] ?? 'morpion');
            if (!in_array($gameId, ['morpion', 'connect4', 'pong', 'naval'], true)) {
                json_response(['success' => false, 'error' => 'Jeu invalide.'], 400);
            }
            $change = $db->setPlayerXp($userId, (int)$xp, $gameId);
            $db->logEvent(
                'admin',
                sprintf('%s a fixé l’XP de %s : %d → %d (niveau %d)', $user['display_name'], $target['display_name'], $change['before'], $change['after'], $change['level']['level']),
                ['user_id' => $userId, 'game_id' => $gameId, 'xp' => $change['after']],
                (int)$user['id'],
                $user['display_name'],
                'warning'
            );
            json_response(['success' => true, 'change' => $change]);

        case 'admin_reset_season':
            require_admin_csrf($user);
            $gameId = (string)($input['game_id'] ?? 'morpion');
            if (!in_array($gameId, ['morpion', 'connect4', 'pong', 'naval'], true)) {
                json_response(['success' => false, 'error' => 'Jeu invalide.'], 400);
            }
            $settings = $db->resetRankedSeason($gameId);
            $db->logEvent(
                'admin',
                sprintf('%s a lancé la saison classée n°%d (réinitialisation de %d compte(s))', $user['display_name'], $settings['season'], $settings['affected']),
                $settings,
                (int)$user['id'],
                $user['display_name'],
                'danger'
            );
            json_response(['success' => true, 'season' => $settings['season'], 'affected' => $settings['affected']]);

        case 'admin_update_setting':
            require_admin_csrf($user);
            $allowed = ['maintenance', 'ai_enabled', 'maintenance_message'];
            $key = (string)($input['key'] ?? '');
            if (!in_array($key, $allowed, true)) {
                json_response(['success' => false, 'error' => 'Réglage inconnu.'], 400);
            }
            $value = $input['value'] ?? null;
            if ($key === 'maintenance_message') {
                $value = mb_substr(trim((string)$value), 0, 240, 'UTF-8');
            } else {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }
            $db->setSetting($key, (string)$value);
            $labels = ['maintenance' => 'le mode maintenance', 'ai_enabled' => 'l’accès à Space Core', 'maintenance_message' => 'le message de maintenance'];
            $db->logEvent(
                'admin',
                sprintf('%s a modifié %s', $user['display_name'], $labels[$key]),
                ['key' => $key, 'value' => $value],
                (int)$user['id'],
                $user['display_name'],
                $key === 'maintenance' ? 'danger' : 'warning'
            );
            json_response(['success' => true, 'key' => $key, 'value' => $value]);

        case 'admin_clear_events':
            require_admin_csrf($user);
            $removed = $db->clearEvents();
            $db->logEvent('admin', sprintf('%s a purgé le journal (%d entrée(s))', $user['display_name'], $removed), ['removed' => $removed], (int)$user['id'], $user['display_name'], 'danger');
            json_response(['success' => true, 'removed' => $removed]);

        case 'presence':
            if (!$user) {
                json_response(['success' => true, 'authenticated' => false]);
            }
            if (!verify_csrf()) {
                json_response(['success' => false, 'error' => 'Jeton de sécurité invalide.'], 403);
            }
            $db->updatePresenceContext((int)$user['id'], (string)($input['context'] ?? 'hub'));
            json_response(['success' => true, 'authenticated' => true]);

        case 'bot_profile':
            if (!$user) {
                json_response(['success' => true, 'profile' => null]);
            }
            $memory = $db->getBotMemory($user['id']);
            json_response(['success' => true, 'profile' => [
                'learningLevel' => $memory['learning_level'], 'mistakes' => $memory['mistakes'],
                'gamesLearned' => $memory['games_learned'],
            ]]);

        case 'start_game':
            $mode = (string)($input['mode'] ?? '');
            $gameId = (string)($input['game_id'] ?? 'morpion');
            $size = filter_var($input['board_size'] ?? 3, FILTER_VALIDATE_INT) ?: 3;
            if (!in_array($mode, ['local', 'bot', 'wifi'], true) ||
                !in_array($gameId, ['morpion', 'connect4', 'pong', 'naval'], true) ||
                !in_array($size, [3, 5, 10, 12, 15, 19], true)) {
                json_response(['success' => false, 'error' => 'Paramètres de partie invalides.'], 400);
            }
            $isRanked = filter_var($input['is_ranked'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($isRanked && !$user) {
                json_response(['success' => false, 'error' => 'Connectez-vous pour lancer un match classé.'], 401);
            }
            $gameSessionId = bin2hex(random_bytes(16));
            $db->createSession([
                'id' => $gameSessionId,
                'session_key' => session_id() ?: bin2hex(random_bytes(8)),
                'user_id' => $user['id'] ?? null,
                'mode' => $mode,
                'game_id' => $gameId,
                'opponent_name' => (string)($input['opponent_name'] ?? ($mode === 'bot' ? 'Space' : 'Joueur 2')),
                'bot_level' => $mode === 'bot' ? (string)($input['bot_level'] ?? 'medium') : null,
                'board_size' => $size,
                'result' => null,
                'started_at' => time(),
                'completed_at' => null,
                'is_ranked' => $isRanked ? 1 : 0,
            ]);
            json_response(['success' => true, 'game_id' => $gameSessionId, 'is_ranked' => $isRanked]);

        case 'finish_game':
            $gameId = (string)($input['game_id'] ?? '');
            $winner = (string)($input['winner'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/', $gameId) || !in_array($winner, ['X', 'O', 'TIE'], true)) {
                json_response(['success' => false, 'error' => 'Résultat de partie invalide.'], 400);
            }
            $game = $db->getSessionById($gameId);
            if (!$game) {
                json_response(['success' => false, 'error' => 'Partie introuvable pour cette session.'], 404);
            }
            if ($game['user_id'] !== null && (!$user || (int)$user['id'] !== (int)$game['user_id'])) {
                json_response(['success' => false, 'error' => 'Cette partie appartient à un autre compte.'], 403);
            }
            if ($game['user_id'] === null && !hash_equals((string)$game['session_key'], session_id())) {
                json_response(['success' => false, 'error' => 'Cette partie appartient à une autre session.'], 403);
            }
            if ($game['completed_at'] !== null) {
                json_response(['success' => true, 'already_recorded' => true, 'rewards' => null]);
            }
            if (!$db->completeGameSession($gameId, $winner)) {
                json_response(['success' => true, 'already_recorded' => true, 'rewards' => null]);
            }
            if ($game['mode'] === 'bot' && $game['bot_level'] === 'adaptive' && $game['user_id'] !== null) {
                $memory = $db->getBotMemory($game['user_id']);
                $learningLevel = $memory['learning_level'];
                if ($winner === 'X') {
                    $learningLevel = min(3, $learningLevel + 1);
                } elseif ($winner === 'O') {
                    $learningLevel = max(-2, $learningLevel - 1);
                }
                $mistakes = $memory['mistakes'];
                $decisions = is_array($input['decisions'] ?? null) ? $input['decisions'] : [];
                $last = $decisions ? end($decisions) : null;
                if ($winner === 'X' && is_array($last) && isset($last['board'], $last['move'], $last['size'], $last['winLength']) && is_numeric($last['move'])) {
                    $key = $last['board'] . ':' . $last['size'] . ':' . $last['winLength'];
                    $found = false;
                    foreach ($mistakes as &$mistake) {
                        if (($mistake['key'] ?? '') === $key && (int)$mistake['move'] === (int)$last['move']) {
                            $mistake['failures'] = min(20, (int)($mistake['failures'] ?? 0) + 1);
                            $found = true;
                            break;
                        }
                    }
                    unset($mistake);
                    if (!$found) {
                        $mistakes[] = [
                            'board' => (string)$last['board'], 'move' => (int)$last['move'],
                            'size' => (int)$last['size'], 'winLength' => (int)$last['winLength'],
                            'key' => $key, 'failures' => 1,
                        ];
                    }
                }
                $db->updateBotMemory($game['user_id'], [
                    'learning_level' => $learningLevel,
                    'mistakes' => array_slice($mistakes, -100),
                    'games_learned' => $memory['games_learned'] + 1,
                ]);
            }

            // --- Récompenses : points classés, XP et progression -----------
            $rewards = null;
            $playerId = $game['user_id'];
            if ($playerId !== null && $user && (int)$user['id'] === (int)$playerId) {
                $outcome = mg_match_outcome($game['mode'], $winner, (string)($game['user_symbol'] ?: 'X'));
                try {
                    if ((int)$game['is_ranked'] === 1) {
                        $rewards = $db->applyRankedResult((int)$playerId, (string)$game['mode'], $game['bot_level'], $outcome, (string)$game['game_id']);
                        $rewards['ranked'] = true;
                        $db->updateSession($gameId, [
                            'points_delta' => $rewards['points'],
                            'xp_delta' => $rewards['xp'],
                        ]);
                        $db->logEvent('ranked', sprintf(
                            '%s : match classé %s (%s) %+d pts',
                            $user['display_name'],
                            $game['mode'] === 'bot' ? 'contre ' . ($game['bot_level'] ?? 'bot') : 'PvP',
                            ['win' => 'victoire', 'loss' => 'défaite', 'tie' => 'égalité'][$outcome],
                            $rewards['points']
                        ), ['points' => $rewards['points'], 'xp' => $rewards['xp'], 'mode' => $game['mode']], (int)$playerId, $user['display_name'], $rewards['points'] >= 0 ? 'success' : 'warning');
                        if ($rewards['level_up']) {
                            $db->logEvent('progression', $user['display_name'] . ' atteint le niveau ' . $rewards['level_after'], ['level' => $rewards['level_after']], (int)$playerId, $user['display_name'], 'success');
                        }
                        if ($rewards['rank_after']['key'] !== $rewards['rank_before']['key']) {
                            $db->logEvent('progression', $user['display_name'] . ' passe ' . $rewards['rank_after']['label'], ['rank' => $rewards['rank_after']['key']], (int)$playerId, $user['display_name'], 'success');
                        }
                    } else {
                        $rewards = $db->applyFriendlyXp((int)$playerId, (string)$game['game_id']);
                        $rewards['ranked'] = false;
                        $rewards['outcome'] = $outcome;
                        $rewards['points'] = 0;
                        $db->updateSession($gameId, ['xp_delta' => $rewards['xp'], 'points_delta' => 0]);
                    }
                    $rewards['profile'] = $db->getRankedProfile((int)$playerId, (string)$game['game_id']);
                } catch (Throwable $rewardError) {
                    error_log('Récompenses impossibles : ' . $rewardError->getMessage());
                    $rewards = null;
                }
            }
            json_response(['success' => true, 'rewards' => $rewards]);

        case 'ranked_profile':
            if (!$user) {
                json_response(['success' => true, 'authenticated' => false, 'profile' => null]);
            }
            $gameId = (string)($input['game_id'] ?? 'morpion');
            if (!in_array($gameId, ['morpion', 'connect4', 'pong', 'naval'], true)) {
                json_response(['success' => false, 'error' => 'Jeu de classement invalide.'], 400);
            }
            $history = array_values(array_filter(
                $db->getUserGameHistory((int)$user['id'], 40, $gameId),
                static fn(array $row): bool => (int)($row['is_ranked'] ?? 0) === 1
            ));
            json_response([
                'success' => true,
                'authenticated' => true,
                'profile' => $db->getRankedProfile((int)$user['id'], $gameId),
                'leaderboard' => array_slice($db->getLeaderboard(10, $gameId, (int)$user['id']), 0, 10),
                'history' => array_slice($history, 0, 15),
            ]);

        case 'ranked_leaderboard':
            $gameId = (string)($input['game_id'] ?? 'morpion');
            if (!in_array($gameId, ['morpion', 'connect4', 'pong', 'naval'], true)) {
                json_response(['success' => false, 'error' => 'Jeu de classement invalide.'], 400);
            }
            json_response([
                'success' => true,
                'leaderboard' => $db->getLeaderboard(min(100, max(3, (int)($input['limit'] ?? 25))), $gameId, $user ? (int)$user['id'] : null),
                'top_player' => $db->getTopPlayer($gameId),
                'distribution' => $db->getRankDistribution($gameId),
                'season' => (int)$db->getSetting('ranked_season', '1'),
            ]);

        case 'get_user_stats':
            if (!$user) {
                json_response(['success' => false, 'error' => 'Connexion requise pour consulter les statistiques.'], 401);
            }
            json_response(['success' => true, 'stats' => $db->getUserDetailedStats($user['id']), 'rank' => $db->getUserRank($user['id'])]);

        case 'sync_stats':
            if (!$user) {
                json_response(['success' => false, 'error' => 'Connexion requise pour synchroniser les statistiques.'], 401);
            }
            if (!verify_csrf()) {
                json_response(['success' => false, 'error' => 'Jeton de sécurité invalide.'], 403);
            }
            $stats = $db->syncUserLocalStats(
                $user['id'],
                max(0, (int)($input['wins'] ?? 0)),
                max(0, (int)($input['losses'] ?? 0)),
                max(0, (int)($input['ties'] ?? 0))
            );
            json_response(['success' => true, 'stats' => $stats, 'rank' => $db->getUserRank($user['id'])]);

        case 'create_room':
            $size = (int)($input['size'] ?? 3);
            if (!in_array($size, [3, 5, 10, 12, 15, 19], true)) {
                $size = 3;
            }
            $targetWins = (int)($input['target_wins'] ?? 1);
            if (!in_array($targetWins, [1, 2, 3, 5], true)) {
                $targetWins = 1;
            }
            $hostName = mb_substr(trim((string)($input['player_name'] ?? 'Joueur 1')), 0, 20, 'UTF-8') ?: 'Joueur 1';
            $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $roomId = '';
            do {
                for ($index = 0; $index < 4; $index++) {
                    $roomId .= $characters[random_int(0, strlen($characters) - 1)];
                }
            } while (is_file(room_file_path($roomId)));
            $now = time();
            $playerId = bin2hex(random_bytes(16));
            $room = [
                'roomId' => $roomId, 'size' => $size, 'winLen' => $size === 3 ? 3 : ($size === 5 ? 4 : 5),
                'targetWins' => $targetWins, 'status' => 'waiting',
                'playerX' => ['id' => $playerId, 'name' => $hostName, 'lastSeen' => $now],
                'playerO' => null, 'currentTurn' => 'X', 'startingPlayer' => 'X',
                'board' => array_fill(0, $size * $size, null), 'winner' => null, 'winningCombo' => [],
                'scores' => ['X' => 0, 'O' => 0, 'ties' => 0], 'rematchVotes' => [], 'moveHistory' => [],
                'pendingAction' => null, 'lastActionResult' => null, 'tournamentWinner' => null, 'updatedAt' => $now,
            ];
            save_room($room);
            json_response(['success' => true, 'roomId' => $roomId, 'playerId' => $playerId, 'symbol' => 'X', 'room' => $room]);

        case 'join_room':
            $roomId = strtoupper(trim((string)($input['room_id'] ?? '')));
            if (!preg_match('/^[A-Z0-9]{4}$/', $roomId)) {
                json_response(['success' => false, 'error' => 'Salon introuvable']);
            }
            $guestName = mb_substr(trim((string)($input['player_name'] ?? 'Joueur 2')), 0, 20, 'UTF-8') ?: 'Joueur 2';
            $existingPlayerId = trim((string)($input['player_id'] ?? ''));
            $room = get_room($roomId);
            if (!$room) {
                json_response(['success' => false, 'error' => 'Salon introuvable']);
            }
            $now = time();
            foreach (['X' => 'playerX', 'O' => 'playerO'] as $symbol => $playerKey) {
                if ($existingPlayerId !== '' && ($room[$playerKey]['id'] ?? null) === $existingPlayerId) {
                    $room[$playerKey]['lastSeen'] = $now;
                    save_room($room);
                    json_response(['success' => true, 'roomId' => $roomId, 'playerId' => $existingPlayerId, 'symbol' => $symbol, 'room' => $room]);
                }
            }
            if ($room['playerO'] === null) {
                $playerId = bin2hex(random_bytes(16));
                $room['playerO'] = ['id' => $playerId, 'name' => $guestName, 'lastSeen' => $now];
                $room['status'] = 'playing';
                save_room($room);
                json_response(['success' => true, 'roomId' => $roomId, 'playerId' => $playerId, 'symbol' => 'O', 'room' => $room]);
            }
            json_response(['success' => false, 'error' => 'Ce salon est déjà complet (2/2 joueurs)']);

        case 'get_state':
            $roomId = strtoupper(trim((string)($input['room_id'] ?? '')));
            $playerId = trim((string)($input['player_id'] ?? ''));
            $room = get_room($roomId);
            if (!$room) {
                json_response(['success' => false, 'error' => 'Salon introuvable']);
            }
            $now = time();
            $opponentDisconnected = false;
            if ($room['status'] === 'playing') {
                if (($room['playerX']['id'] ?? null) === $playerId && $room['playerO'] && $now - $room['playerO']['lastSeen'] > 12) {
                    $opponentDisconnected = true;
                } elseif (($room['playerO']['id'] ?? null) === $playerId && $room['playerX'] && $now - $room['playerX']['lastSeen'] > 12) {
                    $opponentDisconnected = true;
                }
            }
            foreach (['playerX', 'playerO'] as $key) {
                if ($playerId !== '' && ($room[$key]['id'] ?? null) === $playerId) {
                    $room[$key]['lastSeen'] = $now;
                    save_room($room);
                }
            }
            $room['opponentDisconnected'] = $opponentDisconnected;
            json_response(['success' => true, 'room' => $room]);

        case 'play_move':
            $roomId = strtoupper(trim((string)($input['room_id'] ?? '')));
            $playerId = trim((string)($input['player_id'] ?? ''));
            $index = filter_var($input['index'] ?? null, FILTER_VALIDATE_INT);
            $room = get_room($roomId);
            if (!$room) {
                json_response(['success' => false, 'error' => 'Salon introuvable']);
            }
            $symbol = ($room['playerX']['id'] ?? null) === $playerId ? 'X' : ((($room['playerO']['id'] ?? null) === $playerId) ? 'O' : null);
            if ($symbol === null) {
                json_response(['success' => false, 'error' => 'Joueur non autorisé']);
            }
            if ($room['status'] !== 'playing') {
                json_response(['success' => false, 'error' => "La partie n'est pas en cours"]);
            }
            if ($room['currentTurn'] !== $symbol) {
                json_response(['success' => false, 'error' => "Ce n'est pas votre tour"]);
            }
            if ($index === false || $index < 0 || $index >= count($room['board']) || $room['board'][$index] !== null) {
                json_response(['success' => false, 'error' => 'Case invalide ou déjà occupée']);
            }
            $room['moveHistory'][] = ['index' => $index, 'symbol' => $symbol];
            $room['board'][$index] = $symbol;
            $room['rematchVotes'] = [];
            $win = check_board_winner($room['board'], (int)$room['size'], (int)$room['winLen']);
            if ($win !== null) {
                $room['status'] = 'finished';
                $room['winner'] = $win['winner'];
                $room['winningCombo'] = $win['combo'];
                if ($win['winner'] === 'X') {
                    $room['scores']['X']++;
                } elseif ($win['winner'] === 'O') {
                    $room['scores']['O']++;
                } else {
                    $room['scores']['ties']++;
                }
                if ($room['targetWins'] > 1) {
                    if ($room['scores']['X'] >= $room['targetWins']) {
                        $room['tournamentWinner'] = 'X';
                    } elseif ($room['scores']['O'] >= $room['targetWins']) {
                        $room['tournamentWinner'] = 'O';
                    }
                }
            } else {
                $room['currentTurn'] = $symbol === 'X' ? 'O' : 'X';
            }
            save_room($room);
            json_response(['success' => true, 'room' => $room]);

        case 'rematch':
            $roomId = strtoupper(trim((string)($input['room_id'] ?? '')));
            $playerId = trim((string)($input['player_id'] ?? ''));
            $room = get_room($roomId);
            if (!$room) {
                json_response(['success' => false, 'error' => 'Salon introuvable']);
            }
            $room['rematchVotes'] ??= [];
            if (!in_array($playerId, $room['rematchVotes'], true)) {
                $room['rematchVotes'][] = $playerId;
            }
            $bothVoted = count($room['rematchVotes']) >= 2 || ($room['playerO'] === null && count($room['rematchVotes']) >= 1);
            if ($bothVoted) {
                $nextStart = ($room['startingPlayer'] ?? 'X') === 'X' ? 'O' : 'X';
                $room['startingPlayer'] = $nextStart;
                $room['currentTurn'] = $nextStart;
                $room['board'] = array_fill(0, $room['size'] * $room['size'], null);
                $room['moveHistory'] = [];
                $room['status'] = 'playing';
                $room['winner'] = null;
                $room['winningCombo'] = [];
                $room['rematchVotes'] = [];
                if ($room['tournamentWinner'] !== null) {
                    $room['scores'] = ['X' => 0, 'O' => 0, 'ties' => 0];
                    $room['tournamentWinner'] = null;
                }
            }
            save_room($room);
            json_response(['success' => true, 'room' => $room, 'bothVoted' => $bothVoted]);

        case 'leave_room':
            $roomId = strtoupper(trim((string)($input['room_id'] ?? '')));
            $playerId = trim((string)($input['player_id'] ?? ''));
            $room = get_room($roomId);
            if ($room && in_array($playerId, [$room['playerX']['id'] ?? null, $room['playerO']['id'] ?? null], true)) {
                $room['status'] = 'abandoned';
                save_room($room);
                json_response(['success' => true]);
            }
            json_response(['success' => false]);

        case 'request_room_action':
            $roomId = strtoupper(trim((string)($input['room_id'] ?? '')));
            $playerId = trim((string)($input['player_id'] ?? ''));
            $operation = (string)($input['operation'] ?? '');
            $requestId = (string)($input['request_id'] ?? '');
            $requestedAction = (string)($input['requested_action'] ?? '');
            $allowed = ['undo', 'next_round', 'reset_all', 'menu', 'change_grid'];
            $requestedSize = (int)($input['size'] ?? 3) ?: 3;
            $approved = filter_var($input['approved'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $room = get_room($roomId);
            if (!$room) {
                json_response(['success' => false, 'error' => 'Salon introuvable']);
            }
            $isX = ($room['playerX']['id'] ?? null) === $playerId;
            $isO = ($room['playerO']['id'] ?? null) === $playerId;
            if (!$isX && !$isO) {
                json_response(['success' => false, 'error' => 'Joueur non autorisé']);
            }
            $now = time();
            if ($operation === 'request') {
                if ($room['pendingAction'] && $now - ($room['pendingAction']['createdAt'] ?? 0) > 30) {
                    $room['pendingAction'] = null;
                }
                if (!in_array($requestedAction, $allowed, true) || $room['pendingAction']) {
                    json_response(['success' => false, 'error' => 'Une autre demande est déjà en attente']);
                }
                if ($requestedAction === 'undo' && empty($room['moveHistory'])) {
                    json_response(['success' => false, 'error' => 'Aucun coup à annuler']);
                }
                $room['pendingAction'] = [
                    'id' => bin2hex(random_bytes(12)), 'action' => $requestedAction, 'requesterId' => $playerId,
                    'requesterName' => $isX ? $room['playerX']['name'] : $room['playerO']['name'],
                    'createdAt' => $now, 'size' => $requestedSize,
                ];
            } elseif ($operation === 'respond') {
                $pending = $room['pendingAction'];
                if (!$pending || $pending['id'] !== $requestId || $pending['requesterId'] === $playerId) {
                    json_response(['success' => false, 'error' => 'Demande introuvable ou non autorisée']);
                }
                if ($approved) {
                    apply_room_action($room, $pending['action']);
                    $room['lastActionResult'] = ['id' => $requestId, 'approved' => true, 'by' => $playerId];
                } else {
                    $room['pendingAction'] = null;
                    $room['lastActionResult'] = ['id' => $requestId, 'approved' => false, 'by' => $playerId];
                }
            } elseif ($operation === 'cancel') {
                $pending = $room['pendingAction'];
                if (!$pending || $pending['id'] !== $requestId || $pending['requesterId'] !== $playerId) {
                    json_response(['success' => false, 'error' => 'Cette demande ne peut pas être annulée']);
                }
                $room['pendingAction'] = null;
                $room['lastActionResult'] = ['id' => $requestId, 'approved' => false, 'cancelled' => true, 'by' => $playerId];
            } else {
                json_response(['success' => false, 'error' => 'Opération invalide']);
            }
            save_room($room);
            json_response(['success' => true, 'room' => $room]);

        default:
            json_response(['success' => false, 'error' => 'Action inconnue']);
    }
}

function render_maintenance_page(Database $db, ?array $user): never
{
    $message = $db->getSetting('maintenance_message', '') ?? '';
    if ($message === '') {
        $message = 'Multi-Games fait peau neuve. Le service revient très vite !';
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 1800');
    $safeMessage = e($message);
    $safeTheme = e(effective_theme($user));
    $isAdmin = $user && ($user['role'] ?? '') === 'admin';
    echo <<<HTML
<!DOCTYPE html>
<html lang="fr" data-theme="{$safeTheme}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Maintenance — Multi-Games</title>
<style>
:root { color-scheme: dark; }
* { box-sizing: border-box; }
body {
  margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem;
  background: radial-gradient(ellipse at 50% 0%, #1a080c 0%, #09090b 75%);
  color: #f4f4f5; font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
}
.card {
  width: min(34rem, 100%); padding: 2.5rem 2rem; text-align: center;
  border: 1px solid #27272a; border-radius: 1.4rem; background: #131317;
  box-shadow: 0 24px 70px rgba(0, 0, 0, .55);
}
.orb { font-size: 3rem; display: block; margin-bottom: .6rem; animation: pulse 2.4s ease-in-out infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: .55; transform: scale(.94); } }
@media (prefers-reduced-motion: reduce) { .orb { animation: none; } }
h1 { margin: 0 0 .5rem; font-size: 1.9rem; letter-spacing: -.02em; }
h1 span { color: #dc2626; }
p { color: #a1a1aa; margin: 0 0 1.6rem; }
.bar { height: 4px; border-radius: 999px; background: #27272a; overflow: hidden; margin-bottom: 1.6rem; }
.bar i { display: block; height: 100%; width: 40%; border-radius: 999px; background: #dc2626; animation: slide 1.8s ease-in-out infinite; }
@keyframes slide { 0% { transform: translateX(-110%); } 100% { transform: translateX(260%); } }
@media (prefers-reduced-motion: reduce) { .bar i { animation: none; width: 100%; } }
.actions { display: flex; flex-wrap: wrap; gap: .6rem; justify-content: center; }
a {
  display: inline-block; padding: .7rem 1.2rem; border: 1px solid #27272a; border-radius: .7rem;
  background: #0d0d10; color: #f4f4f5; text-decoration: none; font-size: .9rem;
}
a.primary { background: #dc2626; border-color: #dc2626; color: #fff; font-weight: 600; }
a:hover { border-color: #dc2626; }
small { display: block; margin-top: 1.6rem; color: #71717a; font-size: .78rem; }
</style>
</head>
<body>
  <div class="card">
    <span class="orb">🛠️</span>
    <h1>Multi-Games <span>en maintenance</span></h1>
    <div class="bar"><i></i></div>
    <p>{$safeMessage}</p>
    <div class="actions">
      <a class="primary" href="index.php">Réessayer</a>
      <a href="account.php">Mon compte</a>
      <a href="space-core/">Space Core</a>
    </div>
    <small>Les administrateurs conservent un accès complet au panneau.</small>
  </div>
</body>
</html>
HTML;
    if ($isAdmin) {
        // Les admins ne devraient pas atteindre cette page : on les renvoie au panneau.
    }
    exit;
}

function require_admin_api(?array $user): void
{
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        json_response(['success' => false, 'error' => 'Accès administrateur requis.'], 403);
    }
}

function admin_theme_payload(array $themes): array
{
    return array_map(static fn(array $theme): array => [
        'id' => $theme['id'], 'key' => $theme['theme_key'], 'name' => $theme['name'],
        'primary_color' => $theme['primary_color'], 'background_color' => $theme['background_color'],
        'custom' => $theme['custom'],
    ], $themes);
}

/**
 * Ordonne la liste des joueurs par activité récente : c'est l'ordre par défaut
 * du tableau de bord, donc le rendu serveur et le rendu JS restent identiques.
 */
function admin_player_payload(array $players): array
{
    usort($players, static fn(array $a, array $b): int => ($b['last_seen_at'] <=> $a['last_seen_at']) ?: ($b['id'] <=> $a['id']));
    return $players;
}

function require_admin_csrf(?array $user): void
{
    require_admin_api($user);
    if (!verify_csrf()) {
        json_response(['success' => false, 'error' => 'Jeton de sécurité invalide.'], 403);
    }
}

try {
    $dataDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'data';
    $db = new Database($dataDirectory);

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 7 * 24 * 3600,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (!isset($_SESSION['csrfToken']) || !is_string($_SESSION['csrfToken'])) {
        $_SESSION['csrfToken'] = bin2hex(random_bytes(32));
    }

    $user = current_user($db);
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $basePath = app_base_path();
    if ($basePath !== '' && $basePath !== '/' && str_starts_with($requestPath, $basePath . '/')) {
        $requestPath = substr($requestPath, strlen($basePath));
    } elseif ($basePath !== '' && $requestPath === $basePath) {
        $requestPath = '/';
    }
    $requestPath = '/' . ltrim($requestPath, '/');
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $input = array_merge($_GET, request_data());

    // Mode maintenance : le site public est fermé, mais l'administration et la
    // connexion restent accessibles pour intervenir.
    if ($db->isMaintenanceEnabled()) {
        $isAdminUser = $user && ($user['role'] ?? '') === 'admin';
        $maintenanceExempt = in_array($requestPath, ['/admin', '/admin.php', '/account', '/account.php'], true);
        if (!$maintenanceExempt && !$isAdminUser) {
            if (str_contains($requestPath, 'api')) {
                json_response(['success' => false, 'error' => 'Multi-Games est en maintenance. Reviens très vite !', 'maintenance' => true], 503);
            }
            render_maintenance_page($db, $user);
        }
    }

    if (in_array($requestPath, ['/', '/index.php'], true) && $method === 'GET') {
        $games = [
            ['id' => 'morpion', 'name' => 'Morpion by Space', 'desc' => "3x3 Classique ou 5 à aligner Gomoku. 1v1 Local, 5 niveaux d'IA ou Défi en Wi-Fi LAN.", 'url' => 'games/morpion/index.php', 'icon' => '✕ ○', 'badge' => 'Disponible ⚡', 'players' => '1 - 2 Joueurs', 'active' => true],
            ['id' => 'connect4', 'name' => 'Puissance 4', 'desc' => 'Faites glisser vos pions et alignez-en 4 pour remporter la manche PvP.', 'url' => '#', 'icon' => '🔴 🟡', 'badge' => 'En développement', 'players' => '2 Joueurs', 'active' => false],
            ['id' => 'pong', 'name' => 'Cyber Pong Duel', 'desc' => "L'arcade légendaire revisitée avec effets lumineux et physique ultra rapide.", 'url' => '#', 'icon' => '🏓 ⚡', 'badge' => 'Bientôt', 'players' => '2 Joueurs', 'active' => false],
            ['id' => 'naval', 'name' => 'Bataille Navale LAN', 'desc' => 'Placez votre flotte secrète et coulez les navires adverses sur le réseau Wi-Fi.', 'url' => '#', 'icon' => '🚢 💥', 'badge' => 'Bientôt', 'players' => '2 Joueurs', 'active' => false],
        ];
        render_view('index', [
            'user' => $user, 'gameThemes' => $db->getThemes(), 'customThemeCss' => $db->getCustomThemeCss(),
            'currentTheme' => effective_theme($user), 'csrfToken' => $_SESSION['csrfToken'], 'games' => $games,
        ]);
    }

    if (in_array($requestPath, ['/games/morpion', '/games/morpion/', '/games/morpion/index.php'], true) && $method === 'GET') {
        if ($requestPath === '/games/morpion') {
            redirect_to('/games/morpion/', 301);
        }
        $requested = filter_var($_GET['grid_size'] ?? null, FILTER_VALIDATE_INT);
        $requestedGridSize = in_array($requested, [3, 5, 10, 12, 15, 19], true) ? $requested : null;
        $host = explode(':', $_SERVER['HTTP_HOST'] ?? '127.0.0.1')[0];
        render_view('morpion', [
            'user' => $user, 'gameThemes' => $db->getThemes(), 'gameSymbols' => $db->getSymbols(),
            'customThemeCss' => $db->getCustomThemeCss(), 'currentTheme' => effective_theme($user),
            'csrfToken' => $_SESSION['csrfToken'], 'requestedGridSize' => $requestedGridSize,
            'playerDisplayName' => $user['display_name'] ?? 'Joueur 1',
            'lanBaseUrl' => 'games/morpion/', 'serverIp' => $host,
        ]);
    }

    if (in_array($requestPath, ['/account', '/account.php'], true) && $method === 'GET') {
        render_account_page($db, $user);
    }

    if ($requestPath === '/account.php' && $method === 'POST') {
        $action = (string)($input['action'] ?? '');
        if (!verify_csrf()) {
            render_account_page($db, $user, 'La session du formulaire a expiré. Rechargez la page et réessayez.');
        }
        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrfToken'] = bin2hex(random_bytes(32));
            redirect_to('account.php');
        }
        if ($action === 'login') {
            $email = strtolower(trim((string)($input['email'] ?? '')));
            $password = (string)($input['password'] ?? '');
            $now = time();
            $attempts = $_SESSION['loginAttempts'] ?? ['count' => 0, 'startedAt' => $now];
            if ($now - $attempts['startedAt'] > 900) {
                $attempts = ['count' => 0, 'startedAt' => $now];
            }
            if ($attempts['count'] >= 8) {
                render_account_page($db, $user, 'Trop de tentatives. Attendez 15 minutes avant de réessayer.');
            }
            $loginUser = $db->getUserByEmail($email);
            if ($loginUser && !$loginUser['is_banned'] && password_verify($password, $loginUser['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['userId'] = $loginUser['id'];
                $_SESSION['username'] = $loginUser['display_name'];
                $_SESSION['csrfToken'] = bin2hex(random_bytes(32));
                unset($_SESSION['loginAttempts']);
                redirect_to($loginUser['role'] === 'admin' ? 'admin.php' : 'account.php');
            }
            $attempts['count']++;
            $_SESSION['loginAttempts'] = $attempts;
            render_account_page($db, $user, 'Adresse e-mail ou mot de passe incorrect.');
        }
        if ($action === 'register') {
            $name = trim((string)($input['display_name'] ?? ''));
            $email = strtolower(trim((string)($input['email'] ?? '')));
            $password = (string)($input['password'] ?? '');
            $avatar = trim((string)($input['avatar'] ?? '🚀'));
            if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 30) {
                render_account_page($db, $user, 'Le pseudo doit contenir entre 2 et 30 caractères.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
                render_account_page($db, $user, 'Entrez une adresse e-mail valide.');
            }
            if (strlen($password) < 10) {
                render_account_page($db, $user, 'Choisissez un mot de passe d’au moins 10 caractères.');
            }
            if ($email === 'spacedev@thegoat.com') {
                render_account_page($db, $user, 'Cette adresse e-mail est réservée.');
            }
            if (!in_array($avatar, Database::AVAILABLE_AVATARS, true)) {
                render_account_page($db, $user, 'Veuillez sélectionner un avatar valide.');
            }
            if (!$db->isDisplayNameAvailable($name)) {
                render_account_page($db, $user, 'Ce pseudo est déjà utilisé. Choisissez-en un autre.');
            }
            if ($db->getUserByEmail($email)) {
                render_account_page($db, $user, 'Un compte existe déjà avec cette adresse e-mail.');
            }
            $newUser = $db->createUser($email, $name, password_hash($password, PASSWORD_DEFAULT), 'player', $avatar, 'red');
            session_regenerate_id(true);
            $_SESSION['userId'] = $newUser['id'];
            $_SESSION['username'] = $newUser['display_name'];
            $_SESSION['csrfToken'] = bin2hex(random_bytes(32));
            redirect_to('account.php');
        }
        if (!$user) {
            render_account_page($db, null, 'Veuillez vous connecter pour modifier votre compte.');
        }
        if ($action === 'update_profile' || $action === 'update_username') {
            $name = trim((string)($input['display_name'] ?? ''));
            if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 30) {
                render_account_page($db, $user, 'Le pseudo doit contenir entre 2 et 30 caractères.');
            }
            if (!$db->isDisplayNameAvailable($name, (int)$user['id'])) {
                render_account_page($db, $user, 'Ce pseudo est déjà utilisé. Choisissez-en un autre.');
            }
            $db->updateUser($user['id'], ['display_name' => $name]);
            $_SESSION['username'] = $name;
            $user['display_name'] = $name;
            render_account_page($db, $user, '', 'Votre pseudo a été mis à jour avec succès !');
        }
        if ($action === 'update_email') {
            $email = strtolower(trim((string)($input['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
                render_account_page($db, $user, 'Veuillez entrer une adresse e-mail valide.');
            }
            if ($email === 'spacedev@thegoat.com' && $user['role'] !== 'admin') {
                render_account_page($db, $user, 'Cette adresse e-mail est réservée.');
            }
            $existing = $db->getUserByEmail($email);
            if ($existing && $existing['id'] !== $user['id']) {
                render_account_page($db, $user, 'Cette adresse e-mail est déjà utilisée par un autre compte.');
            }
            $db->updateUser($user['id'], ['email' => $email]);
            $user['email'] = $email;
            render_account_page($db, $user, '', 'Votre adresse e-mail a été mise à jour avec succès !');
        }
        if ($action === 'update_password') {
            $current = (string)($input['current_password'] ?? '');
            $password = (string)($input['new_password'] ?? '');
            $confirm = (string)($input['confirm_password'] ?? '');
            if (!password_verify($current, $user['password_hash'])) {
                render_account_page($db, $user, 'Le mot de passe actuel est incorrect.');
            }
            if (strlen($password) < 10) {
                render_account_page($db, $user, 'Le nouveau mot de passe doit comporter au moins 10 caractères.');
            }
            if ($password !== $confirm) {
                render_account_page($db, $user, 'La confirmation ne correspond pas au nouveau mot de passe.');
            }
            $db->updateUser($user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            render_account_page($db, $user, '', 'Votre mot de passe a été modifié avec succès !');
        }
        if ($action === 'update_avatar') {
            $avatar = trim((string)($input['avatar'] ?? ''));
            if (!in_array($avatar, Database::AVAILABLE_AVATARS, true)) {
                render_account_page($db, $user, 'Veuillez sélectionner un avatar valide.');
            }
            $db->updateUser($user['id'], ['avatar' => $avatar]);
            $user['avatar'] = $avatar;
            render_account_page($db, $user, '', 'Avatar mis à jour avec succès !');
        }
        if ($action === 'update_theme') {
            $theme = strtolower(trim((string)($input['theme_key'] ?? 'red')));
            $keys = array_column($db->getThemes(), 'theme_key');
            if (!in_array($theme, $keys, true)) {
                render_account_page($db, $user, 'Veuillez sélectionner un thème valide.');
            }
            $db->updateUser($user['id'], ['theme_preference' => $theme]);
            $user['theme_preference'] = $theme;
            setcookie('mg_theme', $theme, ['expires' => time() + 365 * 24 * 3600, 'path' => '/', 'httponly' => false, 'samesite' => 'Lax', 'secure' => $https]);
            render_account_page($db, $user, '', 'Thème préféré enregistré sur votre profil !');
        }
        render_account_page($db, $user, 'Action inconnue.');
    }

    if (in_array($requestPath, ['/profile', '/profile.php'], true) && $method === 'POST') {
        if (!$user) {
            redirect_to('account.php');
        }
        if (!verify_csrf()) {
            redirect_to('profile.php?error=' . rawurlencode('La session du formulaire a expiré. Rechargez la page et réessayez.'));
        }
        $name = trim((string)($input['display_name'] ?? ''));
        $username = strtolower(trim((string)($input['username'] ?? '')));
        $bio = trim(strip_tags((string)($input['bio'] ?? '')));
        $avatar = (string)($input['avatar'] ?? '');
        $visibility = (string)($input['profile_visibility'] ?? '');
        $favoriteGame = (string)($input['favorite_game'] ?? '');
        if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 30) {
            redirect_to('profile.php?error=' . rawurlencode('Le pseudo doit contenir entre 2 et 30 caractères.'));
        }
        if (!$db->isDisplayNameAvailable($name, (int)$user['id'])) {
            redirect_to('profile.php?error=' . rawurlencode('Ce pseudo est déjà utilisé.'));
        }
        if (!preg_match('/^[a-z0-9_]{3,20}$/', $username)) {
            redirect_to('profile.php?error=' . rawurlencode('Le nom public doit contenir de 3 à 20 lettres, chiffres ou tirets bas.'));
        }
        if (!$db->isUsernameAvailable($username, (int)$user['id'])) {
            redirect_to('profile.php?error=' . rawurlencode('Ce nom public est déjà utilisé.'));
        }
        if (!in_array($avatar, Database::AVAILABLE_AVATARS, true) ||
            !in_array($visibility, ['public', 'friends', 'private'], true) ||
            ($favoriteGame !== '' && !in_array($favoriteGame, ['morpion', 'connect4', 'pong', 'naval'], true))) {
            redirect_to('profile.php?error=' . rawurlencode('Une valeur de profil est invalide.'));
        }
        if (mb_strlen($bio, 'UTF-8') > 280) {
            redirect_to('profile.php?error=' . rawurlencode('La bio ne peut pas dépasser 280 caractères.'));
        }
        $db->updateUser((int)$user['id'], [
            'display_name' => $name,
            'username' => $username,
            'bio' => $bio,
            'avatar' => $avatar,
            'profile_visibility' => $visibility,
            'show_online' => isset($input['show_online']) ? 1 : 0,
            'favorite_game' => $favoriteGame !== '' ? $favoriteGame : null,
        ]);
        $db->logEvent('social', $name . ' a modifié son profil public', [
            'username' => $username, 'visibility' => $visibility,
        ], (int)$user['id'], $name, 'info');
        $_SESSION['username'] = $name;
        redirect_to('profile.php?saved=1');
    }

    if (in_array($requestPath, ['/profile', '/profile.php'], true) && $method === 'GET') {
        $requestedUsername = trim((string)($_GET['u'] ?? ''));
        if ($requestedUsername === '') {
            if (!$user) {
                redirect_to('account.php');
            }
            $requestedUsername = (string)$user['username'];
        }
        $profile = $db->getPublicProfile($requestedUsername, $user ? (int)$user['id'] : null);
        if (!$profile) {
            http_response_code(404);
            render_view('profile', [
                'user' => $user, 'profile' => null, 'csrfToken' => $_SESSION['csrfToken'],
                'customThemeCss' => $db->getCustomThemeCss(), 'currentTheme' => effective_theme($user),
                'friends' => [], 'friendRequests' => [], 'friendshipState' => 'none',
                'error' => 'Ce profil est introuvable.',
            ]);
        }
        $isOwnProfile = !empty($profile['is_owner_profile']);
        render_view('profile', [
            'user' => $user, 'profile' => $profile, 'csrfToken' => $_SESSION['csrfToken'],
            'customThemeCss' => $db->getCustomThemeCss(), 'currentTheme' => effective_theme($user),
            'friends' => $isOwnProfile ? $db->listFriends((int)$profile['id']) : [],
            'friendRequests' => $isOwnProfile ? $db->listFriendRequests((int)$profile['id']) : [],
            'friendOutgoingRequests' => $isOwnProfile ? $db->listOutgoingFriendRequests((int)$profile['id']) : [],
            'friendshipState' => $user && !$isOwnProfile ? $db->getFriendshipState((int)$user['id'], (int)$profile['id']) : 'none',
            'error' => (string)($_GET['error'] ?? ''),
            'saved' => (string)($_GET['saved'] ?? '') === '1',
        ]);
    }

    if (in_array($requestPath, ['/leaderboard', '/leaderboard.php'], true) && $method === 'GET') {
        render_view('leaderboard', [
            'user' => $user, 'csrfToken' => $_SESSION['csrfToken'], 'customThemeCss' => $db->getCustomThemeCss(),
            'currentTheme' => effective_theme($user), 'leaderboard' => $db->getLeaderboard(50, 'morpion', $user ? (int)$user['id'] : null),
            'userRank' => $user ? $db->getUserRank($user['id']) : null,
            'userStats' => $user ? $db->getUserDetailedStats($user['id']) : null,
        ]);
    }

    if ($requestPath === '/api/leaderboard' && $method === 'GET') {
        $limit = min(100, max(5, (int)($_GET['limit'] ?? 50) ?: 50));
        $gameId = (string)($_GET['game_id'] ?? 'morpion');
        if (!in_array($gameId, ['morpion', 'connect4', 'pong', 'naval'], true)) {
            json_response(['success' => false, 'error' => 'Jeu de classement invalide.'], 400);
        }
        json_response(['success' => true, 'leaderboard' => $db->getLeaderboard($limit, $gameId, $user ? (int)$user['id'] : null)]);
    }

    if ($requestPath === '/api/social') {
        if ($method !== 'POST') {
            json_response(['success' => false, 'error' => 'Méthode non autorisée.'], 405);
        }
        api_social($db, $user, $input);
    }

    if (in_array($requestPath, ['/admin', '/admin.php'], true) && $method === 'GET') {
        if (!$user || $user['role'] !== 'admin') {
            redirect_to('account.php');
        }
        // Rendu initial complet : la page est utilisable immédiatement, sans
        // attendre le premier appel API (aucun état « Chargement… » visible).
        render_view('admin', [
            'user' => $user, 'csrfToken' => $_SESSION['csrfToken'], 'customThemeCss' => $db->getCustomThemeCss(),
            'currentTheme' => effective_theme($user), 'summary' => $db->getSummaryStats(),
            'botLevels' => $db->getBotLevelsStats(), 'players' => admin_player_payload($db->getPlayersAdminList()),
            'topPlayer' => $db->getTopPlayer('morpion'),
            'rankDistribution' => $db->getRankDistribution('morpion'),
            'popularModes' => $db->getPopularModes(), 'themes' => admin_theme_payload($db->getThemes()),
            'symbols' => $db->getSymbols(), 'rooms' => list_all_rooms(), 'now' => time(),
            'spaceKnowledge' => $db->listSpaceKnowledge(),
            'spaceUsage' => $db->getSpaceUsage(365),
            'groqConfigured' => is_string(getenv('GROQ_API_KEY')) && trim((string)getenv('GROQ_API_KEY')) !== '',
        ]);
    }

    if (in_array($requestPath, ['/space-core', '/space-core/', '/space-core/index.php'], true) && $method === 'GET') {
        if ($requestPath === '/space-core') {
            redirect_to('/space-core/', 301);
        }
        header('Cache-Control: no-store, no-cache, must-revalidate');
        render_view('space', [
            'csrfToken' => $_SESSION['csrfToken'],
            'currentTheme' => effective_theme($user),
            // Nécessaires pour que les thèmes personnalisés créés depuis l'admin
            // s'appliquent aussi à Space Core.
            'customThemeCss' => $db->getCustomThemeCss(),
            'user' => $user,
        ]);
    }

    if (in_array($requestPath, ['/space-core/api_space.php', '/api_space.php', '/api/space'], true) && $method === 'POST') {
        $clientCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
        if (!verify_csrf(is_string($clientCsrf) ? $clientCsrf : null)) {
            json_response(['error' => 'Session expirée. Recharge la page.'], 403);
        }
        $context = [
            'user_id' => $user['id'] ?? null,
            'username' => $user['display_name'] ?? 'Invité',
            'role' => $user['role'] ?? 'guest',
            'user_stats' => $user ? $db->getUserGameStats($user['id']) : null,
        ];
        $scope = (string)($user['id'] ?? 'guest');
        if (($_SESSION['spaceScope'] ?? null) !== $scope) {
            $_SESSION['spaceHistory'] = [];
            $_SESSION['spaceScope'] = $scope;
        }
        $operation = (string)($input['operation'] ?? 'chat');
        $userId = $user ? (int)$user['id'] : null;

        // Les comptes connectés stockent leurs discussions en base ; les invités
        // conservent leur archive dans le navigateur et transmettent le contexte récent.
        $conversationId = filter_var($input['conversation_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        if ($conversationId !== null && $userId !== null && !$db->conversationBelongsTo((int)$conversationId, $userId)) {
            json_response(['error' => 'Conversation introuvable.'], 404);
        }

        $listConversations = static function () use ($db, $userId): array {
            return $userId === null ? [] : $db->listConversations($userId);
        };

        if ($operation === 'list') {
            json_response(['success' => true, 'conversations' => $listConversations()]);
        }

        if ($operation === 'new') {
            $id = $userId !== null ? $db->createConversation($userId, 'Nouvelle discussion') : null;
            json_response(['success' => true, 'conversation_id' => $id, 'conversations' => $listConversations()]);
        }

        if ($operation === 'load') {
            if ($conversationId === null || $userId === null) {
                json_response(['success' => true, 'messages' => [], 'conversation_id' => null]);
            }
            json_response([
                'success' => true,
                'conversation_id' => (int)$conversationId,
                'messages' => $db->getConversationMessages((int)$conversationId),
            ]);
        }

        if ($operation === 'rename') {
            if ($conversationId === null || $userId === null) {
                json_response(['error' => 'Conversation introuvable.'], 404);
            }
            $db->renameConversation((int)$conversationId, (string)($input['title'] ?? 'Discussion'));
            json_response(['success' => true, 'conversations' => $listConversations()]);
        }

        if ($operation === 'delete') {
            if ($conversationId === null || $userId === null) {
                json_response(['error' => 'Conversation introuvable.'], 404);
            }
            $db->deleteConversation((int)$conversationId, $userId);
            json_response(['success' => true, 'conversations' => $listConversations()]);
        }

        if ($operation === 'clear') {
            $_SESSION['spaceHistory'] = [];
            $id = $userId !== null ? $db->createConversation($userId, 'Nouvelle discussion') : null;
            json_response([
                'success' => true,
                'text' => 'Nouvelle discussion ouverte.',
                'action' => null,
                'conversation_id' => $id,
                'conversations' => $listConversations(),
            ]);
        }

        if ($operation === 'hello') {
            if ($userId !== null && $conversationId === null) {
                $existing = $db->listConversations($userId, 1);
                if ($existing) {
                    $conversationId = (int)$existing[0]['id'];
                } else {
                    $conversationId = $db->createConversation($userId, 'Première discussion');
                    $db->appendConversationMessage($conversationId, 'assistant', 'Bienvenue ' . $context['username'] . ' ! Je suis Space. On joue ou on discute ?');
                }
            }
            $messages = $conversationId !== null && $userId !== null
                ? $db->getConversationMessages((int)$conversationId)
                : ($userId === null ? ($_SESSION['spaceHistory'] ?? []) : []);
            json_response([
                'success' => true,
                'text' => 'Bienvenue ' . $context['username'] . ' ! Je suis Space. On joue ou on discute ?',
                'action' => null,
                'conversation_id' => $conversationId,
                'messages' => $messages,
                'conversations' => $listConversations(),
                'ai_enabled' => $db->isAiEnabled(),
                'ai_blocked' => $user ? (bool)$user['ai_blocked'] : false,
            ]);
        }

        $message = $input['message'] ?? null;
        if (!is_string($message) || trim($message) === '' || mb_strlen($message, 'UTF-8') > 4000) {
            json_response(['error' => 'Écris un message de 4 000 caractères maximum.'], 400);
        }

        // Contrôle global de l'IA (panneau admin) et blocage individuel.
        if (!$db->isAiEnabled()) {
            json_response(['success' => true, 'text' => 'Space Core est momentanément désactivé par l’administrateur. Réessaie plus tard !', 'action' => null, 'conversation_id' => $conversationId]);
        }
        if ($user && !empty($user['ai_blocked'])) {
            json_response(['success' => true, 'text' => 'Ton accès à Space Core a été suspendu par un administrateur.', 'action' => null, 'conversation_id' => $conversationId]);
        }

        if ($userId !== null && $conversationId === null) {
            $conversationId = $db->createConversation($userId, mb_substr(trim($message), 0, 60, 'UTF-8'));
        }

        if ($conversationId !== null && $userId !== null) {
            $history = $db->getConversationHistory((int)$conversationId, 12);
        } elseif ($userId === null && array_key_exists('history', $input)) {
            $providedHistory = $input['history'];
            if (!is_array($providedHistory) || !array_is_list($providedHistory) || count($providedHistory) > 12) {
                json_response(['error' => 'Historique de discussion invalide.'], 400);
            }
            $history = [];
            foreach ($providedHistory as $historyMessage) {
                if (
                    !is_array($historyMessage) ||
                    !in_array($historyMessage['role'] ?? null, ['user', 'assistant'], true) ||
                    !is_string($historyMessage['content'] ?? null) ||
                    mb_strlen($historyMessage['content'], 'UTF-8') > 4000
                ) {
                    json_response(['error' => 'Historique de discussion invalide.'], 400);
                }
                $history[] = [
                    'role' => $historyMessage['role'],
                    'content' => $historyMessage['content'],
                ];
            }
        } else {
            $history = $_SESSION['spaceHistory'] ?? [];
        }

        $learnFromChat = ($input['learn_from_chat'] ?? false) === true;
        $result = ask_space($db, $message, $context, $history, $learnFromChat);

        if ($conversationId !== null && $userId !== null) {
            $db->appendConversationMessage((int)$conversationId, 'user', $message);
            $db->appendConversationMessage((int)$conversationId, 'assistant', $result['text']);
            $existing = $db->getConversationMessages((int)$conversationId, 200);
            if (count($existing) === 2) {
                $db->renameConversation((int)$conversationId, mb_substr(trim($message), 0, 60, 'UTF-8'));
            }
        } else {
            $history[] = ['role' => 'user', 'content' => $message];
            $history[] = ['role' => 'assistant', 'content' => $result['text']];
            $_SESSION['spaceHistory'] = array_slice($history, -16);
        }

        $db->logEvent('space', 'Interaction avec Space', [
            'length' => mb_strlen($message, 'UTF-8'),
            'action' => $result['action']['action'] ?? null,
        ], null, null, 'info');

        json_response($result + [
            'success' => true,
            'conversation_id' => $conversationId,
            'conversations' => $listConversations(),
        ]);
    }

    if (in_array($requestPath, ['/games/morpion/api.php', '/api/morpion', '/api.php'], true)) {
        api_morpion($db, $user, $input);
    }

    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Page introuvable.';
} catch (Throwable $error) {
    error_log('Multi-Games PHP error: ' . $error);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
        str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') ||
        str_contains(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/api')) {
        json_response(['success' => false, 'error' => 'Une erreur serveur est survenue.'], 500);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Une erreur serveur est survenue. Consultez les journaux PHP pour plus de détails.';
}
