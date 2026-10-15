<?php
require_once __DIR__ . '/includes/app.php';

$user = mg_current_user();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mg_verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'La session du formulaire a expiré. Rechargez la page et réessayez.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            header('Location: account.php');
            exit;
        }

        if ($action === 'login') {
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            $now = time();
            $attempts = $_SESSION['login_attempts'] ?? ['count' => 0, 'started_at' => $now];
            if ($now - (int)$attempts['started_at'] > 900) {
                $attempts = ['count' => 0, 'started_at' => $now];
            }

            if ((int)$attempts['count'] >= 8) {
                $error = 'Trop de tentatives. Attendez 15 minutes avant de réessayer.';
            } else {
                $statement = mg_db()->prepare('SELECT id, role, is_banned, display_name, password_hash FROM users WHERE email = :email');
                $statement->execute([':email' => $email]);
                $account = $statement->fetch();

                if ($account && !(int)$account['is_banned'] && password_verify($password, $account['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int)$account['id'];
                    $_SESSION['username'] = $account['display_name'];
                    unset($_SESSION['login_attempts']);
                    header('Location: ' . ($account['role'] === 'admin' ? 'admin.php' : 'account.php'));
                    exit;
                }

                $attempts['count'] = (int)$attempts['count'] + 1;
                $_SESSION['login_attempts'] = $attempts;
                $error = 'Adresse e-mail ou mot de passe incorrect.';
            }
        } elseif ($action === 'register') {
            $name = trim((string)($_POST['display_name'] ?? ''));
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $password = (string)($_POST['password'] ?? '');

            if (mb_strlen($name) < 2 || mb_strlen($name) > 30) {
                $error = 'Le pseudo doit contenir entre 2 et 30 caractères.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                $error = 'Entrez une adresse e-mail valide.';
            } elseif (strlen($password) < 10) {
                $error = 'Choisissez un mot de passe d’au moins 10 caractères.';
            } elseif ($email === MG_ADMIN_EMAIL) {
                $error = 'Cette adresse e-mail est réservée.';
            } else {
                try {
                    $statement = mg_db()->prepare(
                        'INSERT INTO users (email, display_name, password_hash, created_at)
                         VALUES (:email, :name, :password_hash, :created_at)'
                    );
                    $statement->execute([
                        ':email' => $email,
                        ':name' => $name,
                        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        ':created_at' => time(),
                    ]);
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int)mg_db()->lastInsertId();
                    $_SESSION['username'] = $name;
                    header('Location: account.php');
                    exit;
                } catch (PDOException $exception) {
                    if ((string)$exception->getCode() === '23000') {
                        $error = 'Un compte existe déjà avec cette adresse e-mail.';
                    } else {
                        throw $exception;
                    }
                }
            }
        } else {
            http_response_code(400);
            $error = 'Action de compte inconnue.';
        }
    }
}

$user = mg_current_user();
$playerStats = null;
$botStats = null;
if ($user) {
    $statement = mg_db()->prepare(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN mode = \'bot\' THEN 1 ELSE 0 END) AS bot_games
         FROM game_sessions WHERE user_id = :user_id'
    );
    $statement->execute([':user_id' => $user['id']]);
    $playerStats = $statement->fetch();

    $statement = mg_db()->prepare(
        'SELECT learning_level, games_learned FROM bot_memory WHERE user_id = :user_id'
    );
    $statement->execute([':user_id' => $user['id']]);
    $botStats = $statement->fetch() ?: ['learning_level' => 0, 'games_learned' => 0];
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme="red">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compte joueur - Multi-Games</title>
    <link rel="stylesheet" href="assets/css/themes/themes.css">
    <link rel="stylesheet" href="assets/css/base.css">
    <script src="assets/js/theme.js"></script>
</head>
<body>
    <header class="topbar">
        <a href="index.php" class="brand-wrapper">
            <div class="brand-title">Multi-Games <span>Compte joueur</span></div>
        </a>
        <div class="topbar-actions">
            <a class="btn-topbar" href="index.php">Retour aux jeux</a>
            <?php if ($user && $user['role'] === 'admin'): ?>
                <a class="btn-topbar" href="admin.php">Administration</a>
            <?php endif; ?>
            <?php if ($user): ?>
                <form method="post" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= mg_escape(mg_csrf_token()) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="btn-topbar" type="submit">Déconnexion</button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <main class="main-container account-page">
        <?php if ($error !== ''): ?>
            <div class="account-alert" role="alert"><?= mg_escape($error) ?></div>
        <?php endif; ?>
        <?php if ($user): ?>
            <section class="account-card">
                <div class="hub-tag">Compte <?= $user['role'] === 'admin' ? 'administrateur' : 'joueur' ?></div>
                <h1 class="card-title">Bienvenue, <?= mg_escape($user['display_name']) ?></h1>
                <p class="card-desc"><?= mg_escape($user['email']) ?></p>
                <?php if ($user['role'] === 'admin'): ?>
                    <a class="btn-primary-action account-action" href="admin.php">Ouvrir le tableau de bord</a>
                <?php else: ?>
                    <div class="account-stat-grid">
                        <div class="account-stat"><strong><?= (int)$playerStats['total'] ?></strong><span>Parties lancées</span></div>
                        <div class="account-stat"><strong><?= (int)$playerStats['completed'] ?></strong><span>Parties terminées</span></div>
                        <div class="account-stat"><strong><?= (int)$playerStats['bot_games'] ?></strong><span>Duels contre un bot</span></div>
                        <div class="account-stat"><strong><?= (int)$botStats['games_learned'] ?></strong><span>Parties apprises par le bot évolutif</span></div>
                    </div>
                    <p class="account-note">Le bot mémorise vos parties et évite progressivement les coups qui l’ont fait perdre. Cette mémoire est propre à votre compte.</p>
                    <a class="btn-primary-action account-action" href="games/morpion/index.php">Jouer une partie</a>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="account-card">
                <div class="hub-tag">Espace joueur</div>
                <h1 class="hub-title">Connexion <span>/ Inscription</span></h1>
                <p class="hub-subtitle account-intro">Connectez-vous pour suivre vos parties et conserver l’apprentissage du bot sur votre compte.</p>
                <div class="account-forms">
                    <form method="post" class="account-form">
                        <h2 class="card-title">Se connecter</h2>
                        <input type="hidden" name="csrf_token" value="<?= mg_escape(mg_csrf_token()) ?>">
                        <input type="hidden" name="action" value="login">
                        <label for="login-email">Adresse e-mail</label>
                        <input id="login-email" name="email" type="email" autocomplete="username" required maxlength="190">
                        <label for="login-password">Mot de passe</label>
                        <input id="login-password" name="password" type="password" autocomplete="current-password" required>
                        <button class="btn-primary-action" type="submit">Connexion</button>
                    </form>
                    <form method="post" class="account-form">
                        <h2 class="card-title">Créer un compte</h2>
                        <input type="hidden" name="csrf_token" value="<?= mg_escape(mg_csrf_token()) ?>">
                        <input type="hidden" name="action" value="register">
                        <label for="register-name">Pseudo</label>
                        <input id="register-name" name="display_name" type="text" autocomplete="nickname" required minlength="2" maxlength="30">
                        <label for="register-email">Adresse e-mail</label>
                        <input id="register-email" name="email" type="email" autocomplete="email" required maxlength="190">
                        <label for="register-password">Mot de passe (10 caractères minimum)</label>
                        <input id="register-password" name="password" type="password" autocomplete="new-password" required minlength="10">
                        <button class="btn-primary-action" type="submit">Créer mon compte</button>
                    </form>
                </div>
                <p class="account-note">Le compte administrateur est réservé à son propriétaire et ne peut pas être créé depuis le formulaire.</p>
            </section>
        <?php endif; ?>
    </main>
    <footer class="footer">© 2026 Space &amp; Multi-Games</footer>
    <?php if ($user): ?>
        <script>window.MG_CSRF = <?= json_encode(mg_csrf_token()) ?>;</script>
        <script src="assets/js/account-presence.js" data-api="games/morpion/api.php" defer></script>
    <?php endif; ?>
</body>
</html>
