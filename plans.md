# Multi-Games : Architecture & Feuille de Route

Plateforme Web de jeux multijoueur (PvP) en PHP & JavaScript avec support 4K UHD et thèmes dynamiques.

---

## 📁 Structure du Projet

```text
Multi-Games/
├── index.php                 ← Routeur HTTP, pages, API et sessions
├── assets/
│   ├── css/
│   │   ├── base.css          ← Design System 4K, glassmorphism, animations, typographie
│   │   └── themes/themes.css ← 5 thèmes (Ambre/Or, Néon Violet, Cyan, Émeraude, Rubis)
│   └── js/
│       ├── theme.js          ← Gestionnaire de thème persistant (localStorage)
│       ├── audio.js          ← Moteur audio synthétisé Web Audio API
│       ├── account-presence.js ← Actualisation de présence des comptes
│       └── admin.js          ← Actualisation du tableau de bord
├── games/
│   └── morpion/
│       ├── index.php         ← Route d’interface Morpion
│       ├── games.js          ← Logique du plateau, tournois et résultats
│       ├── bot.js            ← IA à 5 niveaux (Novice, Moyen, Avancé, Légende, Évolutif)
├── src/
│   ├── db.php                ← SQLite, migrations, comptes, profils, amis et progression
│   ├── friends.php           ← API sociale protégée par CSRF
│   ├── ranked.php            ← Barème et règles de niveaux/rangs
│   ├── rooms.php             ← Salons Wi-Fi temporaires
│   └── space.php             ← Fournisseurs et logique de Space Core
├── views/
│   ├── partials/header.php   ← En-tête partagé
│   ├── profile.php           ← Profils, progression et relations d’amitié
│   ├── account.php           ← Connexion, inscription et compte
│   ├── leaderboard.php       ← Classement public
│   └── admin.php             ← Tableau de bord administrateur
├── data/
│   ├── multigames.sqlite     ← Comptes, progression, amitiés et mémoire (non versionnée)
│   └── rooms/                ← Stockage des salons Wi-Fi actifs
```

Toutes les pages et API sont routées par `index.php` ; il n’existe pas de `includes/app.php`, de contrôleur `account.php` ou d’API PHP autonome sous `games/morpion/`.

## Profils et données sociales

- Chaque compte reçoit un `username` public unique et un `display_name` unique, avec index SQLite insensibles à la casse.
- La migration `public_profiles_and_game_stats_v1` préserve l’ancienne progression Morpion dans `user_game_stats` et affecte un identifiant public aux comptes existants.
- Les colonnes `game_id` et `opponent_name` identifient le jeu et l’adversaire des parties. Les classements lisent les statistiques agrégées par jeu, sans effectuer une lecture d’historique par compte.
- Les profils n’exposent jamais l’e-mail. Les choix de visibilité sont `public`, `friends` et `private`.
- L’API `POST /api/social` exige un jeton CSRF et gère la recherche, les demandes, l’acceptation/le refus, le retrait et le blocage. Les demandes sont limitées à 20 par jour.
- Les ressources de test dont le nom commence par `_tmp` sont ignorées par Git et refusées par Apache ; aucun outil de connexion de test ou fichier de session ne doit être déployé.

---

## 🎮 Fonctionnalités du Morpion

1. **Option 1 - Hors ligne / Local :**
   - Match amical immédiat à 2 joueurs sur le même écran / PC.
   - Mode Tournoi (Premier à 2, 3 ou 5 victoires) avec tableau des scores.
2. **Option 2 - Intelligence Artificielle (Bot) :**
   - 🐣 **Novice** : Coups 100% aléatoires.
   - 🤖 **Intermédiaire** : Joue les victoires évidentes et bloque l'adversaire.
   - 🧠 **Avancé** : Minimax avec heuristique de profondeur.
   - 👑 **Légende** : Minimax optimal invincible (impossible à battre sur 3x3).
   - 🧬 **Évolutif** : L'IA conserve son niveau adaptatif et mémorise les coups qui l'ont fait perdre, séparément pour chaque compte.
3. **Option 3 - Wi-Fi / À distance :**
   - Création de salon avec code à 4 lettres.
   - Détection automatique de l'IP Wi-Fi (`http://<IP-LOCALE>/Multi-Games/games/morpion/?room=CODE`).
   - Rejoindre une partie depuis un autre PC ou smartphone sur le même Wi-Fi.
4. **Graphismes 4K & Thèmes :**
   - Rendu net sur écrans 1080p, 1440p et 4K UHD (2160p).
   - 5 thèmes interchangeables en 1 clic (dont les 3 des captures d'écran : Ambre/Or, Violet Cyberpunk, Cyan Électrique).
5. **Comptes et administration :**
   - Inscription des joueurs, mots de passe hachés et sessions sécurisées.
   - Le compte administrateur `spacedev@thegoat.com` est créé au premier démarrage uniquement lorsque `MG_ADMIN_PASSWORD` est configuré côté serveur (16 caractères minimum). Aucun mot de passe administrateur par défaut n'est intégré au code.
   - Le tableau de bord présente les joueurs en ligne, les parties lancées/terminées et les résultats du bot par difficulté.
   - La difficulté adaptative et les coups ratés mémorisés sont enregistrés séparément pour chaque compte.