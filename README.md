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

La page de conversation est accessible via `http://localhost/Multi-Games/space-core/`. Elle partage les comptes, la session et la base SQLite du site. Pour activer le chat IA, définissez `SPACE_API_KEY` (ou `GEMINI_API_KEY`) dans l’environnement serveur. Vous pouvez choisir le modèle avec `SPACE_MODEL` ; par défaut, le code utilise `gemini-3.8-flash`.

La clé API n’est jamais envoyée au navigateur. Ne la placez pas dans les sources, les commits ou les issues publiques. Sans clé, l’interface et sa salutation restent accessibles, mais les réponses générées par IA ne sont pas disponibles.

## Données et déploiement

La base SQLite et les salons temporaires se trouvent sous `data/` et sont exclus du dépôt. Utilisez HTTPS en production, conservez les secrets dans la configuration du serveur et sauvegardez la base séparément du code.
