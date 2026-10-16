# Multi-Games

Plateforme de jeux PHP et JavaScript avec comptes SQLite, administration, salons Wi-Fi, thèmes personnalisables et Morpion contre Space. Le serveur et les pages dynamiques sont exécutés par PHP sous Apache ; Node.js n’est pas requis.

## Installation locale (XAMPP)

1. Placez le dépôt dans le dossier `htdocs` de XAMPP.
2. Activez PHP 8.1 ou plus récent avec PDO SQLite, l’extension mbstring, cURL et le module Apache `mod_rewrite`.
3. Démarrez Apache et ouvrez `http://localhost/Multi-Games/`.
4. Le dossier `data/` doit être accessible en écriture par PHP. La base SQLite `data/multigames.sqlite` est créée automatiquement et n’est pas suivie par Git.

Lors de sa première ouverture, le site importe les comptes, parties, thèmes et symboles existants depuis `data/multigames.json` si ce fichier est présent. Le fichier JSON original est conservé ; l’import ne s’effectue qu’une seule fois.

## Compte administrateur

Le compte `spacedev@thegoat.com` est créé à la première initialisation de la base seulement si la variable d’environnement serveur `MG_ADMIN_PASSWORD` est définie. Sa valeur doit contenir au moins 16 caractères. Elle n’est jamais stockée dans ce dépôt. Sans cette variable, aucun compte administrateur par défaut n’est créé.

Configurez ce secret dans l’environnement Apache avant la première visite du site, puis redémarrez Apache. Ne réutilisez pas un mot de passe d’un autre service.
Si l’import JSON contient déjà l’ancien compte administrateur, il est désactivé tant que ce secret n’est pas configuré ; au premier démarrage PHP avec le secret, son mot de passe est remplacé par celui-ci.

## Space Core

La page de conversation est accessible via `http://localhost/Multi-Games/space-core/`. Elle partage les comptes, la session et la base SQLite du site. Pour activer Groq, définissez `GROQ_API_KEY` dans l’environnement du serveur Apache, puis redémarrez Apache. Le modèle par défaut est `openai/gpt-oss-120b` ; vous pouvez le remplacer avec `SPACE_GROQ_MODEL` (ou `GROQ_MODEL`). `llama-3.3-70b-versatile` a été retiré par Groq le 16 août 2026 et ne doit plus être utilisé.

Sous XAMPP, configurez la variable côté serveur, par exemple dans un fichier de configuration Apache non suivi par Git : `SetEnv GROQ_API_KEY "votre-nouvelle-cle"`. Ne mettez jamais la clé dans PHP, JavaScript, un fichier versionné ou une capture d’écran. Une clé partagée publiquement doit être révoquée et remplacée immédiatement. En l’absence de clé Groq, l’ancien fournisseur Gemini reste disponible avec `GEMINI_API_KEY` (ou `SPACE_API_KEY`) et le modèle `SPACE_MODEL`.

Si l’appel cloud échoue — notamment sur une limite HTTP 429 — Space bascule automatiquement vers les connaissances injectées par l’administrateur et ses règles locales intégrées. Sans clé, ces réponses locales restent disponibles. Lorsqu’un fournisseur cloud est actif, le message et jusqu’aux six messages précédents sont envoyés à ce fournisseur pour générer la réponse ; n’y envoyez pas de secrets.

L’onglet « Space IA » du panneau d’administration permet d’ajouter et de supprimer des descriptions de fonctionnalités et de consulter les fiches extraites. L’extraction depuis les discussions est désactivée par défaut : le joueur doit activer « Contribuer à l’apprentissage de Space ». Après une réponse Groq, le serveur envoie alors l’échange récent à un second appel Groq pour extraire au plus deux faits techniques vérifiables, puis les ajoute automatiquement au catalogue. La question est filtrée pour éviter les sujets personnels et secrets, les courriels et numéros sont masqués, le schéma et les contenus sont validés, et les fiches extraites expirent après 365 jours (500 fiches cloud maximum). Les fiches sont réutilisées comme références partagées ; l’administrateur peut les supprimer dans le panneau. N’envoyez pas de secrets : le filtrage automatique ne peut pas garantir de reconnaître toutes les données personnelles.

Space actualise aussi automatiquement son contexte à partir des fonctionnalités intégrées et des thèmes/symboles présents dans la base. Pour une nouvelle fonctionnalité qui n’est pas stockée dans ces paramètres, décrivez-la dans le catalogue de l’onglet « Space IA » ; aucune règle PHP spéciale à Space n’est alors nécessaire. L’apprentissage automatique conserve des compteurs quotidiens par catégorie (sans texte de message ni identifiant de joueur) pendant 365 jours, et Space utilise les sujets les plus demandés pour orienter ses suggestions. Les discussions personnelles ne sont jamais extraites automatiquement sans activation explicite par le joueur.

## Profils, progression et amis

Chaque compte possède un nom public unique de 3 à 20 caractères (`a-z`, `0-9`, `_`) et une page `profile.php?u=<nom-public>`. Les anciens comptes reçoivent un identifiant public lors d’une migration SQLite idempotente. Les pseudos visibles sont également uniques, sans distinction de casse. Le profil permet de modifier le pseudo, le nom public, l’avatar autorisé, la bio, le jeu favori, la visibilité et l’affichage de la présence.

Les profils peuvent être publics, réservés aux amis ou privés. Les pages publiques n’exposent jamais l’adresse e-mail ; les profils privés ne montrent que le pseudo et l’avatar. La progression est stockée par jeu dans `user_game_stats`. Les points, XP, niveaux et parties existants sont migrés vers le Morpion ; l’XP affichée au niveau du compte est le total de tous les jeux. Les classements utilisent ces statistiques stockées au lieu de recalculer l’historique séparément pour chaque joueur.

Les demandes d’amitié, acceptations, refus, retraits, blocages, recherche, liste et présence passent par `POST /api/social` avec vérification CSRF. Les comptes bannis ou suspendus socialement ne sont pas proposés ; la limite quotidienne des demandes est configurable (20 par défaut). La présence n’est visible que si le joueur l’a activée.

L’onglet « Social » du panneau administrateur permet de modérer les bios, avatars, pseudos et visibilités sans bannir un compte, de suspendre séparément son accès social, de traiter les signalements, de consulter les statistiques d’amitié, et de configurer les limites sociales. Les succès peuvent être activés ou désactivés et attribués manuellement. Les événements sociaux sont consignés dans le journal d’administration.

Les actions administratives sur les points, l’XP et les saisons classées prennent un `game_id`. Les vues « Joueur n°1 » et « Répartition par palier » disposent aussi d’un sélecteur de jeu ; les onglets des jeux non encore implémentés restent désactivés.

## Données et déploiement

La base SQLite et les salons temporaires se trouvent sous `data/` et sont exclus du dépôt. Utilisez HTTPS en production, conservez les secrets dans la configuration du serveur et sauvegardez la base séparément du code.
Les chemins `_tmp*` sont ignorés par Git et refusés par la règle Apache du dépôt. Ne déployez jamais de scripts de connexion de test, sessions temporaires, bases de test ou profils de navigateur.
