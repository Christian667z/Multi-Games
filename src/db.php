<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ranked.php';

final class Database
{
    private PDO $pdo;
    public const OWNER_EMAIL = 'spacedev@thegoat.com';
    public const AVAILABLE_AVATARS = ['🚀', '⚡', '🤖', '👑', '🐺', '🦊', '🐉', '🎮'];

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
        $this->migratePublicProfilesAndGameStats();
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
                game_id TEXT NOT NULL DEFAULT 'morpion',
                opponent_name TEXT NOT NULL DEFAULT '',
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            );
            CREATE INDEX IF NOT EXISTS idx_game_sessions_user_started ON game_sessions(user_id, started_at);
            CREATE TABLE IF NOT EXISTS user_game_stats (
                user_id INTEGER NOT NULL,
                game_id TEXT NOT NULL,
                xp INTEGER NOT NULL DEFAULT 0,
                level INTEGER NOT NULL DEFAULT 1,
                ranked_points INTEGER NOT NULL DEFAULT 0,
                peak_ranked_points INTEGER NOT NULL DEFAULT 0,
                ranked_games INTEGER NOT NULL DEFAULT 0,
                ranked_wins INTEGER NOT NULL DEFAULT 0,
                ranked_losses INTEGER NOT NULL DEFAULT 0,
                ranked_ties INTEGER NOT NULL DEFAULT 0,
                games INTEGER NOT NULL DEFAULT 0,
                wins INTEGER NOT NULL DEFAULT 0,
                losses INTEGER NOT NULL DEFAULT 0,
                ties INTEGER NOT NULL DEFAULT 0,
                current_streak INTEGER NOT NULL DEFAULT 0,
                best_streak INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY (user_id, game_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_user_game_stats_game_rank
                ON user_game_stats(game_id, ranked_points DESC, wins DESC);
            CREATE TABLE IF NOT EXISTS processed_game_results (
                game_session_id TEXT PRIMARY KEY,
                processed_at INTEGER NOT NULL,
                FOREIGN KEY (game_session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS user_achievements (
                user_id INTEGER NOT NULL,
                achievement_key TEXT NOT NULL,
                unlocked_at INTEGER NOT NULL,
                PRIMARY KEY (user_id, achievement_key),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS reports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reporter_id INTEGER NOT NULL,
                target_id INTEGER NOT NULL,
                reason TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                created_at INTEGER NOT NULL,
                handled_at INTEGER,
                handled_by INTEGER,
                FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (target_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
            );
            CREATE INDEX IF NOT EXISTS idx_reports_status_created ON reports(status, created_at DESC);
            CREATE TABLE IF NOT EXISTS friendships (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                requester_id INTEGER NOT NULL,
                addressee_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                created_at INTEGER NOT NULL,
                responded_at INTEGER,
                UNIQUE (requester_id, addressee_id),
                FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (addressee_id) REFERENCES users(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_friendships_addressee_status
                ON friendships(addressee_id, status, created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_friendships_requester_status
                ON friendships(requester_id, status, created_at DESC);
            CREATE TABLE IF NOT EXISTS friend_request_daily (
                user_id INTEGER NOT NULL,
                request_date TEXT NOT NULL,
                request_count INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, request_date),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
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
            CREATE TABLE IF NOT EXISTS event_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at INTEGER NOT NULL,
                category TEXT NOT NULL,
                severity TEXT NOT NULL DEFAULT 'info',
                actor_id INTEGER,
                actor_name TEXT,
                message TEXT NOT NULL,
                meta TEXT NOT NULL DEFAULT '{}'
            );
            CREATE INDEX IF NOT EXISTS idx_event_log_created ON event_log(created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_event_log_category ON event_log(category, created_at DESC);
            CREATE TABLE IF NOT EXISTS app_settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS space_conversations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_space_conversations_user ON space_conversations(user_id, updated_at DESC);
            CREATE TABLE IF NOT EXISTS space_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id INTEGER NOT NULL,
                role TEXT NOT NULL,
                content TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (conversation_id) REFERENCES space_conversations(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_space_messages_conversation ON space_messages(conversation_id, id);
            CREATE TABLE IF NOT EXISTS space_knowledge (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                keyword TEXT NOT NULL,
                content TEXT NOT NULL,
                source TEXT NOT NULL DEFAULT 'admin',
                action_json TEXT,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_space_knowledge_source_keyword
                ON space_knowledge(source, keyword COLLATE NOCASE);
            CREATE INDEX IF NOT EXISTS idx_space_knowledge_updated
                ON space_knowledge(updated_at DESC);
            CREATE TABLE IF NOT EXISTS space_usage_daily (
                usage_date TEXT NOT NULL,
                feature_key TEXT NOT NULL,
                usage_count INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (usage_date, feature_key)
            );
            CREATE INDEX IF NOT EXISTS idx_space_usage_date
                ON space_usage_daily(usage_date DESC);
            CREATE TABLE IF NOT EXISTS space_extraction_daily (
                usage_date TEXT PRIMARY KEY,
                extraction_count INTEGER NOT NULL DEFAULT 0
            );
        SQL);

        $this->ensureColumns();
    }

    /**
     * Ajout des colonnes manquantes sur les tables existantes (migration douce,
     * idempotente : on ne touche jamais aux données en place).
     */
    private function ensureColumns(): void
    {
        $required = [
            'users' => [
                'ranked_points' => 'INTEGER NOT NULL DEFAULT 0',
                'peak_ranked_points' => 'INTEGER NOT NULL DEFAULT 0',
                'ranked_games' => 'INTEGER NOT NULL DEFAULT 0',
                'ranked_wins' => 'INTEGER NOT NULL DEFAULT 0',
                'ranked_losses' => 'INTEGER NOT NULL DEFAULT 0',
                'ranked_ties' => 'INTEGER NOT NULL DEFAULT 0',
                'xp' => 'INTEGER NOT NULL DEFAULT 0',
                'level' => 'INTEGER NOT NULL DEFAULT 1',
                'season_id' => 'INTEGER NOT NULL DEFAULT 1',
                'last_context' => "TEXT NOT NULL DEFAULT ''",
                'last_context_at' => 'INTEGER NOT NULL DEFAULT 0',
                'ai_blocked' => 'INTEGER NOT NULL DEFAULT 0',
                'username' => 'TEXT',
                'bio' => "TEXT NOT NULL DEFAULT ''",
                'profile_visibility' => "TEXT NOT NULL DEFAULT 'public'",
                'show_online' => 'INTEGER NOT NULL DEFAULT 1',
                'favorite_game' => 'TEXT',
                'social_suspended' => 'INTEGER NOT NULL DEFAULT 0',
            ],
            'game_sessions' => [
                'is_ranked' => 'INTEGER NOT NULL DEFAULT 0',
                'points_delta' => 'INTEGER NOT NULL DEFAULT 0',
                'xp_delta' => 'INTEGER NOT NULL DEFAULT 0',
                'game_id' => "TEXT NOT NULL DEFAULT 'morpion'",
                'opponent_name' => "TEXT NOT NULL DEFAULT ''",
            ],
            'reports' => [
                'status' => "TEXT NOT NULL DEFAULT 'pending'",
                'handled_at' => 'INTEGER',
                'handled_by' => 'INTEGER',
            ],
            'user_game_stats' => [
                'ranked_games' => 'INTEGER NOT NULL DEFAULT 0',
                'ranked_wins' => 'INTEGER NOT NULL DEFAULT 0',
                'ranked_losses' => 'INTEGER NOT NULL DEFAULT 0',
                'ranked_ties' => 'INTEGER NOT NULL DEFAULT 0',
                'current_streak' => 'INTEGER NOT NULL DEFAULT 0',
                'best_streak' => 'INTEGER NOT NULL DEFAULT 0',
            ],
        ];

        foreach ($required as $table => $columns) {
            $existing = [];
            foreach ($this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $info) {
                $existing[(string)$info['name']] = true;
            }
            foreach ($columns as $column => $definition) {
                if (isset($existing[$column])) {
                    continue;
                }
                $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
    }

    private function migratePublicProfilesAndGameStats(): void
    {
        $migration = 'public_profiles_and_game_stats_v1';
        $check = $this->pdo->prepare('SELECT 1 FROM migrations WHERE migration = ?');
        $check->execute([$migration]);
        if ($check->fetchColumn()) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $users = $this->pdo->query('SELECT id, display_name, username FROM users ORDER BY id')->fetchAll();
            $usernameUpdate = $this->pdo->prepare('UPDATE users SET username = ? WHERE id = ?');
            foreach ($users as $row) {
                if (is_string($row['username']) && preg_match('/^[a-z0-9_]{3,20}$/i', $row['username'])) {
                    continue;
                }
                $usernameUpdate->execute([$this->makeUniqueUsername((string)$row['display_name'], (int)$row['id']), (int)$row['id']]);
            }

            $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users(username COLLATE NOCASE)');
            $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_display_name ON users(display_name COLLATE NOCASE)');

            $now = time();
            $backfill = $this->pdo->prepare(
                "INSERT OR IGNORE INTO user_game_stats " .
                "(user_id, game_id, xp, level, ranked_points, peak_ranked_points, ranked_games, ranked_wins, ranked_losses, ranked_ties, games, wins, losses, ties, updated_at) " .
                "SELECT u.id, 'morpion', u.xp, u.level, u.ranked_points, u.peak_ranked_points, u.ranked_games, u.ranked_wins, u.ranked_losses, u.ranked_ties, " .
                "COALESCE(SUM(gs.completed_at IS NOT NULL), 0), " .
                "COALESCE(SUM(gs.completed_at IS NOT NULL AND gs.result = COALESCE(gs.user_symbol, 'X')), 0), " .
                "COALESCE(SUM(gs.completed_at IS NOT NULL AND gs.result NOT IN ('TIE', COALESCE(gs.user_symbol, 'X'))), 0), " .
                "COALESCE(SUM(gs.completed_at IS NOT NULL AND gs.result = 'TIE'), 0), ? " .
                "FROM users u LEFT JOIN game_sessions gs ON gs.user_id = u.id AND gs.game_id = 'morpion' GROUP BY u.id"
            );
            $backfill->execute([$now]);

            $insertMigration = $this->pdo->prepare('INSERT INTO migrations (migration, completed_at) VALUES (?, ?)');
            $insertMigration->execute([$migration, $now]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        foreach ($this->getAllPlayers() as $user) {
            $this->rebuildGameStreaks((int)$user['id'], 'morpion');
        }
    }

    private function rebuildGameStreaks(int $userId, string $gameId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT result, user_symbol FROM game_sessions WHERE user_id = ? AND game_id = ? " .
            "AND completed_at IS NOT NULL AND result IS NOT NULL ORDER BY started_at ASC, id ASC"
        );
        $statement->execute([$userId, $gameId]);
        $currentStreak = 0;
        $bestStreak = 0;
        foreach ($statement->fetchAll() as $game) {
            if ($game['result'] === ($game['user_symbol'] ?: 'X')) {
                $currentStreak++;
                $bestStreak = max($bestStreak, $currentStreak);
            } else {
                $currentStreak = 0;
            }
        }
        $update = $this->pdo->prepare(
            'UPDATE user_game_stats SET current_streak = ?, best_streak = ? WHERE user_id = ? AND game_id = ?'
        );
        $update->execute([$currentStreak, $bestStreak, $userId, $gameId]);
    }

    private function makeUniqueUsername(string $displayName, int $userId): string
    {
        $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($displayName)) : $displayName;
        $base = strtolower((string)($ascii === false ? '' : $ascii));
        $base = trim((string)preg_replace('/[^a-z0-9_]+/', '_', $base), '_');
        $base = substr($base, 0, 20);
        if (strlen($base) < 3) {
            $base = 'player';
        }

        $candidate = $base;
        $suffix = 1;
        $check = $this->pdo->prepare('SELECT 1 FROM users WHERE username = ? COLLATE NOCASE AND id <> ?');
        while (true) {
            $check->execute([$candidate, $userId]);
            if (!$check->fetchColumn()) {
                return $candidate;
            }
            $suffixText = (string)$suffix++;
            $candidate = substr($base, 0, 20 - strlen($suffixText) - 1) . '_' . $suffixText;
        }
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

        foreach ([
            'social_friends_enabled' => '1',
            'social_public_profiles_enabled' => '1',
            'social_default_visibility' => 'public',
            'social_friend_limit' => '200',
            'social_daily_request_limit' => '20',
            'achievement_first_win_enabled' => '1',
            'achievement_streak_10_enabled' => '1',
            'achievement_beat_legend_enabled' => '1',
        ] as $key => $value) {
            $this->setDefaultSetting($key, $value);
        }
    }

    private function setDefaultSetting(string $key, string $value): void
    {
        $statement = $this->pdo->prepare('INSERT OR IGNORE INTO app_settings (key, value, updated_at) VALUES (?, ?, ?)');
        $statement->execute([$key, $value, time()]);
    }

    private function ensureAdminUser(): void
    {
        $migration = 'admin_password_provisioning_v1';
        $statement = $this->pdo->prepare('SELECT 1 FROM migrations WHERE migration = ?');
        $statement->execute([$migration]);
        if ($statement->fetchColumn()) {
            return;
        }

        $existing = $this->getUserByEmail(self::OWNER_EMAIL);
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
            $this->createUser(self::OWNER_EMAIL, 'Space Admin', password_hash($password, PASSWORD_DEFAULT), 'admin', '👑', 'red');
        }
        $statement = $this->pdo->prepare('INSERT INTO migrations (migration, completed_at) VALUES (?, ?)');
        $statement->execute([$migration, time()]);
    }

    private function normalizeUser(array $user): array
    {
        foreach ([
            'id', 'created_at', 'last_seen_at', 'is_banned',
            'ranked_points', 'peak_ranked_points', 'ranked_games', 'ranked_wins', 'ranked_losses',
            'ranked_ties', 'xp', 'level', 'season_id', 'last_context_at', 'ai_blocked',
            'show_online', 'social_suspended',
        ] as $field) {
            if (array_key_exists($field, $user)) {
                $user[$field] = (int)$user[$field];
            }
        }
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

    public function isDisplayNameAvailable(string $displayName, ?int $excludeUserId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE display_name = ? COLLATE NOCASE';
        $params = [trim($displayName)];
        if ($excludeUserId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeUserId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return !$statement->fetchColumn();
    }

    public function isUsernameAvailable(string $username, ?int $excludeUserId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE username = ? COLLATE NOCASE';
        $params = [trim($username)];
        if ($excludeUserId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeUserId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return !$statement->fetchColumn();
    }

    public function createUser(string $email, string $displayName, string $passwordHash, string $role = 'player', string $avatar = '🚀', string $themePreference = 'red'): array
    {
        $displayName = trim($displayName);
        if (mb_strlen($displayName, 'UTF-8') < 2 || mb_strlen($displayName, 'UTF-8') > 30) {
            throw new InvalidArgumentException('Le pseudo doit contenir entre 2 et 30 caractères.');
        }
        if (!$this->isDisplayNameAvailable($displayName)) {
            throw new InvalidArgumentException('Ce pseudo est déjà utilisé. Choisissez-en un autre.');
        }
        if (!in_array($avatar, self::AVAILABLE_AVATARS, true)) {
            throw new InvalidArgumentException('Avatar invalide.');
        }
        $now = time();
        $visibility = $this->getSetting('social_default_visibility', 'public');
        if (!in_array($visibility, ['public', 'friends', 'private'], true)) {
            $visibility = 'public';
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO users (email, display_name, password_hash, role, created_at, last_seen_at, avatar, theme_preference, username, profile_visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $userId = (int)$this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM users')->fetchColumn();
        $username = $this->makeUniqueUsername($displayName, $userId);
        $statement->execute([strtolower(trim($email)), trim($displayName), $passwordHash, $role, $now, $now, $avatar, $themePreference ?: 'red', $username, $visibility]);
        $createdId = (int)$this->pdo->lastInsertId();
        $this->ensureUserGameStats($createdId, 'morpion');
        return $this->getUserById($createdId) ?? throw new RuntimeException('Le compte créé est introuvable.');
    }

    public function updateUser(int $id, array $updates): bool
    {
        $allowed = [
            'email', 'display_name', 'password_hash', 'role', 'last_seen_at', 'is_banned', 'avatar', 'theme_preference',
            'username', 'bio', 'profile_visibility', 'show_online', 'favorite_game', 'social_suspended',
        ];
        $updates = array_intersect_key($updates, array_flip($allowed));
        if (!$updates) {
            return $this->getUserById($id) !== null;
        }
        if (isset($updates['email'])) {
            $updates['email'] = strtolower(trim((string)$updates['email']));
        }
        if (isset($updates['display_name'])) {
            $updates['display_name'] = trim((string)$updates['display_name']);
            if (mb_strlen($updates['display_name'], 'UTF-8') < 2 || mb_strlen($updates['display_name'], 'UTF-8') > 30) {
                throw new InvalidArgumentException('Le pseudo doit contenir entre 2 et 30 caractères.');
            }
            if (!$this->isDisplayNameAvailable($updates['display_name'], $id)) {
                throw new InvalidArgumentException('Ce pseudo est déjà utilisé. Choisissez-en un autre.');
            }
        }
        if (isset($updates['username'])) {
            $updates['username'] = strtolower(trim((string)$updates['username']));
            if (!preg_match('/^[a-z0-9_]{3,20}$/', $updates['username'])) {
                throw new InvalidArgumentException('Le nom public doit contenir de 3 à 20 lettres, chiffres ou tirets bas.');
            }
            if (!$this->isUsernameAvailable($updates['username'], $id)) {
                throw new InvalidArgumentException('Ce nom public est déjà utilisé. Choisissez-en un autre.');
            }
        }
        if (isset($updates['bio'])) {
            $updates['bio'] = trim((string)$updates['bio']);
            if (mb_strlen($updates['bio'], 'UTF-8') > 280) {
                throw new InvalidArgumentException('La bio ne peut pas dépasser 280 caractères.');
            }
        }
        if (isset($updates['profile_visibility']) && !in_array($updates['profile_visibility'], ['public', 'friends', 'private'], true)) {
            throw new InvalidArgumentException('Visibilité de profil invalide.');
        }
        if (isset($updates['show_online'])) {
            $updates['show_online'] = (int)(bool)$updates['show_online'];
        }
        if (isset($updates['favorite_game']) && $updates['favorite_game'] !== null &&
            !in_array($updates['favorite_game'], ['morpion', 'connect4', 'pong', 'naval'], true)) {
            throw new InvalidArgumentException('Jeu favori invalide.');
        }
        if (isset($updates['avatar']) && !in_array($updates['avatar'], self::AVAILABLE_AVATARS, true)) {
            throw new InvalidArgumentException('Avatar invalide.');
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
        $rows = $this->pdo->query("SELECT * FROM users WHERE role IN ('player', 'admin') ORDER BY id")->fetchAll();
        return array_map(fn(array $row): array => $this->normalizeUser($row), $rows);
    }

    private function ensureUserGameStats(int $userId, string $gameId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO user_game_stats (user_id, game_id, updated_at) VALUES (?, ?, ?)'
        );
        $statement->execute([$userId, $gameId, time()]);
    }

    public function getUserGameProgress(int $userId, string $gameId = 'morpion'): array
    {
        $this->assertGameId($gameId);
        $this->ensureUserGameStats($userId, $gameId);
        $statement = $this->pdo->prepare('SELECT * FROM user_game_stats WHERE user_id = ? AND game_id = ?');
        $statement->execute([$userId, $gameId]);
        $stats = $statement->fetch();
        if (!$stats) {
            throw new RuntimeException('Progression de jeu introuvable après initialisation.');
        }
        foreach ([
            'user_id', 'xp', 'level', 'ranked_points', 'peak_ranked_points', 'ranked_games',
            'ranked_wins', 'ranked_losses', 'ranked_ties', 'games', 'wins',
            'losses', 'ties', 'current_streak', 'best_streak', 'updated_at',
        ] as $field) {
            $stats[$field] = (int)$stats[$field];
        }
        return $stats;
    }

    public function getProfileGameStats(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM user_game_stats WHERE user_id = ? ORDER BY game_id');
        $statement->execute([$userId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            foreach ([
                'xp', 'level', 'ranked_points', 'peak_ranked_points', 'games', 'wins',
                'losses', 'ties', 'current_streak', 'best_streak',
            ] as $field) {
                $row[$field] = (int)$row[$field];
            }
            $rank = mg_rank_for_points($row['ranked_points']);
            $row['rank'] = $rank;
            $row['ratio'] = $this->ratio($row['wins'], $row['losses'])['ratio'];
        }
        unset($row);
        return $rows;
    }

    public function recordGameProgress(string $sessionId, int $userId, string $gameId, string $outcome): void
    {
        if (!in_array($outcome, ['win', 'loss', 'tie'], true)) {
            throw new InvalidArgumentException('Résultat de progression invalide.');
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $marker = $this->pdo->prepare('INSERT OR IGNORE INTO processed_game_results (game_session_id, processed_at) VALUES (?, ?)');
            $marker->execute([$sessionId, time()]);
            if ($marker->rowCount() === 0) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return;
            }

            $stats = $this->getUserGameProgress($userId, $gameId);
            $currentStreak = $outcome === 'win' ? $stats['current_streak'] + 1 : 0;
            $bestStreak = max($stats['best_streak'], $currentStreak);
            $statement = $this->pdo->prepare(
                'UPDATE user_game_stats SET games = games + 1, wins = wins + ?, losses = losses + ?, ties = ties + ?, ' .
                'current_streak = ?, best_streak = ?, updated_at = ? WHERE user_id = ? AND game_id = ?'
            );
            $statement->execute([
                $outcome === 'win' ? 1 : 0,
                $outcome === 'loss' ? 1 : 0,
                $outcome === 'tie' ? 1 : 0,
                $currentStreak,
                $bestStreak,
                time(),
                $userId,
                $gameId,
            ]);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function completeGameSession(string $sessionId, string $result): bool
    {
        if (!in_array($result, ['X', 'O', 'TIE'], true)) {
            throw new InvalidArgumentException('Résultat de partie invalide.');
        }
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'UPDATE game_sessions SET result = ?, completed_at = ? WHERE id = ? AND completed_at IS NULL'
            );
            $statement->execute([$result, time(), $sessionId]);
            if ($statement->rowCount() === 0) {
                $this->pdo->commit();
                return false;
            }

            $game = $this->getSessionById($sessionId);
            if (!$game) {
                throw new RuntimeException('Partie introuvable après enregistrement du résultat.');
            }
            if ($game['user_id'] !== null) {
                $outcome = mg_match_outcome($game['mode'], $result, (string)($game['user_symbol'] ?: 'X'));
                $this->recordGameProgress($sessionId, (int)$game['user_id'], (string)$game['game_id'], $outcome);
                if ($outcome === 'win') {
                    $progress = $this->getUserGameProgress((int)$game['user_id'], (string)$game['game_id']);
                    if ($progress['wins'] === 1) {
                        $this->unlockAchievement((int)$game['user_id'], 'first_win');
                    }
                    if ($progress['current_streak'] >= 10) {
                        $this->unlockAchievement((int)$game['user_id'], 'streak_10');
                    }
                    if ($game['mode'] === 'bot' && $game['bot_level'] === 'legend') {
                        $this->unlockAchievement((int)$game['user_id'], 'beat_legend');
                    }
                }
            }
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function getPublicProfile(string $username, ?int $viewerId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, display_name, avatar, bio, profile_visibility, show_online, favorite_game, ' .
            'last_seen_at, last_context, role, is_banned FROM users WHERE username = ? COLLATE NOCASE AND is_banned = 0'
        );
        $statement->execute([trim($username)]);
        $profile = $statement->fetch();
        if (!$profile) {
            return null;
        }

        $ownProfile = $viewerId !== null && (int)$profile['id'] === $viewerId;
        $friends = $viewerId !== null && !$ownProfile && $this->areFriends($viewerId, (int)$profile['id']);
        $visibility = (string)$profile['profile_visibility'];
        $publicProfilesEnabled = $this->getSetting('social_public_profiles_enabled', '1') === '1';
        $fullProfileVisible = $ownProfile || ($publicProfilesEnabled && $visibility === 'public') ||
            ($visibility === 'friends' && $friends);
        $profile['id'] = (int)$profile['id'];
        $profile['is_owner'] = strcasecmp((string)($this->getUserById($profile['id'])['email'] ?? ''), self::OWNER_EMAIL) === 0;
        $profile['is_admin'] = $profile['role'] === 'admin';
        unset($profile['role'], $profile['is_banned']);

        if (!$fullProfileVisible) {
            $profile = [
                'id' => $profile['id'],
                'username' => $profile['username'],
                'display_name' => $profile['display_name'],
                'avatar' => $profile['avatar'],
                'is_owner_profile' => $ownProfile,
            ];
            return $profile;
        }

        $profile['is_owner_profile'] = $ownProfile;
        $profile['are_friends'] = $friends;
        $profile['online'] = (int)$profile['show_online'] === 1 && (int)$profile['last_seen_at'] >= time() - 90;
        $profile['last_seen_at'] = (int)$profile['show_online'] === 1 ? (int)$profile['last_seen_at'] : 0;
        if (!$profile['online']) {
            $profile['last_context'] = '';
        }
        $profile['stats'] = $this->getProfileGameStats((int)$profile['id']);
        $profile['global_level'] = mg_level_from_xp((int)($this->getUserById((int)$profile['id'])['xp'] ?? 0));
        $profile['achievements'] = $this->getUserAchievements((int)$profile['id']);
        $profile['history'] = $this->getUserGameHistory((int)$profile['id'], 10, null, true);
        return $profile;
    }

    public function areFriends(int $userId, int $otherId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM friendships WHERE status = 'accepted' AND " .
            '((requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)) LIMIT 1'
        );
        $statement->execute([$userId, $otherId, $otherId, $userId]);
        return (bool)$statement->fetchColumn();
    }

    public function getFriendshipState(int $userId, int $otherId): string
    {
        if ($userId === $otherId) {
            return 'self';
        }
        $statement = $this->pdo->prepare(
            'SELECT requester_id, addressee_id, status FROM friendships WHERE ' .
            '((requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?))'
        );
        $statement->execute([$userId, $otherId, $otherId, $userId]);
        foreach ($statement->fetchAll() as $row) {
            if ($row['status'] === 'blocked') {
                return (int)$row['requester_id'] === $userId ? 'blocked_by_you' : 'blocked';
            }
            if ($row['status'] === 'accepted') {
                return 'friends';
            }
            if ($row['status'] === 'pending') {
                return (int)$row['requester_id'] === $userId ? 'pending_outgoing' : 'pending_incoming';
            }
        }
        return 'none';
    }

    public function searchFriendCandidates(int $userId, string $query, int $limit = 20): array
    {
        if ($this->getSetting('social_public_profiles_enabled', '1') !== '1') {
            return [];
        }
        $query = trim($query);
        if (mb_strlen($query, 'UTF-8') < 2 || mb_strlen($query, 'UTF-8') > 30 || preg_match('/[\x00-\x1F\x7F]/', $query)) {
            return [];
        }
        $statement = $this->pdo->prepare(
            "SELECT u.id, u.username, u.display_name, u.avatar, u.level FROM users u " .
            "WHERE u.is_banned = 0 AND u.social_suspended = 0 AND u.id <> ? AND (" .
            "u.username LIKE ? ESCAPE '\\' COLLATE NOCASE OR u.display_name LIKE ? ESCAPE '\\' COLLATE NOCASE) " .
            'AND NOT EXISTS (SELECT 1 FROM friendships f WHERE ' .
            '((f.requester_id = ? AND f.addressee_id = u.id) OR (f.requester_id = u.id AND f.addressee_id = ?)) ' .
            "AND f.status = 'blocked') ORDER BY u.username COLLATE NOCASE LIMIT ?"
        );
        $escaped = strtr(mb_strtolower($query, 'UTF-8'), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
        $statement->bindValue(1, $userId, PDO::PARAM_INT);
        $statement->bindValue(2, '%' . $escaped . '%', PDO::PARAM_STR);
        $statement->bindValue(3, '%' . strtr($query, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%', PDO::PARAM_STR);
        $statement->bindValue(4, $userId, PDO::PARAM_INT);
        $statement->bindValue(5, $userId, PDO::PARAM_INT);
        $statement->bindValue(6, min(20, max(1, $limit)), PDO::PARAM_INT);
        return $statement->execute() ? $statement->fetchAll() : [];
    }

    public function requestFriendship(int $requesterId, int $addresseeId): string
    {
        if ($this->getSetting('social_friends_enabled', '1') !== '1') {
            throw new RuntimeException('Le système d’amis est temporairement désactivé.');
        }
        $requester = $this->getUserById($requesterId);
        if (!$requester || !empty($requester['social_suspended'])) {
            throw new RuntimeException('L’accès aux fonctions sociales est suspendu.');
        }
        if ($requesterId === $addresseeId) {
            throw new InvalidArgumentException('Vous ne pouvez pas vous ajouter vous-même.');
        }
        $target = $this->getUserById($addresseeId);
        if (!$target || $target['is_banned'] || !empty($target['social_suspended'])) {
            throw new RuntimeException('Ce joueur est introuvable.');
        }
        $this->pdo->beginTransaction();
        try {
            $state = $this->getFriendshipState($requesterId, $addresseeId);
            if ($state === 'blocked' || $state === 'blocked_by_you') {
                throw new RuntimeException('Cette demande ne peut pas être envoyée.');
            }
            if ($state === 'friends' || $state === 'pending_outgoing') {
                $this->pdo->commit();
                return $state;
            }
            if ($state === 'pending_incoming') {
                $accept = $this->pdo->prepare(
                    "UPDATE friendships SET status = 'accepted', responded_at = ? " .
                    "WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'"
                );
                $accept->execute([time(), $addresseeId, $requesterId]);
                $this->pdo->commit();
                return 'friends';
            }

            $today = date('Y-m-d');
            $quota = $this->pdo->prepare('SELECT request_count FROM friend_request_daily WHERE user_id = ? AND request_date = ?');
            $quota->execute([$requesterId, $today]);
            $sentToday = (int)($quota->fetchColumn() ?: 0);
            $dailyLimit = max(1, min(500, (int)$this->getSetting('social_daily_request_limit', '20')));
            if ($sentToday >= $dailyLimit) {
                throw new RuntimeException('Vous avez atteint la limite de ' . $dailyLimit . ' demandes d’amitié par jour.');
            }
            $friendLimit = max(1, min(10000, (int)$this->getSetting('social_friend_limit', '200')));
            $friendCount = $this->pdo->prepare(
                "SELECT COUNT(*) FROM friendships WHERE status = 'accepted' AND (requester_id = ? OR addressee_id = ?)"
            );
            $friendCount->execute([$requesterId, $requesterId]);
            if ((int)$friendCount->fetchColumn() >= $friendLimit) {
                throw new RuntimeException('Vous avez atteint la limite de ' . $friendLimit . ' amis.');
            }
            $insert = $this->pdo->prepare(
                "INSERT INTO friendships (requester_id, addressee_id, status, created_at) VALUES (?, ?, 'pending', ?)"
            );
            $insert->execute([$requesterId, $addresseeId, time()]);
            $increment = $this->pdo->prepare(
                'INSERT INTO friend_request_daily (user_id, request_date, request_count) VALUES (?, ?, 1) ' .
                'ON CONFLICT(user_id, request_date) DO UPDATE SET request_count = request_count + 1'
            );
            $increment->execute([$requesterId, $today]);
            $this->pdo->commit();
            return 'pending_outgoing';
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function respondToFriendRequest(int $userId, int $requesterId, bool $accept): bool
    {
        if ($accept) {
            $user = $this->getUserById($userId);
            $requester = $this->getUserById($requesterId);
            if (!$user || !$requester || !empty($user['social_suspended']) || !empty($requester['social_suspended'])) {
                throw new RuntimeException('Cette relation sociale ne peut pas être acceptée.');
            }
            $limit = max(1, min(10000, (int)$this->getSetting('social_friend_limit', '200')));
            $count = $this->pdo->prepare(
                "SELECT COUNT(*) FROM friendships WHERE status = 'accepted' AND (requester_id = ? OR addressee_id = ?)"
            );
            foreach ([$userId, $requesterId] as $memberId) {
                $count->execute([$memberId, $memberId]);
                if ((int)$count->fetchColumn() >= $limit) {
                    throw new RuntimeException('La limite de ' . $limit . ' amis est atteinte.');
                }
            }
        }
        $statement = $this->pdo->prepare(
            $accept
                ? "UPDATE friendships SET status = 'accepted', responded_at = ? WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'"
                : "DELETE FROM friendships WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'"
        );
        $statement->execute($accept ? [time(), $requesterId, $userId] : [$requesterId, $userId]);
        return $statement->rowCount() > 0;
    }

    public function removeFriendship(int $userId, int $otherId): bool
    {
        $statement = $this->pdo->prepare(
            "DELETE FROM friendships WHERE status IN ('accepted', 'pending') AND " .
            '((requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?))'
        );
        $statement->execute([$userId, $otherId, $otherId, $userId]);
        return $statement->rowCount() > 0;
    }

    public function blockUser(int $userId, int $otherId): void
    {
        if ($userId === $otherId) {
            throw new InvalidArgumentException('Vous ne pouvez pas bloquer votre propre compte.');
        }
        $target = $this->getUserById($otherId);
        if (!$target || $target['is_banned']) {
            throw new RuntimeException('Ce joueur est introuvable.');
        }
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare(
                'DELETE FROM friendships WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)'
            );
            $delete->execute([$userId, $otherId, $otherId, $userId]);
            $insert = $this->pdo->prepare(
                "INSERT INTO friendships (requester_id, addressee_id, status, created_at, responded_at) " .
                "VALUES (?, ?, 'blocked', ?, ?)"
            );
            $now = time();
            $insert->execute([$userId, $otherId, $now, $now]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function listFriends(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT u.id, u.username, u.display_name, u.avatar, u.last_seen_at, u.last_context, u.show_online " .
            'FROM friendships f JOIN users u ON u.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END ' .
            "WHERE f.status = 'accepted' AND (f.requester_id = ? OR f.addressee_id = ?) AND u.is_banned = 0 " .
            'ORDER BY u.display_name COLLATE NOCASE'
        );
        $statement->execute([$userId, $userId, $userId]);
        $friends = $statement->fetchAll();
        foreach ($friends as &$friend) {
            $friend['online'] = (int)$friend['show_online'] === 1 && (int)$friend['last_seen_at'] >= time() - 90;
            $friend['id'] = (int)$friend['id'];
            $friend['last_seen_at'] = (int)$friend['last_seen_at'];
            unset($friend['show_online']);
            if (!$friend['online']) {
                $friend['last_context'] = '';
            }
        }
        unset($friend);
        return $friends;
    }

    public function listFriendRequests(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT f.requester_id AS id, f.created_at, u.username, u.display_name, u.avatar " .
            'FROM friendships f JOIN users u ON u.id = f.requester_id ' .
            "WHERE f.addressee_id = ? AND f.status = 'pending' AND u.is_banned = 0 ORDER BY f.created_at DESC"
        );
        $statement->execute([$userId]);
        return $statement->fetchAll();
    }

    public function listOutgoingFriendRequests(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT f.addressee_id AS id, f.created_at, u.username, u.display_name, u.avatar " .
            'FROM friendships f JOIN users u ON u.id = f.addressee_id ' .
            "WHERE f.requester_id = ? AND f.status = 'pending' AND u.is_banned = 0 ORDER BY f.created_at DESC"
        );
        $statement->execute([$userId]);
        return $statement->fetchAll();
    }

    public function createProfileReport(int $reporterId, int $targetId, string $reason): int
    {
        $reason = trim($reason);
        if ($reporterId === $targetId || mb_strlen($reason, 'UTF-8') < 5 || mb_strlen($reason, 'UTF-8') > 1000) {
            throw new InvalidArgumentException('Le signalement doit décrire le problème en 5 à 1 000 caractères.');
        }
        $target = $this->getUserById($targetId);
        if (!$target || $target['is_banned']) {
            throw new RuntimeException('Ce profil est introuvable.');
        }
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM reports WHERE reporter_id = ? AND target_id = ? AND status = 'pending'"
        );
        $statement->execute([$reporterId, $targetId]);
        if ($statement->fetchColumn()) {
            throw new RuntimeException('Vous avez déjà signalé ce profil.');
        }
        $insert = $this->pdo->prepare(
            "INSERT INTO reports (reporter_id, target_id, reason, status, created_at) VALUES (?, ?, ?, 'pending', ?)"
        );
        $insert->execute([$reporterId, $targetId, $reason, time()]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getSocialAdminData(): array
    {
        $stats = $this->pdo->query(
            "SELECT " .
            "(SELECT COUNT(*) FROM friendships WHERE status = 'accepted') AS friendships, " .
            "(SELECT COUNT(*) FROM friendships WHERE status = 'pending') AS pending_requests, " .
            "(SELECT COUNT(*) FROM friendships WHERE status = 'blocked') AS blocked, " .
            "(SELECT COUNT(*) FROM users u WHERE u.role = 'player' AND u.is_banned = 0 AND NOT EXISTS (" .
            "SELECT 1 FROM friendships f WHERE f.status = 'accepted' AND (f.requester_id = u.id OR f.addressee_id = u.id))) AS without_friends"
        )->fetch();
        foreach ($stats as &$value) {
            $value = (int)$value;
        }
        unset($value);

        return [
            'stats' => $stats,
            'players' => $this->getPlayersAdminList(),
            'reports' => $this->getProfileReports(),
            'achievements' => $this->getAchievementDefinitions(),
            'settings' => $this->getSettings(),
        ];
    }

    public function getProfileReports(): array
    {
        return $this->pdo->query(
            'SELECT r.id, r.reporter_id, r.target_id, r.reason, r.status, r.created_at, r.handled_at, ' .
            'reporter.username AS reporter_username, reporter.display_name AS reporter_name, ' .
            'target.username AS target_username, target.display_name AS target_name ' .
            'FROM reports r JOIN users reporter ON reporter.id = r.reporter_id ' .
            'JOIN users target ON target.id = r.target_id ' .
            'WHERE r.status = \'pending\' ORDER BY r.created_at ASC LIMIT 200'
        )->fetchAll();
    }

    public function handleProfileReport(int $reportId, int $adminId, string $status): bool
    {
        if (!in_array($status, ['resolved', 'sanctioned'], true)) {
            throw new InvalidArgumentException('État de signalement invalide.');
        }
        $statement = $this->pdo->prepare(
            "UPDATE reports SET status = ?, handled_at = ?, handled_by = ? WHERE id = ? AND status = 'pending'"
        );
        $statement->execute([$status, time(), $adminId, $reportId]);
        return $statement->rowCount() > 0;
    }

    public function getAchievementDefinitions(): array
    {
        $definitions = [
            'first_win' => ['name' => 'Première victoire', 'description' => 'Remporte ta première partie.'],
            'streak_10' => ['name' => 'Série de 10', 'description' => 'Enchaîne 10 victoires.'],
            'beat_legend' => ['name' => 'Anti-Légende', 'description' => 'Bats le bot en difficulté Légende.'],
        ];
        $result = [];
        foreach ($definitions as $key => $definition) {
            $definition['key'] = $key;
            $definition['enabled'] = $this->getSetting('achievement_' . $key . '_enabled', '1') === '1';
            $result[] = $definition;
        }
        return $result;
    }

    public function setAchievementEnabled(string $key, bool $enabled): void
    {
        $known = array_column($this->getAchievementDefinitions(), 'key');
        if (!in_array($key, $known, true)) {
            throw new InvalidArgumentException('Succès inconnu.');
        }
        $this->setSetting('achievement_' . $key . '_enabled', $enabled ? '1' : '0');
    }

    public function awardAchievement(int $userId, string $key): void
    {
        if (!$this->getUserById($userId)) {
            throw new RuntimeException('Joueur introuvable.');
        }
        $known = array_column($this->getAchievementDefinitions(), 'key');
        if (!in_array($key, $known, true)) {
            throw new InvalidArgumentException('Succès inconnu.');
        }
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO user_achievements (user_id, achievement_key, unlocked_at) VALUES (?, ?, ?)'
        );
        $statement->execute([$userId, $key, time()]);
    }

    public function getUserAchievements(int $userId): array
    {
        $definitions = [];
        foreach ($this->getAchievementDefinitions() as $definition) {
            $definitions[$definition['key']] = $definition;
        }
        $statement = $this->pdo->prepare('SELECT achievement_key, unlocked_at FROM user_achievements WHERE user_id = ?');
        $statement->execute([$userId]);
        $earned = [];
        foreach ($statement->fetchAll() as $row) {
            if (isset($definitions[$row['achievement_key']])) {
                $earned[] = $definitions[$row['achievement_key']] + [
                    'key' => $row['achievement_key'],
                    'unlocked_at' => (int)$row['unlocked_at'],
                ];
            }
        }
        return $earned;
    }

    public function unlockAchievement(int $userId, string $key): void
    {
        if (!in_array($key, array_column($this->getAchievementDefinitions(), 'key'), true)) {
            throw new InvalidArgumentException('Succès invalide.');
        }
        if ($this->getSetting('achievement_' . $key . '_enabled', '1') !== '1') {
            return;
        }
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO user_achievements (user_id, achievement_key, unlocked_at) VALUES (?, ?, ?)'
        );
        $statement->execute([$userId, $key, time()]);
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
        foreach (['user_id', 'board_size', 'started_at', 'completed_at', 'is_ranked', 'points_delta', 'xp_delta'] as $column) {
            if ($game[$column] !== null) {
                $game[$column] = (int)$game[$column];
            }
        }
        return $game;
    }

    public function createSession(array $game): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO game_sessions (id, session_key, user_id, mode, bot_level, board_size, result, started_at, completed_at, user_symbol, is_ranked, game_id, opponent_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $game['id'], $game['session_key'], $game['user_id'], $game['mode'], $game['bot_level'],
            $game['board_size'], $game['result'], $game['started_at'], $game['completed_at'],
            $game['user_symbol'] ?? 'X', !empty($game['is_ranked']) ? 1 : 0,
            $game['game_id'] ?? 'morpion', mb_substr(trim((string)($game['opponent_name'] ?? '')), 0, 30, 'UTF-8'),
        ]);
    }

    public function updateSession(string $id, array $updates): bool
    {
        $allowed = ['session_key', 'user_id', 'mode', 'bot_level', 'board_size', 'result', 'started_at', 'completed_at', 'user_symbol', 'is_ranked', 'points_delta', 'xp_delta', 'game_id', 'opponent_name'];
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

    public function getUserGameHistory(
        int $userId,
        int $limit = 50,
        ?string $gameId = null,
        bool $completedOnly = false
    ): array
    {
        $sql = 'SELECT * FROM game_sessions WHERE user_id = ?';
        if ($gameId !== null) {
            $sql .= ' AND game_id = ?';
        }
        if ($completedOnly) {
            $sql .= ' AND completed_at IS NOT NULL';
        }
        $sql .= ' ORDER BY started_at DESC LIMIT ?';
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(1, $userId, PDO::PARAM_INT);
        $limitParameter = 2;
        if ($gameId !== null) {
            $statement->bindValue(2, $gameId, PDO::PARAM_STR);
            $limitParameter = 3;
        }
        $statement->bindValue($limitParameter, $limit, PDO::PARAM_INT);
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
                'bot' => 'Contre Bot ' . ($botNames[$game['bot_level'] ?? ''] ?? $game['bot_level'] ?? 'Standard'),
                'local' => '1v1 Local (Même écran)',
                'wifi' => 'Duel Wi-Fi LAN',
                default => $game['mode'],
            };
            $history[] = [
                'id' => $game['id'],
                'mode' => $game['mode'],
                'game_id' => $game['game_id'],
                'opponent_name' => $game['opponent_name'],
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
                'is_ranked' => $game['is_ranked'],
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

    public function getUserDetailedStats(int $userId, string $gameId = 'morpion'): array
    {
        $modes = ['bot' => $this->emptyModeStats(), 'wifi' => $this->emptyModeStats(), 'local' => $this->emptyModeStats()];
        $botLevels = array_fill_keys(['novice', 'medium', 'advanced', 'legend', 'adaptive'], $this->emptyModeStats());
        $total = $completed = 0;
        $statement = $this->pdo->prepare(
            "SELECT mode, COALESCE(bot_level, 'medium') AS bot_level, COUNT(*) AS total, " .
            "COALESCE(SUM(completed_at IS NOT NULL AND result IS NOT NULL), 0) AS completed, " .
            "COALESCE(SUM(completed_at IS NOT NULL AND result = COALESCE(user_symbol, 'X')), 0) AS wins, " .
            "COALESCE(SUM(completed_at IS NOT NULL AND result NOT IN ('TIE', COALESCE(user_symbol, 'X'))), 0) AS losses, " .
            "COALESCE(SUM(completed_at IS NOT NULL AND result = 'TIE'), 0) AS ties " .
            "FROM game_sessions WHERE user_id = ? AND game_id = ? GROUP BY mode, COALESCE(bot_level, 'medium')"
        );
        $statement->execute([$userId, $gameId]);
        foreach ($statement->fetchAll() as $row) {
            $total += (int)$row['total'];
            $completed += (int)$row['completed'];
            $mode = (string)$row['mode'];
            if (!isset($modes[$mode])) {
                continue;
            }
            foreach (['total', 'wins', 'losses', 'ties'] as $field) {
                $modes[$mode][$field] += (int)$row[$field];
            }
            if ($mode === 'bot') {
                $level = (string)$row['bot_level'];
                $botLevels[$level] ??= $this->emptyModeStats();
                foreach (['total', 'wins', 'losses', 'ties'] as $field) {
                    $botLevels[$level][$field] += (int)$row[$field];
                }
            }
        }

        $progress = $this->getUserGameProgress($userId, $gameId);
        $wins = $modes['bot']['wins'] + $modes['wifi']['wins'] + $modes['local']['wins'];
        $losses = $modes['bot']['losses'] + $modes['wifi']['losses'] + $modes['local']['losses'];
        $ties = $modes['bot']['ties'] + $modes['wifi']['ties'] + $modes['local']['ties'];
        $botGames = $modes['bot']['total'];
        $wifiGames = $modes['wifi']['total'];
        $localGames = $modes['local']['total'];

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
            'total' => $total,
            'completed' => $completed,
            'wins' => $wins,
            'losses' => $losses,
            'ties' => $ties,
            'ratio' => $overallRatio['ratio'],
            'ratio_numeric' => $overallRatio['numeric'],
            'win_rate' => $finished ? (int)round($wins / $finished * 100) : 0,
            'points' => $wins * 3 + $ties,
            'current_streak' => $progress['current_streak'],
            'best_streak' => $progress['best_streak'],
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
            'INSERT OR IGNORE INTO game_sessions (id, session_key, user_id, mode, bot_level, board_size, result, started_at, completed_at, user_symbol, game_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($toAdd as [$type, $count, $result, $offset, $duration]) {
            for ($i = 0; $i < $count; $i++) {
                $started = $now - ($count - $i) * $offset;
                $sessionId = "sync_{$type}_{$userId}_{$now}_{$i}";
                $statement->execute([$sessionId, 'local_sync', $userId, 'bot', 'medium', 3, $result, $started, $started + $duration, 'X', 'morpion']);
                if ($statement->rowCount() > 0) {
                    $outcome = $result === 'X' ? 'win' : ($result === 'TIE' ? 'tie' : 'loss');
                    $this->recordGameProgress($sessionId, $userId, 'morpion', $outcome);
                }
            }
        }
        $this->rebuildGameStreaks($userId, 'morpion');
        return $this->getUserDetailedStats($userId);
    }

    public function getLeaderboard(int $limit = 50, string $gameId = 'morpion', ?int $viewerId = null): array
    {
        $statement = $this->pdo->prepare(
            "SELECT u.id, u.username, u.display_name, u.avatar, u.email, u.role, " .
            "CASE WHEN u.show_online = 1 THEN u.last_seen_at ELSE 0 END AS last_seen_at, " .
            "COALESCE(s.games, 0) AS total_games, COALESCE(s.wins, 0) AS wins, " .
            "COALESCE(s.losses, 0) AS losses, COALESCE(s.ties, 0) AS ties, " .
            "COALESCE(s.ranked_points, 0) AS ranked_points, COALESCE(s.ranked_games, 0) AS ranked_games, " .
            "COALESCE(s.ranked_wins, 0) AS ranked_wins, COALESCE(s.ranked_losses, 0) AS ranked_losses, " .
            "COALESCE(s.xp, 0) AS xp, COALESCE(s.level, 1) AS level " .
            "FROM users u LEFT JOIN user_game_stats s ON s.user_id = u.id AND s.game_id = ? " .
            "WHERE u.is_banned = 0 AND u.role IN ('player', 'admin') AND " .
            "(u.profile_visibility = 'public' OR u.id = ? OR EXISTS (" .
            "SELECT 1 FROM friendships f WHERE f.status = 'accepted' AND " .
            "((f.requester_id = ? AND f.addressee_id = u.id) OR (f.requester_id = u.id AND f.addressee_id = ?))))"
        );
        $viewerId ??= 0;
        $statement->execute([$gameId, $viewerId, $viewerId, $viewerId]);
        $entries = [];
        foreach ($statement->fetchAll() as $user) {
            foreach (['id', 'last_seen_at', 'total_games', 'wins', 'losses', 'ties', 'ranked_points', 'ranked_games', 'ranked_wins', 'ranked_losses', 'xp', 'level'] as $field) {
                $user[$field] = (int)$user[$field];
            }
            $finished = $user['wins'] + $user['losses'] + $user['ties'];
            $ratio = $this->ratio($user['wins'], $user['losses']);
            $rank = mg_rank_for_points($user['ranked_points']);
            $entries[] = [
                'id' => $user['id'], 'display_name' => $user['display_name'], 'avatar' => $user['avatar'] ?: '🚀',
                'username' => $user['username'],
                'role' => $user['role'],
                'is_owner' => strcasecmp(trim((string)$user['email']), self::OWNER_EMAIL) === 0,
                'total_games' => $user['total_games'], 'completed_games' => $finished,
                'wins' => $user['wins'], 'losses' => $user['losses'], 'ties' => $user['ties'],
                'ratio' => $ratio['ratio'],
                'win_rate' => $finished > 0 ? (int)round(($user['wins'] / $finished) * 100) : 0,
                'points' => ($user['wins'] * 3) + $user['ties'],
                'ranked_points' => $user['ranked_points'], 'ranked_games' => $user['ranked_games'],
                'ranked_wins' => $user['ranked_wins'], 'ranked_losses' => $user['ranked_losses'],
                'rank_label' => $rank['label'], 'rank_icon' => $rank['icon'], 'rank_key' => $rank['key'],
                'level' => $user['level'], 'xp' => $user['xp'],
                'ranked_win_rate' => $user['ranked_games'] > 0 ? (int)round(($user['ranked_wins'] / max(1, $user['ranked_games'])) * 100) : 0,
                'last_seen_at' => $user['last_seen_at'],
            ];
        }
        usort($entries, static fn(array $a, array $b): int =>
            ($b['ranked_points'] <=> $a['ranked_points']) ?: ($b['points'] <=> $a['points']) ?:
            ($b['wins'] <=> $a['wins']) ?: ($b['win_rate'] <=> $a['win_rate']) ?:
            ($b['completed_games'] <=> $a['completed_games'])
        );
        $entries = array_slice($entries, 0, max(0, $limit));
        foreach ($entries as $index => &$entry) {
            $entry = ['rank' => $index + 1] + $entry;
        }
        unset($entry);
        return $entries;
    }

    public function getUserRank(int $userId, string $gameId = 'morpion'): int
    {
        foreach ($this->getLeaderboard(1000, $gameId, $userId) as $entry) {
            if ($entry['id'] === $userId) {
                return $entry['rank'];
            }
        }
        return count($this->getLeaderboard(1000, $gameId, $userId)) + 1;
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

    public function getBotLevelsStats(string $gameId = 'morpion'): array
    {
        $this->assertGameId($gameId);
        $rows = $this->pdo->query(
            "SELECT COALESCE(bot_level, 'non précisé') AS bot_level, COUNT(*) AS games, " .
            "SUM(result = 'O') AS bot_wins, SUM(result = 'X') AS player_wins, SUM(result = 'TIE') AS ties " .
            "FROM game_sessions WHERE game_id = " . $this->pdo->quote($gameId) . " AND mode = 'bot' GROUP BY bot_level ORDER BY games DESC"
        )->fetchAll();
        return array_map(static function (array $row): array {
            foreach (['games', 'bot_wins', 'player_wins', 'ties'] as $field) {
                $row[$field] = (int)$row[$field];
            }
            return $row;
        }, $rows);
    }

    public function getPopularModes(?string $gameId = null): array
    {
        $sql = 'SELECT mode, COUNT(*) AS games FROM game_sessions';
        if ($gameId !== null) {
            $this->assertGameId($gameId);
            $sql .= ' WHERE game_id = ' . $this->pdo->quote($gameId);
        }
        $rows = $this->pdo->query($sql . ' GROUP BY mode ORDER BY games DESC')->fetchAll();
        return array_map(static fn(array $row): array => ['mode' => $row['mode'], 'games' => (int)$row['games']], $rows);
    }

    public function getPlayersAdminList(): array
    {
        // Une seule requête agrégée : remplace l'ancienne boucle N+1
        // (1 comptage de parties + 1 lecture bot_memory par joueur).
        $rows = $this->pdo->query(
            "SELECT u.id, u.display_name, u.username, u.bio, u.profile_visibility, u.social_suspended, " .
            "u.email, u.created_at, u.last_seen_at, u.is_banned, " .
            "COALESCE(s.ranked_points, 0) AS ranked_points, COALESCE(s.peak_ranked_points, 0) AS peak_ranked_points, " .
            "COALESCE(s.ranked_games, 0) AS ranked_games, COALESCE(s.ranked_wins, 0) AS ranked_wins, " .
            "COALESCE(s.ranked_losses, 0) AS ranked_losses, COALESCE(s.ranked_ties, 0) AS ranked_ties, " .
            "u.xp, u.level, u.last_context, u.last_context_at, u.ai_blocked, u.avatar, " .
            "COALESCE(bm.learning_level, 0) AS learning_level, " .
            "COALESCE(bm.games_learned, 0) AS games_learned, " .
            "COALESCE(gs.games, 0) AS games " .
            "FROM users u " .
            "LEFT JOIN user_game_stats s ON s.user_id = u.id AND s.game_id = 'morpion' " .
            "LEFT JOIN bot_memory bm ON bm.user_id = u.id " .
            "LEFT JOIN (SELECT user_id, COUNT(*) AS games FROM game_sessions WHERE user_id IS NOT NULL GROUP BY user_id) gs " .
            "ON gs.user_id = u.id " .
            "WHERE u.role = 'player' ORDER BY u.id"
        )->fetchAll();

        return array_map(function (array $row): array {
            foreach ([
                'id', 'created_at', 'last_seen_at', 'is_banned', 'learning_level', 'games_learned', 'games',
                'ranked_points', 'peak_ranked_points', 'ranked_games', 'ranked_wins', 'ranked_losses', 'ranked_ties',
                'xp', 'level', 'last_context_at', 'ai_blocked',
                'social_suspended',
            ] as $field) {
                $row[$field] = (int)$row[$field];
            }
            $rank = mg_rank_for_points($row['ranked_points']);
            $row['rank'] = $rank['key'];
            $row['rank_label'] = $rank['label'];
            $row['rank_icon'] = $rank['icon'];
            $row['rank_progress'] = $rank['progress'];
            $row['level_progress'] = mg_level_from_xp($row['xp'])['progress'];
            return $row;
        }, $rows);
    }

    /* ================================================================== */
    /* Mode Classé : points, XP, niveaux, saisons                          */
    /* ================================================================== */

    /** Profil classé complet d'un joueur, prêt pour l'affichage. */
    public function getRankedProfile(int $userId, string $gameId = 'morpion'): array
    {
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new RuntimeException('Utilisateur introuvable.');
        }
        $progress = $this->getUserGameProgress($userId, $gameId);
        $points = $progress['ranked_points'];
        $rank = mg_rank_for_points($points);
        $level = mg_level_from_xp($progress['xp']);
        $games = $progress['ranked_games'];

        return [
            'points' => $points,
            'peak' => $progress['peak_ranked_points'],
            'rank' => $rank,
            'level' => $level,
            'games' => $games,
            'wins' => $progress['ranked_wins'],
            'losses' => $progress['ranked_losses'],
            'ties' => $progress['ranked_ties'],
            'win_rate' => $games > 0 ? (int)round(($progress['ranked_wins'] / $games) * 100) : 0,
            'season' => (int)$this->getSetting('ranked_season_' . $gameId, $gameId === 'morpion' ? $this->getSetting('ranked_season', '1') : '1'),
            'position' => $this->getRankedPosition($userId, $gameId),
            'tiers' => mg_rank_tiers(),
        ];
    }

    /** Position du joueur au classement (par points classés). */
    public function getRankedPosition(int $userId, string $gameId = 'morpion'): int
    {
        $own = $this->getUserGameProgress($userId, $gameId);
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) + 1 FROM user_game_stats s JOIN users u ON u.id = s.user_id " .
            "WHERE s.game_id = ? AND u.role IN ('player', 'admin') AND u.is_banned = 0 AND s.ranked_points > ? " .
            "AND (u.profile_visibility = 'public' OR u.id = ? OR EXISTS (" .
            "SELECT 1 FROM friendships f WHERE f.status = 'accepted' AND " .
            "((f.requester_id = ? AND f.addressee_id = u.id) OR (f.requester_id = u.id AND f.addressee_id = ?))))"
        );
        $statement->execute([$gameId, $own['ranked_points'], $userId, $userId, $userId]);
        $row = $statement->fetchColumn();
        return max(1, (int)$row);
    }

    /**
     * Applique les récompenses d'un match classé : points, XP, niveau, saison.
     * Les points ne peuvent pas descendre sous zéro.
     *
     * @return array{points:int,points_before:int,points_after:int,xp:int,xp_before:int,xp_after:int,
     *               level_before:int,level_after:int,level_up:bool,rank_before:array,rank_after:array,
     *               outcome:string,label:string}
     */
    public function applyRankedResult(int $userId, string $mode, ?string $botLevel, string $outcome, string $gameId = 'morpion'): array
    {
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new RuntimeException('Utilisateur introuvable.');
        }

        $progress = $this->getUserGameProgress($userId, $gameId);
        $bareme = mg_ranked_bareme($mode, $botLevel, $outcome);
        $pointsBefore = $progress['ranked_points'];
        $pointsAfter = max(0, $pointsBefore + $bareme['points']);
        $xpBefore = $progress['xp'];
        $xpAfter = $xpBefore + $bareme['xp'];
        $levelBefore = mg_level_from_xp($xpBefore);
        $levelAfter = mg_level_from_xp($xpAfter);

        $this->pdo->beginTransaction();
        try {
            $updateProgress = $this->pdo->prepare(
                'UPDATE user_game_stats SET ranked_points = ?, peak_ranked_points = MAX(peak_ranked_points, ?), ' .
                'ranked_games = ranked_games + 1, ranked_wins = ranked_wins + ?, ranked_losses = ranked_losses + ?, ' .
                'ranked_ties = ranked_ties + ?, xp = ?, level = ?, updated_at = ? WHERE user_id = ? AND game_id = ?'
            );
            $updateProgress->execute([
                $pointsAfter,
                $pointsAfter,
                $outcome === 'win' ? 1 : 0,
                $outcome === 'loss' ? 1 : 0,
                $outcome === 'tie' ? 1 : 0,
                $xpAfter,
                $levelAfter['level'],
                time(),
                $userId,
                $gameId,
            ]);
            $totalXpStatement = $this->pdo->prepare('SELECT COALESCE(SUM(xp), 0) FROM user_game_stats WHERE user_id = ?');
            $totalXpStatement->execute([$userId]);
            $globalXp = (int)$totalXpStatement->fetchColumn();
            $globalLevel = mg_level_from_xp($globalXp);
            if ($gameId === 'morpion') {
                $statement = $this->pdo->prepare(
                    'UPDATE users SET ranked_points = ?, peak_ranked_points = MAX(peak_ranked_points, ?), ' .
                    'ranked_games = ranked_games + 1, ranked_wins = ranked_wins + ?, ranked_losses = ranked_losses + ?, ' .
                    'ranked_ties = ranked_ties + ?, xp = ?, level = ? WHERE id = ?'
                );
                $statement->execute([
                    $pointsAfter, $pointsAfter,
                    $outcome === 'win' ? 1 : 0,
                    $outcome === 'loss' ? 1 : 0,
                    $outcome === 'tie' ? 1 : 0,
                    $globalXp, $globalLevel['level'], $userId,
                ]);
            } else {
                $statement = $this->pdo->prepare('UPDATE users SET xp = ?, level = ? WHERE id = ?');
                $statement->execute([$globalXp, $globalLevel['level'], $userId]);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return [
            'points' => $bareme['points'],
            'points_before' => $pointsBefore,
            'points_after' => $pointsAfter,
            'xp' => $bareme['xp'],
            'xp_before' => $xpBefore,
            'xp_after' => $xpAfter,
            'level_before' => $levelBefore['level'],
            'level_after' => $levelAfter['level'],
            'level_up' => $levelAfter['level'] > $levelBefore['level'],
            'level' => $levelAfter,
            'rank_before' => mg_rank_for_points($pointsBefore),
            'rank_after' => mg_rank_for_points($pointsAfter),
            'outcome' => $outcome,
            'label' => $bareme['label'],
        ];
    }

    /** Attribue l'XP d'une partie amicale terminée. */
    public function applyFriendlyXp(int $userId, string $gameId = 'morpion'): array
    {
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new RuntimeException('Utilisateur introuvable.');
        }
        $progress = $this->getUserGameProgress($userId, $gameId);
        $gameXpAfter = $progress['xp'] + MG_XP_FRIENDLY;
        $gameLevelAfter = mg_level_from_xp($gameXpAfter);
        $xpBefore = (int)$user['xp'];
        $xpStatement = $this->pdo->prepare('UPDATE user_game_stats SET xp = ?, level = ?, updated_at = ? WHERE user_id = ? AND game_id = ?');
        $xpStatement->execute([$gameXpAfter, $gameLevelAfter['level'], time(), $userId, $gameId]);
        $sum = $this->pdo->prepare('SELECT COALESCE(SUM(xp), 0) FROM user_game_stats WHERE user_id = ?');
        $sum->execute([$userId]);
        $xpAfter = (int)$sum->fetchColumn();
        $before = mg_level_from_xp($xpBefore);
        $after = mg_level_from_xp($xpAfter);
        $statement = $this->pdo->prepare('UPDATE users SET xp = ?, level = ? WHERE id = ?');
        $statement->execute([$xpAfter, $after['level'], $userId]);

        return [
            'xp' => MG_XP_FRIENDLY,
            'xp_before' => $xpBefore,
            'xp_after' => $xpAfter,
            'level_before' => $before['level'],
            'level_after' => $after['level'],
            'level_up' => $after['level'] > $before['level'],
            'level' => $after,
        ];
    }

    /** Réinitialisation de la saison classée pour tous les joueurs. */
    public function resetRankedSeason(string $gameId = 'morpion'): array
    {
        $this->assertGameId($gameId);
        $seasonSetting = 'ranked_season_' . $gameId;
        $season = (int)$this->getSetting($seasonSetting, $gameId === 'morpion' ? $this->getSetting('ranked_season', '1') : '1') + 1;
        $affected = (int)$this->pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('player', 'admin')")->fetchColumn();
        $statement = $this->pdo->prepare(
            'UPDATE user_game_stats SET ranked_points = 0, peak_ranked_points = 0, ranked_games = 0, ' .
            'ranked_wins = 0, ranked_losses = 0, ranked_ties = 0, updated_at = ? WHERE game_id = ?'
        );
        $statement->execute([time(), $gameId]);
        if ($gameId === 'morpion') {
            $this->pdo->exec(
                'UPDATE users SET ranked_points = 0, peak_ranked_points = 0, ranked_games = 0, ' .
                'ranked_wins = 0, ranked_losses = 0, ranked_ties = 0, season_id = ' . $season .
                " WHERE role IN ('player', 'admin')"
            );
        }
        $statement = $this->pdo->prepare('UPDATE game_sessions SET is_ranked = 0 WHERE is_ranked = 1 AND game_id = ?');
        $statement->execute([$gameId]);
        $this->setSetting($seasonSetting, (string)$season);
        $this->setSetting('ranked_season_started_at_' . $gameId, (string)time());
        if ($gameId === 'morpion') {
            $this->setSetting('ranked_season', (string)$season);
            $this->setSetting('ranked_season_started_at', (string)time());
        }

        return ['season' => $season, 'affected' => $affected, 'game_id' => $gameId];
    }

    /** Meilleur joueur du classement actuel. */
    public function getTopPlayer(string $gameId = 'morpion'): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT u.id, u.display_name, u.avatar, s.ranked_points, s.ranked_wins, s.ranked_losses, s.ranked_ties, " .
            "s.ranked_games, s.xp, s.level, CASE WHEN u.show_online = 1 THEN u.last_seen_at ELSE 0 END AS last_seen_at " .
            "FROM user_game_stats s JOIN users u ON u.id = s.user_id " .
            "WHERE s.game_id = ? AND u.role IN ('player', 'admin') AND u.is_banned = 0 AND s.ranked_games > 0 " .
            'ORDER BY s.ranked_points DESC, s.ranked_wins DESC, s.xp DESC LIMIT 1'
        );
        $statement->execute([$gameId]);
        $row = $statement->fetch();
        if (!$row) {
            return null;
        }
        foreach (['id', 'ranked_points', 'ranked_wins', 'ranked_losses', 'ranked_ties', 'ranked_games', 'xp', 'level', 'last_seen_at'] as $field) {
            $row[$field] = (int)$row[$field];
        }
        $rank = mg_rank_for_points($row['ranked_points']);
        $row['rank_label'] = $rank['label'];
        $row['rank_icon'] = $rank['icon'];
        $row['rank_key'] = $rank['key'];
        $row['rank_progress'] = $rank['progress'];
        $row['win_rate'] = $row['ranked_games'] > 0 ? (int)round(($row['ranked_wins'] / $row['ranked_games']) * 100) : 0;
        return $row;
    }

    /** Répartition des joueurs par palier de rang. */
    public function getRankDistribution(string $gameId = 'morpion'): array
    {
        $distribution = [];
        foreach (mg_rank_tiers() as $tier) {
            $distribution[$tier['key']] = ['key' => $tier['key'], 'name' => $tier['name'], 'icon' => $tier['icon'], 'players' => 0];
        }
        $statement = $this->pdo->prepare(
            "SELECT s.ranked_points FROM user_game_stats s JOIN users u ON u.id = s.user_id " .
            "WHERE s.game_id = ? AND u.role IN ('player', 'admin') AND u.is_banned = 0"
        );
        $statement->execute([$gameId]);
        foreach ($statement->fetchAll() as $row) {
            $tier = mg_rank_for_points((int)$row['ranked_points']);
            $distribution[$tier['key']]['players']++;
        }
        return array_values($distribution);
    }

    /* ================================================================== */
    /* Présence par contexte (savoir qui joue à quoi, en direct)           */
    /* ================================================================== */

    public function updatePresenceContext(int $userId, string $context): void
    {
        $context = substr(preg_replace('/[^a-z0-9_-]/', '', strtolower($context)) ?: 'hub', 0, 30);
        $statement = $this->pdo->prepare('UPDATE users SET last_seen_at = ?, last_context = ?, last_context_at = ? WHERE id = ?');
        $now = time();
        $statement->execute([$now, $context, $now, $userId]);
    }

    /** Joueurs actuellement connectés, éventuellement filtrés sur un contexte. */
    public function getLivePlayers(?string $context = null, int $windowSeconds = 90): array
    {
        $since = time() - $windowSeconds;
        $sql = "SELECT id, display_name, avatar, role, last_seen_at, last_context, last_context_at, " .
            "ranked_points, level, xp FROM users WHERE is_banned = 0 AND last_seen_at >= ?";
        $params = [$since];
        if ($context !== null) {
            $sql .= ' AND last_context = ?';
            $params[] = $context;
        }
        $sql .= ' ORDER BY last_seen_at DESC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return array_map(static function (array $row): array {
            foreach (['id', 'last_seen_at', 'last_context_at', 'ranked_points', 'level', 'xp'] as $field) {
                $row[$field] = (int)$row[$field];
            }
            $row['online'] = true;
            return $row;
        }, $statement->fetchAll());
    }

    /* ================================================================== */
    /* Réglages applicatifs (maintenance, IA, saison)                      */
    /* ================================================================== */

    public function getSetting(string $key, ?string $default = null): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM app_settings WHERE key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        return $value === false ? $default : (string)$value;
    }

    public function setSetting(string $key, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO app_settings (key, value, updated_at) VALUES (?, ?, ?) ' .
            'ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $statement->execute([$key, $value, time()]);
    }

    public function getSettings(): array
    {
        $settings = [];
        foreach ($this->pdo->query('SELECT key, value FROM app_settings')->fetchAll() as $row) {
            $settings[(string)$row['key']] = (string)$row['value'];
        }
        return $settings;
    }

    public function getGameRankAdminStats(string $gameId): array
    {
        $this->assertGameId($gameId);
        $now = time();
        return [
            'game_id' => $gameId,
            'top_player' => $this->getTopPlayer($gameId),
            'distribution' => $this->getRankDistribution($gameId),
            'season' => (int)$this->getSetting(
                'ranked_season_' . $gameId,
                $gameId === 'morpion' ? $this->getSetting('ranked_season', '1') : '1'
            ),
            'season_started_at' => (int)$this->getSetting(
                'ranked_season_started_at_' . $gameId,
                $gameId === 'morpion' ? $this->getSetting('ranked_season_started_at', (string)$now) : (string)$now
            ),
        ];
    }

    public function isMaintenanceEnabled(): bool
    {
        return $this->getSetting('maintenance', '0') === '1';
    }

    public function isAiEnabled(): bool
    {
        return $this->getSetting('ai_enabled', '1') === '1';
    }

    /* ================================================================== */
    /* Journal des événements                                              */
    /* ================================================================== */

    public function logEvent(
        string $category,
        string $message,
        array $meta = [],
        ?int $actorId = null,
        ?string $actorName = null,
        string $severity = 'info'
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO event_log (created_at, category, severity, actor_id, actor_name, message, meta) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            time(),
            substr($category, 0, 40),
            in_array($severity, ['info', 'success', 'warning', 'danger'], true) ? $severity : 'info',
            $actorId,
            $actorName !== null ? mb_substr($actorName, 0, 60, 'UTF-8') : null,
            mb_substr($message, 0, 500, 'UTF-8'),
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        // On garde le journal borné pour ne pas faire grossir la base indéfiniment.
        $this->pdo->exec('DELETE FROM event_log WHERE id <= (SELECT MAX(id) - 2000 FROM event_log)');
    }

    public function getRecentEvents(int $limit = 60, ?string $category = null): array
    {
        $limit = max(1, min(300, $limit));
        $sql = 'SELECT * FROM event_log';
        $params = [];
        if ($category !== null && $category !== '' && $category !== 'all') {
            $sql .= ' WHERE category = ?';
            $params[] = $category;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_map(static function (array $row): array {
            $row['id'] = (int)$row['id'];
            $row['created_at'] = (int)$row['created_at'];
            $row['actor_id'] = $row['actor_id'] !== null ? (int)$row['actor_id'] : null;
            $decoded = json_decode((string)$row['meta'], true);
            $row['meta'] = is_array($decoded) ? $decoded : [];
            return $row;
        }, $statement->fetchAll());
    }

    public function getEventCategories(): array
    {
        return array_map(
            static fn(array $row): array => ['category' => $row['category'], 'events' => (int)$row['events']],
            $this->pdo->query('SELECT category, COUNT(*) AS events FROM event_log GROUP BY category ORDER BY events DESC')->fetchAll()
        );
    }

    /** Purge complète du journal (action d'urgence réservée à l'admin). */
    public function clearEvents(): int
    {
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM event_log')->fetchColumn();
        $this->pdo->exec('DELETE FROM event_log');
        return $count;
    }

    /* ================================================================== */
    /* Statistiques de plateforme (tableau de bord global)                */
    /* ================================================================== */

    public function getPlatformStats(): array
    {
        $now = time();
        $totals = $this->pdo->query(
            'SELECT COUNT(*) AS games, SUM(completed_at IS NOT NULL) AS completed, ' .
            "SUM(mode = 'bot') AS bot_games, SUM(mode = 'wifi') AS wifi_games, SUM(mode = 'local') AS local_games, " .
            'SUM(is_ranked = 1) AS ranked_games FROM game_sessions'
        )->fetch();
        $players = $this->pdo->query("SELECT COUNT(*) AS total, SUM(is_banned = 1) AS banned, SUM(last_seen_at >= " . ($now - 90) . ") AS online, COALESCE(SUM(ranked_points), 0) AS points, COALESCE(SUM(xp), 0) AS xp FROM users WHERE role IN ('player', 'admin')")->fetch();
        $morpion = $this->pdo->query(
            "SELECT COUNT(*) AS games, SUM(completed_at IS NOT NULL) AS completed, SUM(is_ranked = 1) AS ranked " .
            "FROM game_sessions WHERE mode IN ('local', 'bot', 'wifi')"
        )->fetch();

        return [
            'server_time' => $now,
            'players' => [
                'total' => (int)$players['total'],
                'banned' => (int)$players['banned'],
                'online' => (int)$players['online'],
                'points_total' => (int)$players['points'],
                'xp_total' => (int)$players['xp'],
            ],
            'games' => [
                'total' => (int)$totals['games'],
                'completed' => (int)$totals['completed'],
                'ranked' => (int)$totals['ranked_games'],
                'by_mode' => [
                    'bot' => (int)$totals['bot_games'],
                    'wifi' => (int)$totals['wifi_games'],
                    'local' => (int)$totals['local_games'],
                ],
            ],
            'morpion' => [
                'games' => (int)$morpion['games'],
                'completed' => (int)$morpion['completed'],
                'ranked' => (int)$morpion['ranked'],
                'playing_now' => count($this->getLivePlayers('morpion')),
                'in_hub' => count($this->getLivePlayers('hub')),
                'in_space' => count($this->getLivePlayers('space')),
            ],
            'season' => (int)$this->getSetting('ranked_season', '1'),
            'season_started_at' => (int)$this->getSetting('ranked_season_started_at', (string)$now),
            'maintenance' => $this->isMaintenanceEnabled(),
            'ai_enabled' => $this->isAiEnabled(),
        ];
    }

    /** Statistiques détaillées du Morpion pour le panneau admin dédié. */
    public function getMorpionAdminStats(): array
    {
        $now = time();
        $recent = $this->pdo->query(
            "SELECT COUNT(*) FROM game_sessions WHERE game_id = 'morpion' AND started_at >= " . ($now - 3600)
        )->fetchColumn();
        $last24 = $this->pdo->query(
            "SELECT COUNT(*) FROM game_sessions WHERE game_id = 'morpion' AND started_at >= " . ($now - 86400)
        )->fetchColumn();
        $rankedPlayers = $this->pdo->query(
            "SELECT COUNT(*) FROM user_game_stats s JOIN users u ON u.id = s.user_id " .
            "WHERE s.game_id = 'morpion' AND u.role = 'player' AND s.ranked_games > 0"
        )->fetchColumn();

        return [
            'playing_now' => count($this->getLivePlayers('morpion')),
            'live_players' => array_slice($this->getLivePlayers('morpion'), 0, 25),
            'context_counts' => [
                'morpion' => count($this->getLivePlayers('morpion')),
                'space' => count($this->getLivePlayers('space')),
                'hub' => count($this->getLivePlayers('hub')),
            ],
            'sessions_last_hour' => (int)$recent,
            'sessions_last_day' => (int)$last24,
            'ranked_players' => (int)$rankedPlayers,
            'ranked_sessions' => (int)$this->pdo->query("SELECT COUNT(*) FROM game_sessions WHERE game_id = 'morpion' AND is_ranked = 1")->fetchColumn(),
            'points_distributed' => (int)$this->pdo->query("SELECT COALESCE(SUM(points_delta), 0) FROM game_sessions WHERE game_id = 'morpion' AND is_ranked = 1")->fetchColumn(),
            'grid_usage' => array_map(
                static fn(array $row): array => ['board_size' => (int)$row['board_size'], 'games' => (int)$row['games']],
                $this->pdo->query("SELECT board_size, COUNT(*) AS games FROM game_sessions WHERE game_id = 'morpion' GROUP BY board_size ORDER BY games DESC")->fetchAll()
            ),
            'rooms' => function_exists('list_all_rooms') ? list_all_rooms() : [],
        ];
    }

    /** Modifie manuellement les points classés d'un joueur (admin). */
    public function setRankedPoints(int $userId, int $points, bool $resetPeak = false, string $gameId = 'morpion'): array
    {
        $this->assertGameId($gameId);
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new RuntimeException('Utilisateur introuvable.');
        }
        $progress = $this->getUserGameProgress($userId, $gameId);
        $before = $progress['ranked_points'];
        $points = max(0, min(1000000, $points));
        if ($resetPeak) {
            $statement = $this->pdo->prepare('UPDATE user_game_stats SET ranked_points = ?, peak_ranked_points = ? WHERE user_id = ? AND game_id = ?');
            $statement->execute([$points, $points, $userId, $gameId]);
        } else {
            $statement = $this->pdo->prepare('UPDATE user_game_stats SET ranked_points = ?, peak_ranked_points = MAX(peak_ranked_points, ?) WHERE user_id = ? AND game_id = ?');
            $statement->execute([$points, $points, $userId, $gameId]);
        }
        if ($gameId === 'morpion') {
            $peak = $resetPeak ? $points : max((int)$user['peak_ranked_points'], $points);
            $statement = $this->pdo->prepare('UPDATE users SET ranked_points = ?, peak_ranked_points = ? WHERE id = ?');
            $statement->execute([$points, $peak, $userId]);
        }

        return [
            'before' => $before,
            'after' => $points,
            'rank_before' => mg_rank_for_points($before),
            'rank_after' => mg_rank_for_points($points),
        ];
    }

    /** Modifie manuellement le niveau/XP d'un joueur (admin). */
    public function setPlayerXp(int $userId, int $xp, string $gameId = 'morpion'): array
    {
        $this->assertGameId($gameId);
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new RuntimeException('Utilisateur introuvable.');
        }
        $xp = max(0, min(10000000, $xp));
        $progress = $this->getUserGameProgress($userId, $gameId);
        $level = mg_level_from_xp($xp);
        $statement = $this->pdo->prepare('UPDATE user_game_stats SET xp = ?, level = ?, updated_at = ? WHERE user_id = ? AND game_id = ?');
        $statement->execute([$xp, $level['level'], time(), $userId, $gameId]);
        $totalStatement = $this->pdo->prepare('SELECT COALESCE(SUM(xp), 0) FROM user_game_stats WHERE user_id = ?');
        $totalStatement->execute([$userId]);
        $globalXp = (int)$totalStatement->fetchColumn();
        $globalLevel = mg_level_from_xp($globalXp);
        $statement = $this->pdo->prepare('UPDATE users SET xp = ?, level = ? WHERE id = ?');
        $statement->execute([$globalXp, $globalLevel['level'], $userId]);

        return ['before' => $progress['xp'], 'after' => $xp, 'level' => $level, 'global_xp' => $globalXp];
    }

    private function assertGameId(string $gameId): void
    {
        if (!in_array($gameId, ['morpion', 'connect4', 'pong', 'naval'], true)) {
            throw new InvalidArgumentException('Jeu invalide.');
        }
    }

    /* ================================================================== */
    /* Space Core : historique de conversation persisté                    */
    /* ================================================================== */

    public function createConversation(int $userId, string $title = 'Nouvelle discussion'): int
    {
        $now = time();
        $statement = $this->pdo->prepare('INSERT INTO space_conversations (user_id, title, created_at, updated_at) VALUES (?, ?, ?, ?)');
        $statement->execute([$userId, mb_substr($title, 0, 80, 'UTF-8'), $now, $now]);
        return (int)$this->pdo->lastInsertId();
    }

    public function listConversations(int $userId, int $limit = 40): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.title, c.created_at, c.updated_at, ' .
            '(SELECT COUNT(*) FROM space_messages m WHERE m.conversation_id = c.id) AS messages, ' .
            '(SELECT m.content FROM space_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS excerpt ' .
            'FROM space_conversations c WHERE c.user_id = ? ORDER BY c.updated_at DESC LIMIT ?'
        );
        $statement->bindValue(1, $userId, PDO::PARAM_INT);
        $statement->bindValue(2, max(1, min(200, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static function (array $row): array {
            $row['id'] = (int)$row['id'];
            $row['created_at'] = (int)$row['created_at'];
            $row['updated_at'] = (int)$row['updated_at'];
            $row['messages'] = (int)$row['messages'];
            $row['excerpt'] = mb_substr((string)($row['excerpt'] ?? ''), 0, 120, 'UTF-8');
            return $row;
        }, $statement->fetchAll());
    }

    /** Vérifie qu'une conversation appartient bien au joueur. */
    public function conversationBelongsTo(int $conversationId, int $userId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM space_conversations WHERE id = ? AND user_id = ?');
        $statement->execute([$conversationId, $userId]);
        return (bool)$statement->fetchColumn();
    }

    public function getConversationMessages(int $conversationId, int $limit = 200): array
    {
        $statement = $this->pdo->prepare('SELECT id, role, content, created_at FROM space_messages WHERE conversation_id = ? ORDER BY id LIMIT ?');
        $statement->bindValue(1, $conversationId, PDO::PARAM_INT);
        $statement->bindValue(2, max(1, min(500, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static function (array $row): array {
            $row['id'] = (int)$row['id'];
            $row['created_at'] = (int)$row['created_at'];
            return $row;
        }, $statement->fetchAll());
    }

    /** Derniers messages, au format attendu par ask_space(). */
    public function getConversationHistory(int $conversationId, int $limit = 12): array
    {
        $messages = $this->getConversationMessages($conversationId, 400);
        $messages = array_slice($messages, -$limit);
        return array_map(static fn(array $row): array => ['role' => $row['role'], 'content' => $row['content']], $messages);
    }

    public function appendConversationMessage(int $conversationId, string $role, string $content): int
    {
        $now = time();
        $statement = $this->pdo->prepare('INSERT INTO space_messages (conversation_id, role, content, created_at) VALUES (?, ?, ?, ?)');
        $statement->execute([$conversationId, $role === 'assistant' ? 'assistant' : 'user', $content, $now]);
        $messageId = (int)$this->pdo->lastInsertId();
        $update = $this->pdo->prepare('UPDATE space_conversations SET updated_at = ? WHERE id = ?');
        $update->execute([$now, $conversationId]);
        return $messageId;
    }

    public function renameConversation(int $conversationId, string $title): void
    {
        $statement = $this->pdo->prepare('UPDATE space_conversations SET title = ? WHERE id = ?');
        $statement->execute([mb_substr(trim($title), 0, 80, 'UTF-8'), $conversationId]);
    }

    public function deleteConversation(int $conversationId, int $userId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM space_conversations WHERE id = ? AND user_id = ?');
        $statement->execute([$conversationId, $userId]);
        return $statement->rowCount() > 0;
    }

    public function listSpaceKnowledge(int $limit = 200): array
    {
        $this->pruneCloudSpaceKnowledge();
        $statement = $this->pdo->prepare(
            "SELECT id, keyword, content, source, action_json, created_at, updated_at
             FROM space_knowledge
             ORDER BY CASE source WHEN 'admin' THEN 0 ELSE 1 END, updated_at DESC
             LIMIT ?"
        );
        $statement->bindValue(1, max(1, min(500, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return array_map(static function (array $row): array {
            $row['id'] = (int)$row['id'];
            $row['created_at'] = (int)$row['created_at'];
            $row['updated_at'] = (int)$row['updated_at'];
            $row['action'] = null;
            if (is_string($row['action_json']) && $row['action_json'] !== '') {
                $decoded = json_decode($row['action_json'], true);
                $row['action'] = is_array($decoded) ? $decoded : null;
            }
            unset($row['action_json']);
            return $row;
        }, $statement->fetchAll());
    }

    public function saveSpaceKnowledge(string $keyword, string $content, string $source = 'admin', ?array $action = null): int
    {
        if (!in_array($source, ['admin', 'groq', 'gemini', 'groq_extracted'], true)) {
            throw new InvalidArgumentException('Source de connaissance Space invalide.');
        }
        $keyword = trim($keyword);
        $content = trim($content);
        if ($keyword === '' || $content === '') {
            throw new InvalidArgumentException('Le sujet et le contenu de la connaissance Space sont obligatoires.');
        }

        $actionJson = $action === null
            ? null
            : json_encode($action, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = time();
        $find = $this->pdo->prepare('SELECT id FROM space_knowledge WHERE source = ? AND keyword = ? COLLATE NOCASE');
        $find->execute([$source, mb_substr($keyword, 0, 255, 'UTF-8')]);
        $existingId = $find->fetchColumn();
        if ($existingId !== false) {
            $update = $this->pdo->prepare(
                'UPDATE space_knowledge SET content = ?, action_json = ?, updated_at = ? WHERE id = ?'
            );
            $update->execute([mb_substr($content, 0, 5000, 'UTF-8'), $actionJson, $now, (int)$existingId]);
            if (in_array($source, ['groq', 'gemini', 'groq_extracted'], true)) {
                $this->pruneCloudSpaceKnowledge();
            }
            return (int)$existingId;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO space_knowledge (keyword, content, source, action_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            mb_substr($keyword, 0, 255, 'UTF-8'),
            mb_substr($content, 0, 5000, 'UTF-8'),
            $source,
            $actionJson,
            $now,
            $now,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        if (in_array($source, ['groq', 'gemini', 'groq_extracted'], true)) {
            $this->pruneCloudSpaceKnowledge();
        }
        return $id;
    }

    private function pruneCloudSpaceKnowledge(): void
    {
        $expire = $this->pdo->prepare(
            "DELETE FROM space_knowledge WHERE source = 'groq_extracted' AND created_at < ?"
        );
        $expire->execute([time() - 365 * 86400]);
        $this->pdo->exec(
            "DELETE FROM space_knowledge
             WHERE source IN ('groq', 'gemini', 'groq_extracted')
               AND id NOT IN (
                   SELECT id FROM space_knowledge
                   WHERE source IN ('groq', 'gemini', 'groq_extracted')
                   ORDER BY updated_at DESC
                   LIMIT 500
               )"
        );
    }

    public function deleteSpaceKnowledge(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM space_knowledge WHERE id = ?');
        $statement->execute([$id]);
        return $statement->rowCount() > 0;
    }

    public function recordSpaceUsage(string $featureKey): void
    {
        $allowedFeatures = [
            'account', 'bot', 'games', 'hub', 'leaderboard', 'morpion',
            'space', 'themes', 'wifi', 'other',
        ];
        if (!in_array($featureKey, $allowedFeatures, true)) {
            $featureKey = 'other';
        }

        $usageDate = gmdate('Y-m-d');
        $statement = $this->pdo->prepare(
            'INSERT INTO space_usage_daily (usage_date, feature_key, usage_count)
             VALUES (?, ?, 1)
             ON CONFLICT(usage_date, feature_key)
             DO UPDATE SET usage_count = usage_count + 1'
        );
        $statement->execute([$usageDate, $featureKey]);

        $cutoff = gmdate('Y-m-d', time() - 365 * 86400);
        $prune = $this->pdo->prepare('DELETE FROM space_usage_daily WHERE usage_date < ?');
        $prune->execute([$cutoff]);
    }

    public function getSpaceUsage(int $days = 365): array
    {
        $days = max(1, min(365, $days));
        $cutoff = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $statement = $this->pdo->prepare(
            'SELECT feature_key, SUM(usage_count) AS usage_count
             FROM space_usage_daily
             WHERE usage_date >= ?
             GROUP BY feature_key
             ORDER BY usage_count DESC, feature_key ASC'
        );
        $statement->execute([$cutoff]);
        return array_map(static function (array $row): array {
            $row['usage_count'] = (int)$row['usage_count'];
            return $row;
        }, $statement->fetchAll());
    }

    public function claimSpaceExtractionSlot(int $dailyLimit = 100): bool
    {
        $today = gmdate('Y-m-d');
        $statement = $this->pdo->prepare(
            'INSERT INTO space_extraction_daily (usage_date, extraction_count)
             VALUES (?, 1)
             ON CONFLICT(usage_date)
             DO UPDATE SET extraction_count = extraction_count + 1
             WHERE extraction_count < ?'
        );
        $statement->execute([$today, max(1, min(1000, $dailyLimit))]);
        $this->pdo->exec("DELETE FROM space_extraction_daily WHERE usage_date < date('now', '-7 days')");
        return $statement->rowCount() > 0;
    }

    public function findSpaceKnowledge(string $query, int $limit = 5, ?string $source = null, float $minimumScore = 0.2): array
    {
        $tokens = self::spaceKnowledgeTokens($query);
        if (!$tokens) {
            return [];
        }

        $where = '';
        if ($source === 'groq_extracted') {
            $where = 'WHERE source = ? AND created_at >= ? ';
        } elseif ($source !== null) {
            $where = 'WHERE source = ? ';
        }
        $statement = $this->pdo->prepare(
            'SELECT id, keyword, content, source, action_json, created_at, updated_at
             FROM space_knowledge ' . $where . 'ORDER BY updated_at DESC LIMIT 1000'
        );
        if ($source === 'groq_extracted') {
            $statement->execute([$source, time() - 365 * 86400]);
        } elseif ($source !== null) {
            $statement->execute([$source]);
        } else {
            $statement->execute();
        }

        $matches = [];
        foreach ($statement->fetchAll() as $row) {
            $knowledgeTokens = self::spaceKnowledgeTokens((string)$row['keyword']);
            if (!$knowledgeTokens) {
                continue;
            }
            $common = count(array_intersect($tokens, $knowledgeTokens));
            $score = $common / count($tokens);
            if ($score < $minimumScore) {
                continue;
            }
            $row['id'] = (int)$row['id'];
            $row['created_at'] = (int)$row['created_at'];
            $row['updated_at'] = (int)$row['updated_at'];
            $row['score'] = $score;
            $row['action'] = null;
            if (is_string($row['action_json']) && $row['action_json'] !== '') {
                $decoded = json_decode($row['action_json'], true);
                $row['action'] = is_array($decoded) ? $decoded : null;
            }
            unset($row['action_json']);
            $matches[] = $row;
        }

        usort($matches, static fn(array $a, array $b): int =>
            ($b['score'] <=> $a['score']) ?: ($b['updated_at'] <=> $a['updated_at'])
        );
        return array_slice($matches, 0, max(1, min(20, $limit)));
    }

    private static function spaceKnowledgeTokens(string $text): array
    {
        $normalized = mb_strtolower($text, 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopWords = [
            'avec', 'dans', 'des', 'est', 'faire', 'mais', 'mon', 'mes', 'moi', 'nous',
            'pour', 'quoi', 'que', 'quel', 'quelle', 'quels', 'quelles', 'qui', 'sur',
            'une', 'vous', 'comment', 'explique', 'parle', 'donne', 'peux', 'peut',
            'space', 'core', 'assistant', 'multi', 'games',
        ];
        return array_values(array_unique(array_filter(
            $parts,
            static fn(string $part): bool => mb_strlen($part, 'UTF-8') >= 3 && !in_array($part, $stopWords, true)
        )));
    }
}
