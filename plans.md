# Multi-Games : Architecture & Feuille de Route

Plateforme Web de jeux multijoueur (PvP) en PHP & JavaScript avec support 4K UHD et thèmes dynamiques.

---

## 📁 Structure du Projet

```text
Multi-Games/
├── index.php                 ← Hub central & catalogue des jeux (4K)
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
│       ├── index.php         ← Interface & Menu 3 cartes (exact screenshots)
│       ├── games.js          ← Logique du plateau 3x3 et 10x10, tournois, animations
│       ├── bot.js            ← IA à 5 niveaux (Novice, Moyen, Avancé, Légende, Évolutif)
│       └── api.php           ← Moteur multijoueur Wi-Fi / Réseau Local (salons JSON)
├── includes/
│   └── app.php               ← Sessions, SQLite, comptes et initialisation de l'admin
├── account.php               ← Connexion, inscription et statistiques joueur
├── admin.php                 ← Tableau de bord des comptes, parties et résultats du bot
├── data/
│   ├── multigames.sqlite     ← Comptes, activité et mémoire du bot (protégé par Apache)
│   └── rooms/                ← Stockage des salons Wi-Fi actifs
└── dev/
    ├── livereload.php        ← Détecteur de modifications de code en temps réel
    └── livereload.js         ← Rechargement automatique sous XAMPP
```

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
5. **Auto-Refresh XAMPP (Live-Reload) :**
   - Le navigateur se rafraîchit automatiquement dès que vous modifiez un fichier PHP, CSS ou JS !
6. **Comptes et administration :**
   - Inscription des joueurs, mots de passe hachés et sessions sécurisées.
   - Le compte administrateur `spacedev@thegoat.com` est créé au premier démarrage uniquement lorsque `MG_ADMIN_PASSWORD` est configuré côté serveur (16 caractères minimum). Aucun mot de passe administrateur par défaut n'est intégré au code.
   - Le tableau de bord présente les joueurs en ligne, les parties lancées/terminées et les résultats du bot par difficulté.
   - La difficulté adaptative et les coups ratés mémorisés sont enregistrés séparément pour chaque compte.