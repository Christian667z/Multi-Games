<?php
/**
 * Multi-Games - API Multijoueur Wi-Fi / Réseau Local Sécurisée & Robuste
 * Concurrence gérée avec flock(), validation stricte, reconnexion et tournoi
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
require_once dirname(dirname(__DIR__)) . '/includes/app.php';
mg_start_session();

function sendApiResponse(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$roomsDir = dirname(dirname(__DIR__)) . '/data/rooms';
if (!is_dir($roomsDir)) {
    @mkdir($roomsDir, 0777, true);
}

// Nettoyage automatique des salons inactifs (> 1 heure)
function cleanOldRooms($dir) {
    if ($handle = opendir($dir)) {
        $now = time();
        while (false !== ($entry = readdir($handle))) {
            if (str_ends_with($entry, '.json')) {
                $file = $dir . '/' . $entry;
                if ($now - filemtime($file) > 3600) {
                    @unlink($file);
                }
            }
        }
        closedir($handle);
    }
}
cleanOldRooms($roomsDir);

function getRoomFilePath($roomId, $roomsDir) {
    $safeId = preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId));
    return $roomsDir . '/' . $safeId . '.json';
}

// Opération atomique sécurisée avec verrouillage exclusif flock()
function withRoomLock($roomId, $roomsDir, callable $callback) {
    $filePath = getRoomFilePath($roomId, $roomsDir);
    if (!file_exists($filePath)) {
        return ['success' => false, 'error' => 'Salon introuvable'];
    }

    $fp = fopen($filePath, 'c+');
    if (!$fp) {
        return ['success' => false, 'error' => 'Erreur d\'ouverture du fichier de salon'];
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return ['success' => false, 'error' => 'Salon momentanément occupé'];
    }

    $filesize = filesize($filePath);
    $content = $filesize > 0 ? fread($fp, $filesize) : '{}';
    $roomData = json_decode($content, true);

    if (!$roomData) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return ['success' => false, 'error' => 'Données du salon corrompues'];
    }

    $result = $callback($roomData);

    if (isset($result['save']) && $result['save'] === true) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($roomData, JSON_PRETTY_PRINT));
        fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    return $result['response'] ?? ['success' => true, 'room' => $roomData];
}

function checkBoardWinner($board, $size = 3, $winLen = 3) {
    // Lignes
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c <= $size - $winLen; $c++) {
            $sym = $board[$r * $size + $c];
            if (!$sym) continue;
            $match = true;
            $winningCombo = [$r * $size + $c];
            for ($k = 1; $k < $winLen; $k++) {
                if ($board[$r * $size + $c + $k] !== $sym) { $match = false; break; }
                $winningCombo[] = $r * $size + $c + $k;
            }

            if ($match) return ['winner' => $sym, 'combo' => $winningCombo];
        }
    }
    // Colonnes
    for ($c = 0; $c < $size; $c++) {
        for ($r = 0; $r <= $size - $winLen; $r++) {
            $sym = $board[$r * $size + $c];
            if (!$sym) continue;
            $match = true;
            $winningCombo = [$r * $size + $c];
            for ($k = 1; $k < $winLen; $k++) {
                if ($board[($r + $k) * $size + $c] !== $sym) { $match = false; break; }
                $winningCombo[] = ($r + $k) * $size + $c;
            }
            if ($match) return ['winner' => $sym, 'combo' => $winningCombo];
        }
    }
    // Diagonale\
    for ($r = 0; $r <= $size - $winLen; $r++) {
        for ($c = 0; $c <= $size - $winLen; $c++) {
            $sym = $board[$r * $size + $c];
            if (!$sym) continue;
            $match = true;
            $winningCombo = [$r * $size + $c];
            for ($k = 1; $k < $winLen; $k++) {
                if ($board[($r + $k) * $size + ($c + $k)] !== $sym) { $match = false; break; }
                $winningCombo[] = ($r + $k) * $size + ($c + $k);
            }
            if ($match) return ['winner' => $sym, 'combo' => $winningCombo];
        }
    }
    // Diagonale /
    for ($r = 0; $r <= $size - $winLen; $r++) {
        for ($c = $winLen - 1; $c < $size; $c++) {
            $sym = $board[$r * $size + $c];
            if (!$sym) continue;
            $match = true;
            $winningCombo = [$r * $size + $c];
            for ($k = 1; $k < $winLen; $k++) {
                if ($board[($r + $k) * $size + ($c - $k)] !== $sym) { $match = false; break; }
                $winningCombo[] = ($r + $k) * $size + ($c - $k);
            }
            if ($match) return ['winner' => $sym, 'combo' => $winningCombo];
        }
    }

    $isFull = true;
    foreach ($board as $cell) {
        if ($cell === null || $cell === '') {
            $isFull = false;
            break;
        }
    }
    if ($isFull) return ['winner' => 'TIE', 'combo' => []];

    return null;
}

function applyRoomAction(array &$room, string $action): void {
    $room['moveHistory'] = $room['moveHistory'] ?? [];
    $previousStatus = $room['status'] ?? '';
    $previousWinner = $room['winner'] ?? null;
    if ($action === 'change_grid') {
        $size = (int)($room['pendingAction']['size'] ?? 3);
        $room['size'] = $size;
        $room['winLen'] = $size === 3 ? 3 : ($size === 5 ? 4 : 5);
    }
    if ($action === 'undo') {
        $lastMove = array_pop($room['moveHistory']);
        if ($lastMove) {
            $room['board'][$lastMove['index']] = null;
            $room['currentTurn'] = $lastMove['symbol'];
            $room['status'] = 'playing';
            $room['winner'] = null;
            $room['winningCombo'] = [];
            $room['tournamentWinner'] = null;
            if ($previousStatus === 'finished' && in_array($previousWinner, ['X', 'O'], true)) {
                $room['scores'][$previousWinner] = max(0, $room['scores'][$previousWinner] - 1);
            } elseif ($previousStatus === 'finished' && $previousWinner === 'TIE') {
                $room['scores']['ties'] = max(0, $room['scores']['ties'] - 1);
            }
        }
    } else {
        if ($action === 'reset_all') {
            $room['scores'] = ['X' => 0, 'O' => 0, 'ties' => 0];
            $room['tournamentWinner'] = null;
        }
        if ($action === 'menu') {
            $room['status'] = 'abandoned';
            $room['pendingAction'] = null;
            $room['updatedAt'] = time();
            return;
        }
        $nextStarter = ($room['startingPlayer'] ?? 'X') === 'X' ? 'O' : 'X';
        $room['startingPlayer'] = $nextStarter;
        $room['currentTurn'] = $nextStarter;
        $room['board'] = array_fill(0, $room['size'] * $room['size'], null);
        $room['winner'] = null;
        $room['winningCombo'] = [];
        $room['tournamentWinner'] = null;
        $room['status'] = $room['playerO'] ? 'playing' : 'waiting';
        $room['moveHistory'] = [];
    }
    $room['pendingAction'] = null;
    $room['updatedAt'] = time();
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'game_options':
        $db = mg_db();
        sendApiResponse([
            'success' => true,
            'themes' => $db->query('SELECT theme_key AS key, name, primary_color AS primaryColor, background_color AS backgroundColor FROM game_themes ORDER BY custom, name')->fetchAll(),
            'symbols' => $db->query('SELECT symbol, label FROM game_symbols ORDER BY custom, id')->fetchAll(),
        ]);

    case 'admin_stats':
        $user = mg_current_user();
        if (!$user || $user['role'] !== 'admin') {
            sendApiResponse(['success' => false, 'error' => 'Accès administrateur requis.'], 403);
        }
        $db = mg_db();
        $now = time();
        $summary = $db->query(
            'SELECT
                (SELECT COUNT(*) FROM users WHERE role = \'player\') AS players,
                (SELECT COUNT(*) FROM users WHERE role = \'player\' AND last_seen_at >= ' . ($now - 90) . ') AS online,
                (SELECT COUNT(*) FROM game_sessions) AS games,
                (SELECT COUNT(*) FROM game_sessions WHERE completed_at IS NOT NULL) AS completed,
                (SELECT COUNT(*) FROM game_sessions WHERE completed_at IS NULL AND started_at >= ' . ($now - 3600) . ') AS active_games,
                (SELECT COUNT(*) FROM game_sessions WHERE mode = \'bot\') AS bot_games,
                (SELECT COUNT(*) FROM bot_memory) AS trained_accounts'
        )->fetch();
        $botLevels = $db->query(
            'SELECT bot_level, COUNT(*) AS games,
                SUM(CASE WHEN result = \'O\' THEN 1 ELSE 0 END) AS bot_wins,
                SUM(CASE WHEN result = \'X\' THEN 1 ELSE 0 END) AS player_wins,
                SUM(CASE WHEN result = \'TIE\' THEN 1 ELSE 0 END) AS ties
             FROM game_sessions WHERE mode = \'bot\'
             GROUP BY bot_level ORDER BY games DESC'
        )->fetchAll();
        $popularModes = $db->query(
            'SELECT mode, COUNT(*) AS games
             FROM game_sessions
             GROUP BY mode
             ORDER BY games DESC'
        )->fetchAll();
        $players = $db->query(
            'SELECT u.id, u.display_name, u.email, u.created_at, u.last_seen_at, u.is_banned,
                COUNT(g.id) AS games,
                COALESCE(b.learning_level, 0) AS learning_level,
                COALESCE(b.games_learned, 0) AS games_learned
             FROM users u
             LEFT JOIN game_sessions g ON g.user_id = u.id
             LEFT JOIN bot_memory b ON b.user_id = u.id
             WHERE u.role = \'player\'
             GROUP BY u.id
             ORDER BY u.last_seen_at DESC, u.created_at DESC
             LIMIT 100'
        )->fetchAll();
        $themes = $db->query('SELECT id, theme_key AS `key`, name, primary_color, background_color, custom FROM game_themes ORDER BY custom, name')->fetchAll();
        $symbols = $db->query('SELECT id, symbol, label, custom FROM game_symbols ORDER BY custom, id')->fetchAll();
        sendApiResponse([
            'success' => true,
            'summary' => $summary,
            'bot_levels' => $botLevels,
            'popular_modes' => $popularModes,
            'players' => $players,
            'themes' => $themes,
            'symbols' => $symbols,
        ]);

    case 'admin_rooms':
        $user = mg_current_user();
        if (!$user || $user['role'] !== 'admin') {
            sendApiResponse(['success' => false, 'error' => 'Accès administrateur requis.'], 403);
        }
        $rooms = [];
        foreach (glob($roomsDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            $contents = file_get_contents($path);
            $room = $contents === false ? null : json_decode($contents, true);
            if (!is_array($room)) {
                continue;
            }
            $lastSeen = max((int)($room['playerX']['lastSeen'] ?? 0), (int)($room['playerO']['lastSeen'] ?? 0));
            $rooms[] = [
                'id' => basename($path, '.json'),
                'status' => $room['status'] ?? 'unknown',
                'players' => (int)!empty($room['playerX']) + (int)!empty($room['playerO']),
                'last_seen' => $lastSeen,
            ];
        }
        sendApiResponse(['success' => true, 'rooms' => $rooms]);

    case 'admin_delete_room':
        $user = mg_current_user();
        if (!$user || $user['role'] !== 'admin') {
            sendApiResponse(['success' => false, 'error' => 'Accès administrateur requis.'], 403);
        }
        if (!mg_verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            sendApiResponse(['success' => false, 'error' => 'Jeton de sécurité invalide.'], 403);
        }
        $roomId = (string)($input['room_id'] ?? '');
        if (!preg_match('/^[A-Z0-9]{4}$/', $roomId)) {
            sendApiResponse(['success' => false, 'error' => 'Code de salon invalide.'], 400);
        }
        $file = getRoomFilePath($roomId, $roomsDir);
        if (!is_file($file) || !unlink($file)) {
            sendApiResponse(['success' => false, 'error' => 'Impossible de supprimer ce salon.'], 404);
        }
        sendApiResponse(['success' => true]);

    case 'admin_manage_user':
        $user = mg_current_user();
        if (!$user || $user['role'] !== 'admin') {
            sendApiResponse(['success' => false, 'error' => 'Accès administrateur requis.'], 403);
        }
        if (!mg_verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            sendApiResponse(['success' => false, 'error' => 'Jeton de sécurité invalide.'], 403);
        }
        $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
        $operation = $input['operation'] ?? '';
        if (!$userId || $userId === (int)$user['id']) {
            sendApiResponse(['success' => false, 'error' => 'Compte cible invalide.'], 400);
        }
        $db = mg_db();
        $target = $db->prepare('SELECT role FROM users WHERE id = :id');
        $target->execute([':id' => $userId]);
        $targetRole = $target->fetchColumn();
        if (!$targetRole || $targetRole === 'admin') {
            sendApiResponse(['success' => false, 'error' => 'Ce compte ne peut pas être modifié ici.'], 403);
        }
        if ($operation === 'delete') {
            $statement = $db->prepare('DELETE FROM users WHERE id = :id AND role = \'player\'');
            $statement->execute([':id' => $userId]);
        } elseif (in_array($operation, ['ban', 'unban'], true)) {
            $statement = $db->prepare('UPDATE users SET is_banned = :banned WHERE id = :id');
            $statement->execute([':banned' => $operation === 'ban' ? 1 : 0, ':id' => $userId]);
        } elseif ($operation === 'edit') {
            $name = trim((string)($input['display_name'] ?? ''));
            $email = mb_strtolower(trim((string)($input['email'] ?? '')));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 30 || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || mb_strlen($email) > 190 || $email === MG_ADMIN_EMAIL) {
                sendApiResponse(['success' => false, 'error' => 'Pseudo ou e-mail invalide.'], 400);
            }
            $statement = $db->prepare('UPDATE users SET display_name = :name, email = :email WHERE id = :id');
            try {
                $statement->execute([':name' => $name, ':email' => $email, ':id' => $userId]);
            } catch (PDOException $exception) {
                if ((string)$exception->getCode() === '23000') {
                    sendApiResponse(['success' => false, 'error' => 'Cet e-mail est déjà utilisé.'], 409);
                }
                throw $exception;
            }
        } else {
            sendApiResponse(['success' => false, 'error' => 'Opération invalide.'], 400);
        }
        sendApiResponse(['success' => true]);

    case 'admin_manage_theme':
    case 'admin_manage_symbol':
        $user = mg_current_user();
        if (!$user || $user['role'] !== 'admin') {
            sendApiResponse(['success' => false, 'error' => 'Accès administrateur requis.'], 403);
        }
        if (!mg_verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            sendApiResponse(['success' => false, 'error' => 'Jeton de sécurité invalide.'], 403);
        }
        $db = mg_db();
        $operation = $input['operation'] ?? '';
        if ($action === 'admin_manage_theme') {
            if ($operation === 'add') {
                $name = trim((string)($input['name'] ?? ''));
                $primary = (string)($input['primary_color'] ?? '');
                $background = (string)($input['background_color'] ?? '');
                $key = strtolower(trim((string)($input['key'] ?? '')));
                if (mb_strlen($name) < 2 || mb_strlen($name) > 30 || !preg_match('/^[a-z][a-z0-9-]{1,24}$/', $key)
                    || !preg_match('/^#[0-9a-fA-F]{6}$/', $primary) || !preg_match('/^#[0-9a-fA-F]{6}$/', $background)) {
                    sendApiResponse(['success' => false, 'error' => 'Nom, identifiant ou couleur de thème invalide.'], 400);
                }
                try {
                    $statement = $db->prepare('INSERT INTO game_themes (theme_key, name, primary_color, background_color, custom) VALUES (:key, :name, :primary, :background, 1)');
                    $statement->execute([':key' => $key, ':name' => $name, ':primary' => $primary, ':background' => $background]);
                } catch (PDOException $exception) {
                    if ((string)$exception->getCode() === '23000') {
                        sendApiResponse(['success' => false, 'error' => 'Cet identifiant de thème existe déjà.'], 409);
                    }
                    throw $exception;
                }
            } elseif ($operation === 'delete') {
                $statement = $db->prepare('DELETE FROM game_themes WHERE id = :id AND custom = 1');
                $statement->execute([':id' => (int)($input['id'] ?? 0)]);
                if ($statement->rowCount() === 0) {
                    sendApiResponse(['success' => false, 'error' => 'Seuls les thèmes personnalisés peuvent être supprimés.'], 400);
                }
            } else {
                sendApiResponse(['success' => false, 'error' => 'Opération de thème invalide.'], 400);
            }
        } else {
            if ($operation === 'add') {
                $symbol = trim((string)($input['symbol'] ?? ''));
                $label = trim((string)($input['label'] ?? ''));
                if ($symbol === '' || mb_strlen($symbol) > 8 || mb_strlen($label) < 1 || mb_strlen($label) > 30
                    || preg_match('/[\x00-\x1F<>]/u', $symbol)) {
                    sendApiResponse(['success' => false, 'error' => 'Symbole ou nom invalide.'], 400);
                }
                try {
                    $statement = $db->prepare('INSERT INTO game_symbols (symbol, label, custom) VALUES (:symbol, :label, 1)');
                    $statement->execute([':symbol' => $symbol, ':label' => $label]);
                } catch (PDOException $exception) {
                    if ((string)$exception->getCode() === '23000') {
                        sendApiResponse(['success' => false, 'error' => 'Ce symbole existe déjà.'], 409);
                    }
                    throw $exception;
                }
            } elseif ($operation === 'delete') {
                $statement = $db->prepare('DELETE FROM game_symbols WHERE id = :id AND custom = 1');
                $statement->execute([':id' => (int)($input['id'] ?? 0)]);
                if ($statement->rowCount() === 0) {
                    sendApiResponse(['success' => false, 'error' => 'Seuls les symboles personnalisés peuvent être supprimés.'], 400);
                }
            } else {
                sendApiResponse(['success' => false, 'error' => 'Opération de symbole invalide.'], 400);
            }
        }
        sendApiResponse(['success' => true]);

    case 'request_room_action':
        $roomId = strtoupper(trim((string)($input['room_id'] ?? '')));
        $playerId = trim((string)($input['player_id'] ?? ''));
        $operation = $input['operation'] ?? '';
        $requestId = (string)($input['request_id'] ?? '');
        $requestedAction = (string)($input['requested_action'] ?? '');
        $allowedActions = ['undo', 'next_round', 'reset_all', 'menu', 'change_grid'];
        $requestedSize = (int)($input['size'] ?? 3);
        $approved = !empty($input['approved']);
        $result = withRoomLock($roomId, $roomsDir, function (&$room) use ($playerId, $operation, $requestId, $requestedAction, $allowedActions, $approved, $requestedSize) {
            $isX = ($room['playerX']['id'] ?? null) === $playerId;
            $isO = ($room['playerO']['id'] ?? null) === $playerId;
            if (!$isX && !$isO) {
                return ['save' => false, 'response' => ['success' => false, 'error' => 'Joueur non autorisé']];
            }
            if ($operation === 'request') {
                if (!empty($room['pendingAction'])
                    && time() - (int)($room['pendingAction']['createdAt'] ?? 0) > 30) {
                    $room['pendingAction'] = null;
                }
                if (!in_array($requestedAction, $allowedActions, true) || !empty($room['pendingAction'])) {
                    return ['save' => false, 'response' => ['success' => false, 'error' => 'Une autre demande est déjà en attente']];
                }
                if ($requestedAction === 'undo' && empty($room['moveHistory'])) {
                    return ['save' => false, 'response' => ['success' => false, 'error' => 'Aucun coup à annuler']];
                }
                if ($requestedAction === 'change_grid' && !in_array($requestedSize, [3, 5, 10, 12, 15, 19], true)) {
                    return ['save' => false, 'response' => ['success' => false, 'error' => 'Taille de grille invalide']];
                }
                $room['pendingAction'] = [
                    'id' => bin2hex(random_bytes(12)),
                    'action' => $requestedAction,
                    'requesterId' => $playerId,
                    'requesterName' => $isX ? $room['playerX']['name'] : $room['playerO']['name'],
                    'createdAt' => time(),
                ];
                if ($requestedAction === 'change_grid') {
                    $room['pendingAction']['size'] = $requestedSize;
                }
            } elseif ($operation === 'respond') {
                $pending = $room['pendingAction'] ?? null;
                if (!$pending || !hash_equals((string)$pending['id'], $requestId)
                    || time() - (int)($pending['createdAt'] ?? 0) > 30
                    || hash_equals((string)$pending['requesterId'], $playerId)) {
                    return ['save' => false, 'response' => ['success' => false, 'error' => 'Demande introuvable ou vous ne pouvez pas répondre à cette demande']];
                }
                if ($approved) {
                    applyRoomAction($room, $pending['action']);
                    $room['lastActionResult'] = ['id' => $requestId, 'approved' => true, 'by' => $playerId];
                } else {
                    $room['pendingAction'] = null;
                    $room['lastActionResult'] = ['id' => $requestId, 'approved' => false, 'by' => $playerId];
                    $room['updatedAt'] = time();
                }
            } elseif ($operation === 'cancel') {
                $pending = $room['pendingAction'] ?? null;
                if (!$pending || !hash_equals((string)$pending['id'], $requestId)
                    || !hash_equals((string)$pending['requesterId'], $playerId)) {
                    return ['save' => false, 'response' => ['success' => false, 'error' => 'Cette demande ne peut pas être annulée']];
                }
                $room['pendingAction'] = null;
                $room['lastActionResult'] = ['id' => $requestId, 'approved' => false, 'cancelled' => true, 'by' => $playerId];
            } else {
                return ['save' => false, 'response' => ['success' => false, 'error' => 'Opération invalide']];
            }
            $room['updatedAt'] = time();
            return ['save' => true, 'response' => ['success' => true, 'room' => $room]];
        });
        sendApiResponse($result);

    case 'presence':
        $user = mg_current_user();
        if (!$user) {
            sendApiResponse(['success' => true, 'authenticated' => false]);
        }
        if (!mg_verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            sendApiResponse(['success' => false, 'error' => 'Jeton de sécurité invalide.'], 403);
        }
        $statement = mg_db()->prepare('UPDATE users SET last_seen_at = :now WHERE id = :id');
        $statement->execute([':now' => time(), ':id' => $user['id']]);
        sendApiResponse(['success' => true, 'authenticated' => true]);

    case 'bot_profile':
        $user = mg_current_user();
        if (!$user) {
            sendApiResponse(['success' => true, 'profile' => null]);
        }
        $statement = mg_db()->prepare(
            'SELECT learning_level, mistakes, games_learned FROM bot_memory WHERE user_id = :id'
        );
        $statement->execute([':id' => $user['id']]);
        $profile = $statement->fetch();
        sendApiResponse([
            'success' => true,
            'profile' => $profile ? [
                'learningLevel' => (int)$profile['learning_level'],
                'mistakes' => json_decode($profile['mistakes'], true) ?: [],
                'gamesLearned' => (int)$profile['games_learned'],
            ] : ['learningLevel' => 0, 'mistakes' => [], 'gamesLearned' => 0],
        ]);

    case 'start_game':
        $user = mg_current_user();
        if ($user && !mg_verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            sendApiResponse(['success' => false, 'error' => 'Jeton de sécurité invalide.'], 403);
        }
        $gameMode = $input['mode'] ?? '';
        $botLevel = $input['bot_level'] ?? null;
        $size = filter_var($input['board_size'] ?? 3, FILTER_VALIDATE_INT);
        if (!in_array($gameMode, ['local', 'bot', 'wifi'], true)
            || !in_array($size, [3, 5, 10, 12, 15, 19], true)
            || ($gameMode === 'bot' && !in_array($botLevel, ['novice', 'medium', 'advanced', 'legend', 'adaptive'], true))) {
            sendApiResponse(['success' => false, 'error' => 'Paramètres de partie invalides.'], 400);
        }

        $gameId = bin2hex(random_bytes(16));
        $statement = mg_db()->prepare(
            'INSERT INTO game_sessions (id, session_key, user_id, mode, bot_level, board_size, started_at)
             VALUES (:id, :session_key, :user_id, :mode, :bot_level, :board_size, :started_at)'
        );
        $statement->execute([
            ':id' => $gameId,
            ':session_key' => hash('sha256', session_id()),
            ':user_id' => $user ? $user['id'] : null,
            ':mode' => $gameMode,
            ':bot_level' => $gameMode === 'bot' ? $botLevel : null,
            ':board_size' => $size,
            ':started_at' => time(),
        ]);
        sendApiResponse(['success' => true, 'game_id' => $gameId]);

    case 'finish_game':
        $user = mg_current_user();
        if ($user && !mg_verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            sendApiResponse(['success' => false, 'error' => 'Jeton de sécurité invalide.'], 403);
        }
        $gameId = (string)($input['game_id'] ?? '');
        $winner = $input['winner'] ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/', $gameId)
            || !in_array($winner, ['X', 'O', 'TIE'], true)) {
            sendApiResponse(['success' => false, 'error' => 'Résultat de partie invalide.'], 400);
        }

        $db = mg_db();
        $db->beginTransaction();
        $statement = $db->prepare('SELECT * FROM game_sessions WHERE id = :id');
        $statement->execute([':id' => $gameId]);
        $game = $statement->fetch();
        $ownsGame = $game && ($game['user_id'] !== null
            ? $user && (int)$game['user_id'] === (int)$user['id']
            : hash_equals($game['session_key'], hash('sha256', session_id())));
        if (!$ownsGame) {
            $db->rollBack();
            sendApiResponse(['success' => false, 'error' => 'Partie introuvable pour cette session.'], 404);
        }
        if ($game['completed_at'] !== null) {
            $db->commit();
            sendApiResponse(['success' => true, 'already_recorded' => true]);
        }

        $statement = $db->prepare(
            'UPDATE game_sessions SET result = :result, completed_at = :completed_at WHERE id = :id'
        );
        $statement->execute([':result' => $winner, ':completed_at' => time(), ':id' => $gameId]);

        if ($game['mode'] === 'bot' && $game['bot_level'] === 'adaptive' && $game['user_id'] !== null) {
            $decisions = $input['decisions'] ?? [];
            if (!is_array($decisions)) {
                $decisions = [];
            }
            $mistake = null;
            if ($winner === 'X' && $decisions) {
                $lastDecision = end($decisions);
                if (is_array($lastDecision)
                    && isset($lastDecision['board'], $lastDecision['move'], $lastDecision['size'], $lastDecision['winLength'])
                    && in_array((int)$lastDecision['size'], [3, 5, 10, 12, 15, 19], true)
                    && preg_match('/^[XO-]{9,361}$/', (string)$lastDecision['board'])
                    && strlen((string)$lastDecision['board']) === (int)$lastDecision['size'] ** 2
                    && filter_var($lastDecision['move'], FILTER_VALIDATE_INT) !== false
                    && (int)$lastDecision['move'] >= 0
                    && (int)$lastDecision['move'] < strlen((string)$lastDecision['board'])
                    && (string)$lastDecision['board'][(int)$lastDecision['move']] === '-') {
                    $mistake = [
                        'board' => (string)$lastDecision['board'],
                        'move' => (int)$lastDecision['move'],
                        'size' => (int)$lastDecision['size'],
                        'winLength' => (int)$lastDecision['winLength'],
                    ];
                }
            }

            $statement = $db->prepare(
                'SELECT learning_level, mistakes, games_learned FROM bot_memory WHERE user_id = :id'
            );
            $statement->execute([':id' => $game['user_id']]);
            $memory = $statement->fetch() ?: ['learning_level' => 0, 'mistakes' => '[]', 'games_learned' => 0];
            $learningLevel = (int)$memory['learning_level'];
            if ($winner === 'X') {
                $learningLevel = min(3, $learningLevel + 1);
            } elseif ($winner === 'O') {
                $learningLevel = max(-2, $learningLevel - 1);
            }
            $mistakes = json_decode($memory['mistakes'], true);
            $mistakes = is_array($mistakes) ? $mistakes : [];
            if ($mistake !== null) {
                $key = $mistake['board'] . ':' . $mistake['size'] . ':' . $mistake['winLength'];
                $found = false;
                foreach ($mistakes as &$savedMistake) {
                    if (($savedMistake['key'] ?? '') === $key && ($savedMistake['move'] ?? null) === $mistake['move']) {
                        $savedMistake['failures'] = min(20, (int)($savedMistake['failures'] ?? 0) + 1);
                        $found = true;
                        break;
                    }
                }
                unset($savedMistake);
                if (!$found) {
                    $mistakes[] = $mistake + ['key' => $key, 'failures' => 1];
                }
                $mistakes = array_slice($mistakes, -100);
            }
            $statement = $db->prepare(
                'INSERT INTO bot_memory (user_id, learning_level, mistakes, games_learned, updated_at)
                 VALUES (:user_id, :learning_level, :mistakes, :games_learned, :updated_at)
                 ON CONFLICT(user_id) DO UPDATE SET
                    learning_level = excluded.learning_level,
                    mistakes = excluded.mistakes,
                    games_learned = excluded.games_learned,
                    updated_at = excluded.updated_at'
            );
            $statement->execute([
                ':user_id' => $game['user_id'],
                ':learning_level' => $learningLevel,
                ':mistakes' => json_encode($mistakes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':games_learned' => (int)$memory['games_learned'] + 1,
                ':updated_at' => time(),
            ]);
        }

        $db->commit();
        sendApiResponse(['success' => true]);

    case 'create_room':
        // Validation stricte de la taille (3, 5 ou 10)
        $size = intval($input['size'] ?? 3);
        if (!in_array($size, [3, 5, 10, 12, 15, 19], true)) $size = 3;

        $winLen = 3;
        if ($size === 5) $winLen = 4;
        else if ($size >= 10) $winLen = 5;

        // Validation du format tournoi (1 = match unique, 2 = Bo3, 3 = Bo5)
        $targetWins = intval($input['target_wins'] ?? 1);
        if (!in_array($targetWins, [1, 2, 3, 5], true)) $targetWins = 1;

        $hostName = trim($input['player_name'] ?? 'Joueur 1');
        if (empty($hostName)) $hostName = 'Joueur 1';
        $hostName = mb_substr(htmlspecialchars($hostName, ENT_QUOTES, 'UTF-8'), 0, 20);

        // Code aléatoire cryptographiquement sécurisé à 4 caractères, vérification d'unicité
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $maxAttempts = 15;
        $roomId = '';
        do {
            $roomId = '';
            for ($i = 0; $i < 4; $i++) {
                $roomId .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $filePath = getRoomFilePath($roomId, $roomsDir);
            $maxAttempts--;
        } while (file_exists($filePath) && $maxAttempts > 0);

        $playerId = bin2hex(random_bytes(16));

        $roomData = [
            'roomId'          => $roomId,
            'size'            => $size,
            'winLen'          => $winLen,
            'targetWins'      => $targetWins,
            'status'          => 'waiting', // waiting, playing, finished, abandoned
            'playerX'         => ['id' => $playerId, 'name' => $hostName, 'lastSeen' => time()],
            'playerO'         => null,
            'currentTurn'     => 'X',
            'startingPlayer'  => 'X',
            'board'           => array_fill(0, $size * $size, null),
            'winner'          => null,
            'winningCombo'    => [],
            'scores'          => ['X' => 0, 'O' => 0, 'ties' => 0],
            'rematchVotes'    => [],
            'moveHistory'     => [],
            'pendingAction'   => null,
            'lastActionResult'=> null,
            'tournamentWinner'=> null,
            'updatedAt'       => time()
        ];

        file_put_contents(getRoomFilePath($roomId, $roomsDir), json_encode($roomData, JSON_PRETTY_PRINT));

        echo json_encode([
            'success'  => true,
            'roomId'   => $roomId,
            'playerId' => $playerId,
            'symbol'   => 'X',
            'room'     => $roomData
        ]);
        break;

    case 'join_room':
        $roomId = strtoupper(trim($input['room_id'] ?? ''));
        $guestName = trim($input['player_name'] ?? 'Joueur 2');
        if (empty($guestName)) $guestName = 'Joueur 2';
        $guestName = mb_substr(htmlspecialchars($guestName, ENT_QUOTES, 'UTF-8'), 0, 20);
        $existingPlayerId = trim($input['player_id'] ?? '');

        $result = withRoomLock($roomId, $roomsDir, function(&$room) use ($guestName, $existingPlayerId) {
            // Cas de reconnexion du joueur 1 ou 2
            if ($existingPlayerId !== '') {
                if ($room['playerX'] && $room['playerX']['id'] === $existingPlayerId) {
                    $room['playerX']['lastSeen'] = time();
                    return [
                        'save' => true,
                        'response' => [
                            'success'  => true,
                            'roomId'   => $room['roomId'],
                            'playerId' => $existingPlayerId,
                            'symbol'   => 'X',
                            'room'     => $room
                        ]
                    ];
                }
                if ($room['playerO'] && $room['playerO']['id'] === $existingPlayerId) {
                    $room['playerO']['lastSeen'] = time();
                    return [
                        'save' => true,
                        'response' => [
                            'success'  => true,
                            'roomId'   => $room['roomId'],
                            'playerId' => $existingPlayerId,
                            'symbol'   => 'O',
                            'room'     => $room
                        ]
                    ];
                }
            }

            // Nouveau joueur 2
            if ($room['playerO'] === null) {
                $playerId = bin2hex(random_bytes(16));
                $room['playerO'] = [
                    'id'       => $playerId,
                    'name'     => $guestName,
                    'lastSeen' => time()
                ];
                $room['status'] = 'playing';
                $room['updatedAt'] = time();

                return [
                    'save' => true,
                    'response' => [
                        'success'  => true,
                        'roomId'   => $room['roomId'],
                        'playerId' => $playerId,
                        'symbol'   => 'O',
                        'room'     => $room
                    ]
                ];
            }

            return [
                'save' => false,
                'response' => [
                    'success' => false,
                    'error'   => 'Ce salon est déjà complet (2/2 joueurs)'
                ]
            ];
        });

        echo json_encode($result);
        break;

    case 'get_state':
        $roomId = strtoupper(trim($input['room_id'] ?? $_GET['room_id'] ?? ''));
        $playerId = trim($input['player_id'] ?? $_GET['player_id'] ?? '');
        $filePath = getRoomFilePath($roomId, $roomsDir);

        if (!file_exists($filePath)) {
            echo json_encode(['success' => false, 'error' => 'Salon introuvable']);
            exit;
        }

        // Lecture légère sans réécriture complète pour éviter d'écraser les coups simultanés
        $fp = fopen($filePath, 'r');
        if (!$fp) {
            echo json_encode(['success' => false, 'error' => 'Fichier de salon inaccessible']);
            exit;
        }

        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $roomData = json_decode($content, true);
        if (!$roomData) {
            echo json_encode(['success' => false, 'error' => 'Données corrompues']);
            exit;
        }

        // Vérifier déconnexion adverse (silencieux si > 12s)
        $now = time();
        $opponentDisconnected = false;
        if ($roomData['status'] === 'playing') {
            if ($roomData['playerX'] && $roomData['playerX']['id'] === $playerId) {
                if ($roomData['playerO'] && ($now - $roomData['playerO']['lastSeen'] > 12)) {
                    $opponentDisconnected = true;
                }
            } else if ($roomData['playerO'] && $roomData['playerO']['id'] === $playerId) {
                if ($roomData['playerX'] && ($now - $roomData['playerX']['lastSeen'] > 12)) {
                    $opponentDisconnected = true;
                }
            }
        }

        // Mettre à jour lastSeen de façon sécurisée périodiquement
        if (!empty($playerId)) {
            withRoomLock($roomId, $roomsDir, function(&$room) use ($playerId, $now) {
                $needsSave = false;
                if ($room['playerX'] && $room['playerX']['id'] === $playerId) {
                    if ($now - $room['playerX']['lastSeen'] >= 2) {
                        $room['playerX']['lastSeen'] = $now;
                        $needsSave = true;
                    }
                } else if ($room['playerO'] && $room['playerO']['id'] === $playerId) {
                    if ($now - $room['playerO']['lastSeen'] >= 2) {
                        $room['playerO']['lastSeen'] = $now;
                        $needsSave = true;
                    }
                }
                return ['save' => $needsSave, 'response' => ['success' => true]];
            });
        }

        $roomData['opponentDisconnected'] = $opponentDisconnected;
        echo json_encode([
            'success' => true,
            'room'    => $roomData
        ]);
        break;

    case 'play_move':
        $roomId = strtoupper(trim($input['room_id'] ?? ''));
        $playerId = trim($input['player_id'] ?? '');
        $index = intval($input['index'] ?? -1);

        $result = withRoomLock($roomId, $roomsDir, function(&$room) use ($playerId, $index) {
            $sym = null;
            if ($room['playerX'] && $room['playerX']['id'] === $playerId) $sym = 'X';
            if ($room['playerO'] && $room['playerO']['id'] === $playerId) $sym = 'O';

            if (!$sym) {
                return ['save' => false, 'response' => ['success' => false, 'error' => 'Joueur non autorisé']];
            }

            if ($room['status'] !== 'playing') {
                return ['save' => false, 'response' => ['success' => false, 'error' => 'La partie n\'est pas en cours']];
            }

            if ($room['currentTurn'] !== $sym) {
                return ['save' => false, 'response' => ['success' => false, 'error' => 'Ce n\'est pas votre tour']];
            }

            if ($index < 0 || $index >= count($room['board']) || $room['board'][$index] !== null) {
                return ['save' => false, 'response' => ['success' => false, 'error' => 'Case invalide ou déjà occupée']];
            }

            // Coup valide
                $room['moveHistory'] = $room['moveHistory'] ?? [];
                $room['moveHistory'][] = ['index' => $index, 'symbol' => $sym];
                $room['board'][$index] = $sym;
            $room['rematchVotes'] = [];

            // Vérifier victoire
            $winRes = checkBoardWinner($room['board'], $room['size'], $room['winLen']);
            if ($winRes !== null) {
                $room['status'] = 'finished';
                $room['winner'] = $winRes['winner'];
                $room['winningCombo'] = $winRes['combo'];

                if ($winRes['winner'] === 'X') {
                    $room['scores']['X']++;
                } else if ($winRes['winner'] === 'O') {
                    $room['scores']['O']++;
                } else {
                    $room['scores']['ties']++;
                }

                // Vérification de victoire en tournoi
                if ($room['targetWins'] > 1) {
                    if ($room['scores']['X'] >= $room['targetWins']) {
                        $room['tournamentWinner'] = 'X';
                    } else if ($room['scores']['O'] >= $room['targetWins']) {
                        $room['tournamentWinner'] = 'O';
                    }
                }
            } else {
                $room['currentTurn'] = ($sym === 'X') ? 'O' : 'X';
            }

            $room['updatedAt'] = time();
            return [
                'save' => true,
                'response' => ['success' => true, 'room' => $room]
            ];
        });

        echo json_encode($result);
        break;

    case 'rematch':
        $roomId = strtoupper(trim($input['room_id'] ?? ''));
        $playerId = trim($input['player_id'] ?? '');

        $result = withRoomLock($roomId, $roomsDir, function(&$room) use ($playerId) {
            if (!in_array($playerId, $room['rematchVotes'], true)) {
                $room['rematchVotes'][] = $playerId;
            }

            // Si les 2 joueurs ont voté (ou joueur seul)
            $bothVoted = count($room['rematchVotes']) >= 2 || ($room['playerO'] === null && count($room['rematchVotes']) >= 1);

            if ($bothVoted) {
                // Alterner le joueur qui commence la manche !
                $nextStart = ($room['startingPlayer'] ?? 'X') === 'X' ? 'O' : 'X';
                $room['startingPlayer'] = $nextStart;
                $room['currentTurn'] = $nextStart;

                $room['board'] = array_fill(0, $room['size'] * $room['size'], null);
                $room['moveHistory'] = [];
                $room['status'] = 'playing';
                $room['winner'] = null;
                $room['winningCombo'] = [];
                $room['rematchVotes'] = [];

                // Si le tournoi était terminé, remise à zéro des scores pour nouvelle session
                if ($room['tournamentWinner'] !== null) {
                    $room['scores'] = ['X' => 0, 'O' => 0, 'ties' => 0];
                    $room['tournamentWinner'] = null;
                }
            }

            $room['updatedAt'] = time();
            return [
                'save' => true,
                'response' => [
                    'success'   => true,
                    'room'      => $room,
                    'bothVoted' => $bothVoted
                ]
            ];
        });

        echo json_encode($result);
        break;

    case 'leave_room':
        $roomId = strtoupper(trim($input['room_id'] ?? ''));
        $playerId = trim($input['player_id'] ?? '');

        $result = withRoomLock($roomId, $roomsDir, function(&$room) use ($playerId) {
            $isPlayerX = ($room['playerX'] && $room['playerX']['id'] === $playerId);
            $isPlayerO = ($room['playerO'] && $room['playerO']['id'] === $playerId);

            if ($isPlayerX || $isPlayerO) {
                $room['status'] = 'abandoned';
                $room['updatedAt'] = time();
                return ['save' => true, 'response' => ['success' => true]];
            }
            return ['save' => false, 'response' => ['success' => false]];
        });

        echo json_encode($result);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action inconnue']);
        break;
}
