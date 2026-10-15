<?php
declare(strict_types=1);

const MG_ADMIN_EMAIL = 'spacedev@thegoat.com';

function mg_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function mg_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }

    $dataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dataDir) && !mkdir($dataDir, 0770, true) && !is_dir($dataDir)) {
        throw new RuntimeException('Impossible de créer le dossier de données.');
    }

    $db = new PDO('sqlite:' . $dataDir . DIRECTORY_SEPARATOR . 'multigames.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE COLLATE NOCASE,
            display_name TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT \'player\' CHECK (role IN (\'player\', \'admin\')),
            created_at INTEGER NOT NULL,
            last_seen_at INTEGER NOT NULL DEFAULT 0
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS game_sessions (
            id TEXT PRIMARY KEY,
            session_key TEXT NOT NULL,
            user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            mode TEXT NOT NULL CHECK (mode IN (\'local\', \'bot\', \'wifi\')),
            bot_level TEXT,
            board_size INTEGER NOT NULL,
            result TEXT CHECK (result IS NULL OR result IN (\'X\', \'O\', \'TIE\')),
            started_at INTEGER NOT NULL,
            completed_at INTEGER
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS bot_memory (
            user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            learning_level INTEGER NOT NULL DEFAULT 0,
            mistakes TEXT NOT NULL DEFAULT \'[]\',
            games_learned INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL
        )'
    );
    $userColumns = $db->query('PRAGMA table_info(users)')->fetchAll();
    if (!array_filter($userColumns, static fn(array $column): bool => $column['name'] === 'is_banned')) {
        $db->exec('ALTER TABLE users ADD COLUMN is_banned INTEGER NOT NULL DEFAULT 0');
    }
    $db->exec(
        'CREATE TABLE IF NOT EXISTS game_themes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            theme_key TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            primary_color TEXT NOT NULL,
            background_color TEXT NOT NULL,
            custom INTEGER NOT NULL DEFAULT 0
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS game_symbols (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            symbol TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL,
            custom INTEGER NOT NULL DEFAULT 0
        )'
    );
    $themes = [
        ['red', 'Rouge et Noir', '#dc2626', '#09090b'],
        ['amber', 'Ambre / Or', '#d97706', '#0c0e12'],
        ['purple', 'Violet', '#9333ea', '#090710'],
        ['cyan', 'Cyan Électrique', '#0891b2', '#060e14'],
        ['emerald', 'Émeraude', '#059669', '#05100b'],
    ];
    $seedTheme = $db->prepare(
        'INSERT OR IGNORE INTO game_themes (theme_key, name, primary_color, background_color)
         VALUES (:key, :name, :primary, :background)'
    );
    foreach ($themes as [$key, $name, $primary, $background]) {
        $seedTheme->execute([
            ':key' => $key,
            ':name' => $name,
            ':primary' => $primary,
            ':background' => $background,
        ]);
    }
    $symbols = [
        ['✕', 'Croix'], ['○', 'Cercle'], ['★', 'Étoile'], ['♥', 'Cœur'],
        ['◆', 'Losange'], ['▲', 'Triangle'], ['🐱', 'Chat'], ['🐶', 'Chien'],
        ['🔥', 'Feu'], ['⚡', 'Éclair'], ['🌙', 'Lune'], ['🌟', 'Étoile brillante'],
        ['🍀', 'Trèfle'], ['👾', 'Alien'], ['🎯', 'Cible'], ['🛡️', 'Bouclier'],
    ];
    $seedSymbol = $db->prepare(
        'INSERT OR IGNORE INTO game_symbols (symbol, label)
         VALUES (:symbol, :label)'
    );
    foreach ($symbols as [$symbol, $label]) {
        $seedSymbol->execute([':symbol' => $symbol, ':label' => $label]);
    }
    $db->exec('CREATE INDEX IF NOT EXISTS idx_games_started ON game_sessions(started_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_games_user ON game_sessions(user_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_games_mode ON game_sessions(mode, bot_level)');

    $adminPassword = getenv('MG_ADMIN_PASSWORD');
    if ($adminPassword !== false && $adminPassword !== '') {
        if (strlen($adminPassword) < 16) {
            throw new RuntimeException('MG_ADMIN_PASSWORD doit contenir au moins 16 caractères.');
        }

        $seedAdmin = $db->prepare(
            'INSERT OR IGNORE INTO users (email, display_name, password_hash, role, created_at)
             VALUES (:email, :name, :password_hash, \'admin\', :created_at)'
        );
        $seedAdmin->execute([
            ':email' => MG_ADMIN_EMAIL,
            ':name' => 'Space Admin',
            ':password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
            ':created_at' => time(),
        ]);
    }

    return $db;
}

function mg_current_user(): ?array
{
    mg_start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $statement = mg_db()->prepare(
        'SELECT id, email, display_name, role, is_banned FROM users WHERE id = :id'
    );
    $statement->execute([':id' => (int)$_SESSION['user_id']]);
    $user = $statement->fetch();
    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }
    if ((int)$user['is_banned'] === 1) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }

    $_SESSION['username'] = $user['display_name'];

    return $user;
}

function mg_csrf_token(): string
{
    mg_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function mg_verify_csrf(?string $token): bool
{
    mg_start_session();
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function mg_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mg_custom_theme_css(): string
{
    $themes = mg_db()->query(
        'SELECT theme_key, primary_color, background_color FROM game_themes WHERE custom = 1'
    )->fetchAll();
    $css = '';
    foreach ($themes as $theme) {
        $key = preg_replace('/[^a-z0-9_-]/', '', $theme['theme_key']);
        $primary = preg_match('/^#[0-9a-fA-F]{6}$/', $theme['primary_color']) ? $theme['primary_color'] : '#dc2626';
        $background = preg_match('/^#[0-9a-fA-F]{6}$/', $theme['background_color']) ? $theme['background_color'] : '#09090b';
        $css .= '[data-theme="' . $key . '"]{--primary:' . $primary . ';--primary-hover:' . $primary
            . ';--bg-main:' . $background . ';--bg-gradient:radial-gradient(ellipse at 50% 0%,'
            . $primary . '22 0%,' . $background . ' 75%);--color-x:' . $primary
            . ';--win-line:' . $primary . ';--theme-color-preview:' . $primary . ';}';
    }
    return $css;
}

function mg_public_user(?array $user): ?array
{
    if (!$user) {
        return null;
    }

    return [
        'id' => (int)$user['id'],
        'email' => $user['email'],
        'display_name' => $user['display_name'],
        'role' => $user['role'],
    ];
}
