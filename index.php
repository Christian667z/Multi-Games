<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'db.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'rooms.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'space.php';

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
    $leaderboard = $db->getLeaderboard(10);
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
        'availableAvatars' => ['🚀', '⚡', '🤖', '👑', '🐺', '🦊', '🐉', '🎮'],
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
            if (!$user || $user['role'] !== 'admin') {
                json_response(['success' => false, 'error' => 'Accès administrateur requis.'], 403);
            }
            json_response([
                'success' => true,
                'summary' => $db->getSummaryStats(),
                'bot_levels' => $db->getBotLevelsStats(),
                'popular_modes' => $db->getPopularModes(),
                'players' => $db->getPlayersAdminList(),
                'themes' => array_map(static fn(array $theme): array => [
                    'id' => $theme['id'], 'key' => $theme['theme_key'], 'name' => $theme['name'],
                    'primary_color' => $theme['primary_color'], 'background_color' => $theme['background_color'],
                    'custom' => $theme['custom'],
                ], $db->getThemes()),
                'symbols' => $db->getSymbols(),
            ]);

        case 'admin_rooms':
            if (!$user || $user['role'] !== 'admin') {
                json_response(['success' => false, 'error' => 'Accès administrateur requis.'], 403);
            }
            json_response(['success' => true, 'rooms' => list_all_rooms()]);

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
            $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
            $operation = (string)($input['operation'] ?? '');
            if (!$userId || $userId === $user['id']) {
                json_response(['success' => false, 'error' => 'Compte cible invalide.'], 400);
            }
            $target = $db->getUserById($userId);
            if (!$target || $target['role'] === 'admin') {
                json_response(['success' => false, 'error' => 'Ce compte ne peut pas être modifié ici.'], 403);
            }
            if ($operation === 'delete') {
                $db->deleteUser($userId);
            } elseif ($operation === 'ban' || $operation === 'unban') {
                $db->updateUser($userId, ['is_banned' => $operation === 'ban' ? 1 : 0]);
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
                $db->updateUser($userId, ['display_name' => $name, 'email' => $email]);
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

        case 'presence':
            if (!$user) {
                json_response(['success' => true, 'authenticated' => false]);
            }
            if (!verify_csrf()) {
                json_response(['success' => false, 'error' => 'Jeton de sécurité invalide.'], 403);
            }
            $db->updateUser($user['id'], ['last_seen_at' => time()]);
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
            $size = filter_var($input['board_size'] ?? 3, FILTER_VALIDATE_INT) ?: 3;
            if (!in_array($mode, ['local', 'bot', 'wifi'], true) || !in_array($size, [3, 5, 10, 12, 15, 19], true)) {
                json_response(['success' => false, 'error' => 'Paramètres de partie invalides.'], 400);
            }
            $gameId = bin2hex(random_bytes(16));
            $db->createSession([
                'id' => $gameId,
                'session_key' => session_id() ?: bin2hex(random_bytes(8)),
                'user_id' => $user['id'] ?? null,
                'mode' => $mode,
                'bot_level' => $mode === 'bot' ? (string)($input['bot_level'] ?? 'medium') : null,
                'board_size' => $size,
                'result' => null,
                'started_at' => time(),
                'completed_at' => null,
            ]);
            json_response(['success' => true, 'game_id' => $gameId]);

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
            if ($game['completed_at'] !== null) {
                json_response(['success' => true, 'already_recorded' => true]);
            }
            $db->updateSession($gameId, ['result' => $winner, 'completed_at' => time()]);
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
            json_response(['success' => true]);

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

function require_admin_csrf(?array $user): void
{
    if (!$user || $user['role'] !== 'admin') {
        json_response(['success' => false, 'error' => 'Accès administrateur requis.'], 403);
    }
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

    if (in_array($requestPath, ['/dev/livereload.php'], true) && $method === 'GET') {
        header('Cache-Control: no-cache, no-store, must-revalidate');
        json_response(['timestamp' => time(), 'latestFile' => 'index.php', 'time' => date('H:i:s')]);
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
            if ($avatar === '' || mb_strlen($avatar, 'UTF-8') > 8) {
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

    if (in_array($requestPath, ['/leaderboard', '/leaderboard.php'], true) && $method === 'GET') {
        render_view('leaderboard', [
            'user' => $user, 'csrfToken' => $_SESSION['csrfToken'], 'customThemeCss' => $db->getCustomThemeCss(),
            'currentTheme' => effective_theme($user), 'leaderboard' => $db->getLeaderboard(50),
            'userRank' => $user ? $db->getUserRank($user['id']) : null,
            'userStats' => $user ? $db->getUserDetailedStats($user['id']) : null,
        ]);
    }

    if ($requestPath === '/api/leaderboard' && $method === 'GET') {
        $limit = min(100, max(5, (int)($_GET['limit'] ?? 50) ?: 50));
        json_response(['success' => true, 'leaderboard' => $db->getLeaderboard($limit)]);
    }

    if (in_array($requestPath, ['/admin', '/admin.php'], true) && $method === 'GET') {
        if (!$user || $user['role'] !== 'admin') {
            redirect_to('account.php');
        }
        render_view('admin', [
            'user' => $user, 'csrfToken' => $_SESSION['csrfToken'], 'customThemeCss' => $db->getCustomThemeCss(),
            'currentTheme' => effective_theme($user), 'summary' => $db->getSummaryStats(),
            'botLevels' => $db->getBotLevelsStats(), 'players' => $db->getPlayersAdminList(), 'now' => time(),
        ]);
    }

    if (in_array($requestPath, ['/space-core', '/space-core/', '/space-core/index.php'], true) && $method === 'GET') {
        if ($requestPath === '/space-core') {
            redirect_to('/space-core/', 301);
        }
        render_view('space', ['csrfToken' => $_SESSION['csrfToken'], 'currentTheme' => effective_theme($user)]);
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
        $operation = $input['operation'] ?? null;
        if ($operation === 'clear') {
            $_SESSION['spaceHistory'] = [];
            json_response(['text' => 'Nouvelle discussion ouverte.', 'action' => null]);
        }
        if ($operation === 'hello') {
            json_response(['text' => 'Bienvenue ' . $context['username'] . ' ! Je suis Space. On joue ou on discute ?', 'action' => null]);
        }
        $message = $input['message'] ?? null;
        if (!is_string($message) || trim($message) === '' || mb_strlen($message, 'UTF-8') > 4000) {
            json_response(['error' => 'Écris un message de 4 000 caractères maximum.'], 400);
        }
        $history = $_SESSION['spaceHistory'] ?? [];
        $result = ask_space($message, $context, $history);
        $history[] = ['role' => 'user', 'content' => $message];
        $history[] = ['role' => 'assistant', 'content' => $result['text']];
        $_SESSION['spaceHistory'] = array_slice($history, -16);
        json_response($result);
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
