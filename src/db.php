<?php
declare(strict_types=1);

final class Database
{
    private PDO $pdo;

    private const DEFAULT_THEMES = [
        ['id' => 1, 'theme_key' => 'red', 'name' => 'Rouge et Noir', 'primary_color' => '#dc2626', 'background_color' => '#09090b', 'custom' => 0],
        ['id' => 2, 'theme_key' => 'amber', 'name' => 'Ambre / Or', 'primary_color' => '#d97706', 'background_color' => '#0c0e12', 'custom' => 0],
        ['id' => 3, 'theme_key' => 'purple', 'name' => 'Violet', 'primary_color' => '#9333ea', 'background_color' => '#090710', 'custom' => 0],
        ['id' => 4, 'theme_key' => 'cyan', 'name' => 'Cyan Électrique', 'primary_color' => '#0891b2', 'background_color' => '#060e14', 'custom' => 0],
        ['id' => 5, 'theme_key' => 'emerald', 'name' => 'Émeraude', 'primary_color' => '#059669', 'background_color' => '#05100b', 'custom' => 0],
    ];

    private const DEFAULT_SYMBOLS = [
        ['id' => 1, 'symbol' => '✕', 'label' => 'Croix classique', 'custom' => 0],
        ['id' => 2, 'symbol' => '○', 'label' => 'Cercle classique', 'custom' => 0],
        ['id' => 3, 'symbol' => '⚡', 'label' => 'Éclair néon', 'custom' => 0],
        ['id' => 4, 'symbol' => '✦', 'label' => 'Étoile diamant', 'custom' => 0],
    ];

    public function __construct(string $dataDirectory)
    {
        if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0770, true) && !is_dir($dataDirectory)) {
            throw new RuntimeException('Impossible de créer le dossier data.');
        }

        $this->pdo = new PDO('sqlite:' . $dataDirectory . DIRECTORY_SEPARATOR . 'multigames.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->createSchema();
        $this->importLegacyData($dataDirectory . DIRECTORY_SEPARATOR . 'multigames.json');
        $this->ensureDefaults();
        $this->ensureAdminUser();
    }

    private function createSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL COLLATE NOCASE UNIQUE,
                display_name TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'player',
                created_at INTEGER NOT NULL,
                last_seen_at INTEGER NOT NULL,
                is_banned INTEGER NOT NULL DEFAULT 0,
                avatar TEXT NOT NULL DEFAULT '🚀',
                theme_preference TEXT NOT NULL DEFAULT 'red'
            );
            CREATE TABLE IF NOT EXISTS game_sessions (
                id TEXT PRIMARY KEY,
                session_key TEXT NOT NULL,
                user_id INTEGER,
                mode TEXT NOT NULL,
                bot_level TEXT,
                board_size INTEGER NOT NULL,
                result TEXT,
                started_at INTEGER NOT NULL,
                completed_at INTEGER,
                user_symbol TEXT DEFAULT 'X',
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            );
            CREATE INDEX IF NOT EXISTS idx_game_sessions_user_started ON game_sessions(user_id, started_at);
            CREATE TABLE IF NOT EXISTS bot_memory (
                user_id INTEGER PRIMARY KEY,
                learning_level INTEGER NOT NULL DEFAULT 0,
                mistakes TEXT NOT NULL DEFAULT '[]',
                games_learned INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS game_themes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                theme_key TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                primary_color TEXT NOT NULL,
                background_color TEXT NOT NULL,
                custom INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS game_symbols (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                symbol TEXT NOT NULL UNIQUE,
                label TEXT NOT NULL,
                custom INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS migrations (
                migration TEXT PRIMARY KEY,
                completed_at INTEGER NOT NULL
            );
        SQL);
    }

    private function importLegacyData(string $file): void
    {
        $migration = 'import_multigames_json_v1';
        $check = $this->pdo->prepare('SELECT 1 FROM migrations WHERE migration = ?');
        $check->execute([$migration]);
        if ($check->fetchColumn()) {
            return;
        }

        if (is_file($file)) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                throw new RuntimeException('Impossible de lire les anciennes données multigames.json.');
            }
            try {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException('Le fichier multigames.json est invalide; les données n’ont pas été importées.', 0, $error);
            }
            if (!is_array($data)) {
                throw new RuntimeException('Le fichier multigames.json a un format inattendu.');
            }

            $this->pdo->beginTransaction();
            try {
                foreach (($data['users'] ?? []) as $user) {
                    $this->insertLegacyRow('users', [
                        'id' => $user['id'] ?? null,
                        'email' => strtolower(trim((string)($user['email'] ?? ''))),
                        'display_name' => (string)($user['display_name'] ?? ''),
                        'password_hash' => (string)($user['password_hash'] ?? ''),
                        'role' => ($user['role'] ?? 'player') === 'admin' ? 'admin' : 'player',
                        'created_at' => (int)($user['created_at'] ?? time()),
                        'last_seen_at' => (int)($user['last_seen_at'] ?? time()),
                        'is_banned' => (int)($user['is_banned'] ?? 0),
                        'avatar' => (string)($user['avatar'] ?? (($user['role'] ?? '') === 'admin' ? '👑' : '🚀')),
                        'theme_preference' => (string)($user['theme_preference'] ?? 'red'),
                    ]);
                }
                foreach (($data['sessions'] ?? []) as $game) {
                    $this->insertLegacyRow('game_sessions', [
                        'id' => $game['id'] ?? null,
                        'session_key' => (string)($game['session_key'] ?? ''),
                        'user_id' => $game['user_id'] ?? null,
                        'mode' => (string)($game['mode'] ?? 'local'),
                        'bot_level' => $game['bot_level'] ?? null,
                        'board_size' => (int)($game['board_size'] ?? 3),
                        'result' => $game['result'] ?? null,
                        'started_at' => (int)($game['started_at'] ?? time()),
                        'completed_at' => $game['completed_at'] ?? null,
                        'user_symbol' => $game['user_symbol'] ?? 'X',
                    ]);
                }
                foreach (($data['botMemory'] ?? []) as $memory) {
                    $this->insertLegacyRow('bot_memory', [
                        'user_id' => $memory['user_id'] ?? null,
                        'learning_level' => (int)($memory['learning_level'] ?? 0),
                        'mistakes' => json_encode($memory['mistakes'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'games_learned' => (int)($memory['games_learned'] ?? 0),
                        'updated_at' => (int)($memory['updated_at'] ?? time()),
                    ]);
                }
                foreach (($data['themes'] ?? []) as $theme) {
                    $this->insertLegacyRow('game_themes', [
                        'id' => $theme['id'] ?? null,
                        'theme_key' => (string)($theme['theme_key'] ?? ''),
                        'name' => (string)($theme['name'] ?? ''),
                        'primary_color' => (string)($theme['primary_color'] ?? '#dc2626'),
                        'background_color' => (string)($theme['background_color'] ?? '#09090b'),
                        'custom' => (int)($theme['custom'] ?? 0),
                    ]);
                }
                foreach (($data['symbols'] ?? []) as $symbol) {
                    $this->insertLegacyRow('game_symbols', [
                        'id' => $symbol['id'] ?? null,
                        'symbol' => (string)($symbol['symbol'] ?? ''),
                        'label' => (string)($symbol['label'] ?? ''),
                        'custom' => (int)($symbol['custom'] ?? 0),
                    ]);
                }
                $statement = $this->pdo->prepare('INSERT INTO migrations (migration, completed_at) VALUES (?, ?)');
                $statement->execute([$migration, time()]);
                $this->pdo->commit();
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
            return;
        }

        $statement = $this->pdo->prepare('INSERT INTO migrations (migration, completed_at) VALUES (?, ?)');
        $statement->execute([$migration, time()]);
    }

    private function insertLegacyRow(string $table, array $row): void
    {
        $columns = array_keys($row);
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO ' . $table .
            ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $statement->execute(array_values($row));
    }

    private function ensureDefaults(): void
    {
        $themeCount = (int)$this->pdo->query('SELECT COUNT(*) FROM game_themes')->fetchColumn();
        if ($themeCount === 0) {
            $statement = $this->pdo->prepare('INSERT INTO game_themes (id, theme_key, name, primary_color, background_color, custom) VALUES (?, ?, ?, ?, ?, ?)');
            foreach (self::DEFAULT_THEMES as $theme) {
                $statement->execute(array_values($theme));
            }
        }

        $symbolCount = (int)$this->pdo->query('SELECT COUNT(*) FROM game_symbols')->fetchColumn();
        if ($symbolCount === 0) {
            $statement = $this->pdo->prepare('INSERT INTO game_symbols (id, symbol, label, custom) VALUES (?, ?, ?, ?)');
            foreach (self::DEFAULT_SYMBOLS as $symbol) {
                $statement->execute(array_values($symbol));
            }
        }
    }

    private function ensureAdminUser(): void
    {
        $migration = 'admin_password_provisioning_v1';
        $statement = $this->pdo->prepare('SELECT 1 FROM migrations WHERE migration = ?');
        $statement->execute([$migration]);
        if ($statement->fetchColumn()) {
            return;
        }

        $existing = $this->getUserByEmail('spacedev@thegoat.com');
        $password = getenv('MG_ADMIN_PASSWORD');
        if ($password === false || $password === '') {
            if ($existing && $existing['role'] === 'admin' && !$existing['is_banned']) {
                $this->updateUser($existing['id'], ['is_banned' => 1]);
            }
            return;
        }
        if (strlen($password) < 16) {
            throw new RuntimeException('MG_ADMIN_PASSWORD doit contenir au moins 16 caractères.');
        }

        if ($existing) {
            if ($existing['role'] !== 'admin') {
                throw new RuntimeException('L’adresse administrateur est déjà utilisée par un compte non administrateur.');
            }
            $this->updateUser($existing['id'], [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'is_banned' => 0,
                'avatar' => $existing['avatar'] ?: '👑',
                'theme_preference' => $existing['theme_preference'] ?: 'red',
            ]);
        } else {
            $this->createUser('spacedev@thegoat.com', 'Space Admin', password_hash($password, PASSWORD_DEFAULT), 'admin', '👑', 'red');
        }
        $statement = $this->pdo->prepare('INSERT INTO migrations (migration, completed_at) VALUES (?, ?)');
        $statement->execute([$migration, time()]);
    }

    private function normalizeUser(array $user): array
    {
        $user['id'] = (int)$user['id'];
        $user['created_at'] = (int)$user['created_at'];
        $user['last_seen_at'] = (int)$user['last_seen_at'];
        $user['is_banned'] = (int)$user['is_banned'];
        return $user;
    }

    public function getUserById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $statement->execute([$id]);
        $user = $statement->fetch();
        return $user ? $this->normalizeUser($user) : null;
    }

    public function getUserByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = ? COLLATE NOCASE');
        $statement->execute([strtolower(trim($email))]);
        $user = $statement->fetch();
        return $user ? $this->normalizeUser($user) : null;
    }

    public function createUser(string $email, string $displayName, string $passwordHash, string $role = 'player', string $avatar = '🚀', string $themePreference = 'red'): array
    {
        $now = time();
        $statement = $this->pdo->prepare(
            'INSERT INTO users (email, display_name, password_hash, role, created_at, last_seen_at, avatar, theme_preference) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([strtolower(trim($email)), trim($displayName), $passwordHash, $role, $now, $now, $avatar ?: '🚀', $themePreference ?: 'red']);
        return $this->getUserById((int)$this->pdo->lastInsertId()) ?? throw new RuntimeException('Le compte créé est introuvable.');
    }

    public function updateUser(int $id, array $updates): bool
    {
        $allowed = ['email', 'display_name', 'password_hash', 'role', 'last_seen_at', 'is_banned', 'avatar', 'theme_preference'];
        $updates = array_intersect_key($updates, array_flip($allowed));
        if (!$updates) {
            return $this->getUserById($id) !== null;
        }
        if (isset($updates['email'])) {
            $updates['email'] = strtolower(trim((string)$updates['email']));
        }
        $sets = array_map(static fn(string $column): string => $column . ' = ?', array_keys($updates));
        $statement = $this->pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $statement->execute([...array_values($updates), $id]);
        return $statement->rowCount() > 0 || $this->getUserById($id) !== null;
    }

    public function deleteUser(int $id): bool
    {
        $statement = $this->pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'player'");
        $statement->execute([$id]);
        return $statement->rowCount() > 0;
    }

    public function getAllPlayers(): array
    {
        $rows = $this->pdo->query("SELECT * FROM users WHERE role = 'player' ORDER BY id")->fetchAll();
        return array_map(fn(array $row): array => $this->normalizeUser($row), $rows);
    }

    public function getSessionById(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM game_sessions WHERE id = ?');
        $statement->execute([$id]);
        $game = $statement->fetch();
        return $game ? $this->normalizeGameSession($game) : null;
    }

    private function normalizeGameSession(array $game): array
    {
        foreach (['user_id', 'board_size', 'started_at', 'completed_at'] as $column) {
            if ($game[$column] !== null) {
                $game[$column] = (int)$game[$column];
            }
        }
        return $game;
    }

    public function createSession(array $game): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO game_sessions (id, session_key, user_id, mode, bot_level, board_size, result, started_at, completed_at, user_symbol) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $game['id'], $game['session_key'], $game['user_id'], $game['mode'], $game['bot_level'],
            $game['board_size'], $game['result'], $game['started_at'], $game['completed_at'], $game['user_symbol'] ?? 'X',
        ]);
    }

    public function updateSession(string $id, array $updates): bool
    {
        $allowed = ['session_key', 'user_id', 'mode', 'bot_level', 'board_size', 'result', 'started_at', 'completed_at', 'user_symbol'];
        $updates = array_intersect_key($updates, array_flip($allowed));
        if (!$updates) {
            return $this->getSessionById($id) !== null;
        }
        $sets = array_map(static fn(string $column): string => $column . ' = ?', array_keys($updates));
        $statement = $this->pdo->prepare('UPDATE game_sessions SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $statement->execute([...array_values($updates), $id]);
        return $statement->rowCount() > 0 || $this->getSessionById($id) !== null;
    }

    private function getUserSessions(int $userId, bool $ascending = false): array
    {
        $order = $ascending ? 'ASC' : 'DESC';
        $statement = $this->pdo->prepare("SELECT * FROM game_sessions WHERE user_id = ? ORDER BY started_at $order");
        $statement->execute([$userId]);
        return array_map(fn(array $game): array => $this->normalizeGameSession($game), $statement->fetchAll());
    }

    public function getUserGameStats(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) AS total, SUM(completed_at IS NOT NULL) AS completed, SUM(mode = 'bot') AS bot_games FROM game_sessions WHERE user_id = ?"
        );
        $statement->execute([$userId]);
        $stats = $statement->fetch();
        return ['total' => (int)$stats['total'], 'completed' => (int)$stats['completed'], 'bot_games' => (int)$stats['bot_games']];
    }

    public function getUserGameHistory(int $userId, int $limit = 50): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM game_sessions WHERE user_id = ? ORDER BY started_at DESC LIMIT ?');
        $statement->bindValue(1, $userId, PDO::PARAM_INT);
        $statement->bindValue(2, $limit, PDO::PARAM_INT);
        $statement->execute();
        $botNames = ['novice' => 'Novice 🐣', 'medium' => 'Intermédiaire 🤖', 'advanced' => 'Avancé 🧠', 'legend' => 'Légende 👑', 'adaptive' => 'Évolutif 🧬'];
        $history = [];
        foreach ($statement->fetchAll() as $game) {
            $game = $this->normalizeGameSession($game);
            $outcome = 'in_progress';
            $label = 'En cours';
            $badge = 'badge-in-progress';
            if ($game['completed_at'] !== null) {
                if ($game['mode'] === 'bot') {
                    if ($game['result'] === 'X') {
                        [$outcome, $label, $badge] = ['win', 'Victoire', 'badge-win'];
                    } elseif ($game['result'] === 'O') {
                        [$outcome, $label, $badge] = ['loss', 'Défaite', 'badge-loss'];
                    } elseif ($game['result'] === 'TIE') {
                        [$outcome, $label, $badge] = ['tie', 'Match nul', 'badge-tie'];
                    }
                } elseif ($game['mode'] === 'local') {
                    if ($game['result'] === 'TIE') {
                        [$outcome, $label, $badge] = ['tie', 'Match nul', 'badge-tie'];
                    } else {
                        [$outcome, $label, $badge] = ['win', 'Gagnant : ' . $game['result'], 'badge-win'];
                    }
                } elseif ($game['mode'] === 'wifi') {
                    if ($game['result'] === 'TIE') {
                        [$outcome, $label, $badge] = ['tie', 'Match nul', 'badge-tie'];
                    } elseif (in_array($game['result'], ['X', 'O'], true)) {
                        [$outcome, $label, $badge] = ['win', 'Gagnant : ' . $game['result'], 'badge-win'];
                    }
                }
            }
            $modeName = match ($game['mode']) {
                'bot' => 'Contre Bot (' . ($botNames[$game['bot_level'] ?? ''] ?? $game['bot_level'] ?? 'Standard') . ')',
                'local' => '1v1 Local (Même écran)',
                'wifi' => 'Duel Wi-Fi LAN',
                default => $game['mode'],
            };
            $history[] = [
                'id' => $game['id'],
                'mode' => $game['mode'],
                'modeName' => $modeName,
                'bot_level' => $game['bot_level'],
                'board_size' => $game['board_size'],
                'grid_desc' => $game['board_size'] === 3 ? '3×3 Classique' : $game['board_size'] . '×' . $game['board_size'] . ' Gomoku',
                'result' => $game['result'],
                'outcome' => $outcome,
                'outcomeLabel' => $label,
                'outcomeBadge' => $badge,
                'started_at' => $game['started_at'],
                'completed_at' => $game['completed_at'],
                'formatted_date' => date('d/m/Y H:i', $game['started_at']),
                'duration' => $game['completed_at'] ? max(1, $game['completed_at'] - $game['started_at']) : null,
            ];
        }
        return $history;
    }

    private function emptyModeStats(): array
    {
        return ['total' => 0, 'wins' => 0, 'losses' => 0, 'ties' => 0, 'ratio' => '0.00', 'win_rate' => 0];
    }

    private function ratio(int $wins, int $losses): array
    {
        if ($losses === 0) {
            return ['ratio' => $wins > 0 ? $wins . '.00' : '0.00', 'numeric' => $wins];
        }
        $value = $wins / $losses;
        return ['ratio' => number_format($value, 2, '.', ''), 'numeric' => round($value, 2)];
    }

    public function getUserDetailedStats(int $userId): array
    {
        $sessions = $this->getUserSessions($userId, true);
        $completedSessions = array_values(array_filter($sessions, static fn(array $game): bool => $game['completed_at'] !== null && $game['result'] !== null));
        $modes = ['bot' => $this->emptyModeStats(), 'wifi' => $this->emptyModeStats(), 'local' => $this->emptyModeStats()];
        $botLevels = array_fill_keys(['novice', 'medium', 'advanced', 'legend', 'adaptive'], $this->emptyModeStats());
        $wins = $losses = $ties = $botGames = $wifiGames = $localGames = $bestStreak = $tempStreak = 0;

        foreach ($sessions as $game) {
            $complete = $game['completed_at'] !== null && $game['result'] !== null;
            $userSymbol = $game['user_symbol'] ?: 'X';
            if (!isset($modes[$game['mode']])) {
                continue;
            }
            $mode = $game['mode'];
            $modes[$mode]['total']++;
            if ($mode === 'bot') {
                $botGames++;
                $level = $game['bot_level'] ?: 'medium';
                $botLevels[$level] ??= $this->emptyModeStats();
                $botLevels[$level]['total']++;
            } elseif ($mode === 'wifi') {
                $wifiGames++;
            } else {
                $localGames++;
            }
            if (!$complete) {
                continue;
            }

            $level = $game['bot_level'] ?: 'medium';
            $levelStat = $mode === 'bot' ? $botLevels[$level] : null;
            if ($game['result'] === $userSymbol) {
                $wins++;
                $modes[$mode]['wins']++;
                if ($mode === 'bot') {
                    $botLevels[$level]['wins']++;
                }
                $tempStreak++;
                $bestStreak = max($bestStreak, $tempStreak);
            } elseif ($game['result'] === 'TIE') {
                $ties++;
                $modes[$mode]['ties']++;
                if ($mode === 'bot') {
                    $botLevels[$level]['ties']++;
                }
                $tempStreak = 0;
            } else {
                $losses++;
                $modes[$mode]['losses']++;
                if ($mode === 'bot') {
                    $botLevels[$level]['losses']++;
                }
                $tempStreak = 0;
            }
        }

        $currentStreak = 0;
        foreach (array_reverse($completedSessions) as $game) {
            if ($game['result'] === ($game['user_symbol'] ?: 'X')) {
                $currentStreak++;
            } else {
                break;
            }
        }

        foreach ($modes as &$modeStats) {
            $modeStats['ratio'] = $this->ratio($modeStats['wins'], $modeStats['losses'])['ratio'];
            $finished = $modeStats['wins'] + $modeStats['losses'] + $modeStats['ties'];
            $modeStats['win_rate'] = $finished ? (int)round($modeStats['wins'] / $finished * 100) : 0;
        }
        unset($modeStats);
        foreach ($botLevels as &$levelStats) {
            $levelStats['ratio'] = $this->ratio($levelStats['wins'], $levelStats['losses'])['ratio'];
            $finished = $levelStats['wins'] + $levelStats['losses'] + $levelStats['ties'];
            $levelStats['win_rate'] = $finished ? (int)round($levelStats['wins'] / $finished * 100) : 0;
        }
        unset($levelStats);

        $overallRatio = $this->ratio($wins, $losses);
        $finished = $wins + $losses + $ties;
        return [
            'total' => count($sessions),
            'completed' => count($completedSessions),
            'wins' => $wins,
            'losses' => $losses,
            'ties' => $ties,
            'ratio' => $overallRatio['ratio'],
            'ratio_numeric' => $overallRatio['numeric'],
            'win_rate' => $finished ? (int)round($wins / $finished * 100) : 0,
            'points' => $wins * 3 + $ties,
            'current_streak' => $currentStreak,
            'best_streak' => $bestStreak,
            'bot_games' => $botGames,
            'wifi_games' => $wifiGames,
            'local_games' => $localGames,
            'modes' => $modes,
            'bot_level_breakdown' => $botLevels,
        ];
    }

    public function syncUserLocalStats(int $userId, int $localWins, int $localLosses, int $localTies): array
    {
        if (!$this->getUserById($userId)) {
            throw new RuntimeException('Utilisateur introuvable.');
        }
        $stats = $this->getUserDetailedStats($userId);
        $now = time();
        $toAdd = [
            ['w', max(0, $localWins - $stats['wins']), 'X', 60, 45],
            ['l', max(0, $localLosses - $stats['losses']), 'O', 50, 40],
            ['t', max(0, $localTies - $stats['ties']), 'TIE', 40, 35],
        ];
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO game_sessions (id, session_key, user_id, mode, bot_level, board_size, result, started_at, completed_at, user_symbol) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($toAdd as [$type, $count, $result, $offset, $duration]) {
            for ($i = 0; $i < $count; $i++) {
                $started = $now - ($count - $i) * $offset;
                $statement->execute(["sync_{$type}_{$userId}_{$now}_{$i}", 'local_sync', $userId, 'bot', 'medium', 3, $result, $started, $started + $duration, 'X']);
            }
        }
        return $this->getUserDetailedStats($userId);
    }

    public function getLeaderboard(int $limit = 50): array
    {
        $users = $this->pdo->query('SELECT * FROM users WHERE is_banned = 0')->fetchAll();
        $entries = [];
        foreach ($users as $rawUser) {
            $user = $this->normalizeUser($rawUser);
            $stats = $this->getUserDetailedStats($user['id']);
            $entries[] = [
                'id' => $user['id'], 'display_name' => $user['display_name'], 'avatar' => $user['avatar'] ?: '🚀',
                'role' => $user['role'], 'total_games' => $stats['total'], 'completed_games' => $stats['completed'],
                'wins' => $stats['wins'], 'losses' => $stats['losses'], 'ties' => $stats['ties'],
                'ratio' => $stats['ratio'], 'win_rate' => $stats['win_rate'], 'points' => $stats['points'],
                'last_seen_at' => $user['last_seen_at'],
            ];
        }
        usort($entries, static fn(array $a, array $b): int =>
            ($b['points'] <=> $a['points']) ?: ($b['wins'] <=> $a['wins']) ?:
            ($b['win_rate'] <=> $a['win_rate']) ?: ($b['completed_games'] <=> $a['completed_games'])
        );
        $entries = array_slice($entries, 0, max(0, $limit));
        foreach ($entries as $index => &$entry) {
            $entry = ['rank' => $index + 1] + $entry;
        }
        unset($entry);
        return $entries;
    }

    public function getUserRank(int $userId): int
    {
        foreach ($this->getLeaderboard(1000) as $entry) {
            if ($entry['id'] === $userId) {
                return $entry['rank'];
            }
        }
        return count($this->getLeaderboard(1000)) + 1;
    }

    public function getBotMemory(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bot_memory WHERE user_id = ?');
        $statement->execute([$userId]);
        $memory = $statement->fetch();
        if (!$memory) {
            $memory = ['user_id' => $userId, 'learning_level' => 0, 'mistakes' => [], 'games_learned' => 0, 'updated_at' => time()];
        } else {
            $memory['user_id'] = (int)$memory['user_id'];
            $memory['learning_level'] = (int)$memory['learning_level'];
            $memory['mistakes'] = json_decode($memory['mistakes'], true, 512, JSON_THROW_ON_ERROR);
            $memory['games_learned'] = (int)$memory['games_learned'];
            $memory['updated_at'] = (int)$memory['updated_at'];
        }
        return $memory;
    }

    public function updateBotMemory(int $userId, array $updates): void
    {
        $current = array_merge($this->getBotMemory($userId), $updates);
        $statement = $this->pdo->prepare(
            'INSERT INTO bot_memory (user_id, learning_level, mistakes, games_learned, updated_at) VALUES (?, ?, ?, ?, ?) ' .
            'ON CONFLICT(user_id) DO UPDATE SET learning_level = excluded.learning_level, mistakes = excluded.mistakes, games_learned = excluded.games_learned, updated_at = excluded.updated_at'
        );
        $statement->execute([
            $userId, $current['learning_level'],
            json_encode($current['mistakes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $current['games_learned'], time(),
        ]);
    }

    public function getThemes(): array
    {
        return array_map(static function (array $theme): array {
            foreach (['id', 'custom'] as $field) {
                $theme[$field] = (int)$theme[$field];
            }
            return $theme;
        }, $this->pdo->query('SELECT * FROM game_themes ORDER BY id')->fetchAll());
    }

    public function addTheme(string $key, string $name, string $primary, string $background): array
    {
        $statement = $this->pdo->prepare('INSERT INTO game_themes (theme_key, name, primary_color, background_color, custom) VALUES (?, ?, ?, ?, 1)');
        $statement->execute([$key, $name, $primary, $background]);
        return ['id' => (int)$this->pdo->lastInsertId(), 'theme_key' => $key, 'name' => $name, 'primary_color' => $primary, 'background_color' => $background, 'custom' => 1];
    }

    public function deleteTheme(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM game_themes WHERE id = ? AND custom = 1');
        $statement->execute([$id]);
        return $statement->rowCount() > 0;
    }

    public function getSymbols(): array
    {
        return array_map(static function (array $symbol): array {
            $symbol['id'] = (int)$symbol['id'];
            $symbol['custom'] = (int)$symbol['custom'];
            return $symbol;
        }, $this->pdo->query('SELECT * FROM game_symbols ORDER BY id')->fetchAll());
    }

    public function addSymbol(string $symbol, string $label): array
    {
        $statement = $this->pdo->prepare('INSERT INTO game_symbols (symbol, label, custom) VALUES (?, ?, 1)');
        $statement->execute([$symbol, $label]);
        return ['id' => (int)$this->pdo->lastInsertId(), 'symbol' => $symbol, 'label' => $label, 'custom' => 1];
    }

    public function deleteSymbol(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM game_symbols WHERE id = ? AND custom = 1');
        $statement->execute([$id]);
        return $statement->rowCount() > 0;
    }

    public function getCustomThemeCss(): string
    {
        $css = '';
        foreach ($this->getThemes() as $theme) {
            if (!$theme['custom']) {
                continue;
            }
            $key = preg_replace('/[^a-z0-9_-]/', '', $theme['theme_key']);
            $primary = preg_match('/^#[0-9a-fA-F]{6}$/', $theme['primary_color']) ? $theme['primary_color'] : '#dc2626';
            $background = preg_match('/^#[0-9a-fA-F]{6}$/', $theme['background_color']) ? $theme['background_color'] : '#09090b';
            $css .= '[data-theme="' . $key . '"]{--primary:' . $primary . ';--primary-hover:' . $primary .
                ';--bg-main:' . $background . ';--bg-gradient:radial-gradient(ellipse at 50% 0%,' . $primary .
                '22 0%,' . $background . ' 75%);--color-x:' . $primary . ';--win-line:' . $primary .
                ';--theme-color-preview:' . $primary . ';}';
        }
        return $css;
    }

    public function getSummaryStats(): array
    {
        $now = time();
        $players = $this->getAllPlayers();
        $summary = $this->pdo->query(
            'SELECT COUNT(*) AS games, SUM(completed_at IS NOT NULL) AS completed, ' .
            'SUM(completed_at IS NULL AND started_at >= ' . ($now - 3600) . ') AS active_games, ' .
            "SUM(mode = 'bot') AS bot_games FROM game_sessions"
        )->fetch();
        return [
            'players' => count($players),
            'online' => count(array_filter($players, static fn(array $player): bool => $player['last_seen_at'] >= $now - 90)),
            'games' => (int)$summary['games'],
            'completed' => (int)$summary['completed'],
            'active_games' => (int)$summary['active_games'],
            'bot_games' => (int)$summary['bot_games'],
            'trained_accounts' => (int)$this->pdo->query('SELECT COUNT(*) FROM bot_memory')->fetchColumn(),
        ];
    }

    public function getBotLevelsStats(): array
    {
        $rows = $this->pdo->query(
            "SELECT COALESCE(bot_level, 'non précisé') AS bot_level, COUNT(*) AS games, " .
            "SUM(result = 'O') AS bot_wins, SUM(result = 'X') AS player_wins, SUM(result = 'TIE') AS ties " .
            "FROM game_sessions WHERE mode = 'bot' GROUP BY bot_level ORDER BY games DESC"
        )->fetchAll();
        return array_map(static function (array $row): array {
            foreach (['games', 'bot_wins', 'player_wins', 'ties'] as $field) {
                $row[$field] = (int)$row[$field];
            }
            return $row;
        }, $rows);
    }

    public function getPopularModes(): array
    {
        $rows = $this->pdo->query('SELECT mode, COUNT(*) AS games FROM game_sessions GROUP BY mode ORDER BY games DESC')->fetchAll();
        return array_map(static fn(array $row): array => ['mode' => $row['mode'], 'games' => (int)$row['games']], $rows);
    }

    public function getPlayersAdminList(): array
    {
        $players = $this->getAllPlayers();
        $result = [];
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM game_sessions WHERE user_id = ?');
        foreach ($players as $player) {
            $statement->execute([$player['id']]);
            $memory = $this->getBotMemory($player['id']);
            $result[] = [
                'id' => $player['id'],
                'display_name' => $player['display_name'],
                'email' => $player['email'],
                'created_at' => $player['created_at'],
                'last_seen_at' => $player['last_seen_at'],
                'is_banned' => $player['is_banned'],
                'games' => (int)$statement->fetchColumn(),
                'learning_level' => $memory['learning_level'],
                'games_learned' => $memory['games_learned'],
            ];
        }
        return $result;
    }
}
