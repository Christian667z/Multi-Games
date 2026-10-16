<?php
/**
 * Space Core — interface de conversation.
 *
 * Mise en page de type assistant moderne : barre latérale d'historique
 * (tiroir sur mobile) + fil de discussion. Le rendu initial est minimal,
 * puis assets/space.js reprend la main :
 *   - operation « hello » au chargement (salutation, historiques, état de l'IA) ;
 *   - « list » / « load » / « new » / « rename » / « delete » pour l'historique ;
 *   - message simple pour le chat.
 * Toute la logique métier reste côté serveur (index.php, src/space.php) : cette
 * vue ne fait que fournir la structure et le jeton CSRF.
 *
 * Variables attendues : $csrfToken (jeton CSRF de session) et $currentTheme
 * (clé de thème pour <html data-theme>). $customThemeCss n'est pas transmis par
 * render_view('space', …) : on le tolère pour rester compatible si la route
 * venait à l'ajouter.
 */
$customThemeCss = $customThemeCss ?? '';
?>
<!DOCTYPE html>
<html lang="fr" data-theme="<?= e($currentTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="space-csrf" content="<?= e($csrfToken) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title>Space Core — Multi-Games</title>
    <meta name="description" content="Space Core - Votre compagnon et assistant IA sur Multi-Games">
    <meta name="color-scheme" content="dark">

    <link rel="stylesheet" href="../assets/css/themes/themes.css">
    <link rel="stylesheet" href="../assets/css/base.css">
    <?php if ($customThemeCss !== ''): ?>
        <style><?= $customThemeCss ?></style>
    <?php endif; ?>
    <!-- Feuille de style dédiée (chemin relatif : /space-core/assets/space.css) -->
    <link rel="stylesheet" href="assets/space.css?v=<?= (int) filemtime(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'space-core' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'space.css') ?>">
    <script src="../assets/js/theme.js"></script>
    <script src="assets/space.js?v=<?= (int) filemtime(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'space-core' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'space.js') ?>" defer></script>
</head>
<body class="sp-body">
    <div class="sp-app" id="sp-app">
        <!-- ================= Barre latérale d'historique ================= -->
        <aside class="sp-sidebar" id="sp-sidebar" aria-label="Historique des discussions">
            <div class="sp-sidebar-head">
                <span class="sp-orb" aria-hidden="true">✦</span>
                <span class="sp-brand">
                    <strong>Space Core</strong>
                    <span>Assistant Multi-Games</span>
                </span>
                <button type="button" class="sp-icon-btn" id="sidebar-close" aria-label="Replier l’historique des discussions">✕</button>
            </div>

            <button type="button" class="sp-new" id="new-chat">
                <span aria-hidden="true">＋</span>
                <span>Nouvelle discussion</span>
            </button>

            <p class="sp-history-head">
                <span id="history-label">Discussions</span>
                <span class="sp-history-count" id="history-count" aria-hidden="true">0</span>
            </p>

            <!-- Liste remplie par space.js (role="list" + role="listitem" côté JS) -->
            <div class="sp-history" id="history" role="list" aria-labelledby="history-label">
                <p class="sp-history-empty">Chargement de l’historique…</p>
            </div>

            <p class="sp-sidebar-note" id="guest-note" hidden>
                Tes discussions sont sauvegardées sur ce navigateur. <a href="../account.php">Connecte-toi</a> pour synchroniser tes prochaines discussions sur tes autres appareils.
            </p>
        </aside>

        <button type="button" class="sp-backdrop" id="sp-backdrop" aria-label="Fermer l’historique des discussions" hidden></button>

        <!-- ================= Fil de discussion + saisie ================= -->
        <main class="sp-main" id="sp-main">
            <header class="sp-topbar">
                <button type="button" class="sp-icon-btn" id="sidebar-toggle"
                        aria-label="Afficher ou masquer l’historique des discussions"
                        aria-expanded="true" aria-controls="sp-sidebar">☰</button>
                <div class="sp-topbar-title">
                    <span class="sp-orb" aria-hidden="true">✦</span>
                    <div class="sp-topbar-text">
                        <h1>Space Core</h1>
                        <p id="conv-subtitle">Ton compagnon Multi-Games</p>
                    </div>
                </div>
                <a class="btn-topbar sp-home" href="../index.php">Retour aux jeux</a>
            </header>

            <p class="sp-banner" id="ai-banner" role="status" hidden></p>

            <section class="sp-thread" id="chat" role="log" aria-live="polite" aria-relevant="additions"
                     aria-label="Conversation avec Space" tabindex="0">
                <div class="sp-empty" id="empty-state">
                    <span class="sp-empty-orb" aria-hidden="true">✦</span>
                    <h2>Bonjour, je suis Space</h2>
                    <div class="sp-md sp-empty-text" id="empty-text">
                        <p>Pose-moi une question, demande une explication de règles, ou lance directement une partie.</p>
                    </div>
                    <div class="sp-suggestions" role="group" aria-label="Suggestions de départ">
                        <button type="button" class="sp-chip" data-prompt="Lance un morpion 3x3">Lance un morpion 3x3</button>
                        <button type="button" class="sp-chip" data-prompt="Explique les niveaux du bot">Explique les niveaux du bot</button>
                        <button type="button" class="sp-chip" data-prompt="Comment jouer en Wi-Fi ?">Comment jouer en Wi-Fi ?</button>
                        <button type="button" class="sp-chip" data-prompt="Quels jeux sont disponibles sur Multi-Games ?">Quels jeux sont disponibles ?</button>
                    </div>
                </div>
            </section>

            <button type="button" class="sp-scroll-btn" id="scroll-bottom" hidden>
                <span aria-hidden="true">↓</span> Revenir en bas
            </button>

            <!-- Région vivante dédiée aux annonces courtes (état, erreurs, dictée) -->
            <p class="sp-sr-only" id="status" role="status" aria-live="polite"></p>

            <form class="sp-composer" id="form" autocomplete="off" novalidate>
                <div class="sp-composer-box">
                    <label class="sp-sr-only" for="message">Ton message pour Space</label>
                    <textarea id="message" name="message" rows="1" maxlength="4000"
                              placeholder="Écris à Space…" aria-describedby="composer-hint" spellcheck="true"></textarea>
                    <button type="button" class="sp-icon-btn" id="mic" aria-pressed="false"
                            aria-label="Dicter un message à la voix" title="Dicter un message">🎙</button>
                    <button type="submit" class="sp-send" id="send" aria-label="Envoyer le message" disabled>
                        <span aria-hidden="true">➤</span>
                    </button>
                </div>
                <div class="sp-composer-foot">
                    <label class="sp-voice" for="voice">
                        <input type="checkbox" id="voice">
                        <span id="voice-label">Lire les réponses à voix haute</span>
                    </label>
                    <label class="sp-learning" for="learn-from-chat">
                        <input type="checkbox" id="learn-from-chat">
                        <span>Contribuer à l’apprentissage de Space</span>
                    </label>
                    <button type="button" class="sp-link-btn" id="stop">Arrêter la voix</button>
                    <span class="sp-hint" id="composer-hint">
                        <kbd>Entrée</kbd> envoyer · <kbd>Maj</kbd>+<kbd>Entrée</kbd> nouvelle ligne
                    </span>
                </div>
                <p class="sp-learning-note" id="learning-note">
                    Option désactivée par défaut. Si tu l’actives, le dernier échange est envoyé à Groq pour extraire des faits sur Multi-Games ; les éléments extraits peuvent être ajoutés automatiquement aux connaissances partagées. N’inclus pas de secrets.
                </p>
            </form>
        </main>
    </div>

    <!-- Confirmation de suppression (remplacée par window.confirm si <dialog> absent) -->
    <dialog class="sp-dialog" id="confirm-dialog" aria-labelledby="confirm-title">
        <div class="sp-dialog-form">
            <h2 id="confirm-title">Supprimer la discussion</h2>
            <p id="confirm-message">Cette discussion et ses messages seront définitivement supprimés.</p>
            <div class="sp-dialog-actions">
                <button type="button" class="sp-btn" id="confirm-cancel">Annuler</button>
                <button type="button" class="sp-btn sp-btn--danger" id="confirm-ok">Supprimer</button>
            </div>
        </div>
    </dialog>

    <noscript>
        <p class="sp-noscript">Space Core a besoin de JavaScript pour afficher la conversation. Active-le puis recharge la page.</p>
    </noscript>
</body>
</html>
