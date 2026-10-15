# Space Core — PHP + JavaScript

Assistant Space intégré à Multi-Games, pour XAMPP, PHP 8 et l’extension cURL.

Ouvrir http://localhost/Multi-Games/space-core/. Les liens « Parler à Space » du hub et du Morpion y conduisent. Space réutilise la session, les comptes et la base SQLite de Multi-Games ; aucune configuration de base de données distincte ni migration n’est nécessaire.

Pour activer les réponses générées par IA, définir `SPACE_API_KEY` dans l’environnement du serveur Apache et redémarrer Apache. La clé reste côté serveur ; ne pas la placer dans JavaScript ni dans un dépôt. `SPACE_MODEL` permet de choisir le modèle compatible. Sans clé, la page, le compte partagé, la salutation et la remise à zéro de la discussion fonctionnent, mais le chat signale que le fournisseur IA n’est pas configuré.

Le compte administrateur initial `spacedev@thegoat.com` n’est créé que si `MG_ADMIN_PASSWORD` est configuré dans l’environnement Apache lors du premier accès à la base. Utiliser un mot de passe unique de 16 caractères minimum ; aucun mot de passe par défaut n’est inclus dans le code.

Ne pas ouvrir index.php par double-clic. Le chat appelle un fournisseur IA sur Internet. Une clé API avec crédit disponible est nécessaire ; ChatGPT Plus ne couvre pas ces appels. Le modèle et l’URL peuvent être adaptés à un fournisseur compatible Chat Completions et JSON object. Aucun appel n’est fait avant l’envoi d’un message ; la salutation fonctionne sans clé.

## Jeux, pages et compte
Les pages autorisées et le Morpion sont déclarés dans `config.php`. Les routes sont résolues sous le répertoire Multi-Games et les tailles de grille sont validées sur le serveur. Si Space lance le Morpion, la grille demandée est appliquée et une partie contre Space démarre ; les joueurs connectés utilisent leur pseudo de compte.

Le contexte serveur récupère le nom, le rôle et les statistiques du compte connecté depuis la base existante. L’assistant ne peut pas administrer les comptes ni les parties et l’accès à une page reste soumis aux contrôles propres à cette page.

## Mémoire et voix
La mémoire contient les huit derniers échanges, côté session PHP. Elle disparaît selon l’expiration de session ou avec Nouvelle discussion ; ce n’est pas une mémoire MySQL durable. La conversation n’est pas restaurée visuellement après rechargement, mais son contexte de session reste disponible. Microphone : autorisation du navigateur, généralement localhost ou HTTPS. La transcription remplit le champ et vous cliquez Envoyer. Certains navigateurs utilisent un service distant pour la reconnaissance. La lecture vocale est désactivée par défaut.

## Portée
Chat IA, navigation autorisée, lancement de jeux configurés et voix. Aucun pouvoir administrateur par chat, aucune confirmation PVP réseau et aucun entraînement automatique ne sont implémentés. Pour intégrer la barre à plusieurs pages, adapter les chemins relatifs et charger les éléments HTML/CSS/JS correspondants.

## Avant exposition publique
Utiliser HTTPS, protéger le fichier de configuration, conserver les contrôles serveur des pages cibles et ajouter un quota par compte/IP côté serveur ou proxy. La limite incluse de 12 messages/minute par session est une protection de base, pas un quota global. Ne jamais exposer les fichiers de session ni les clés. L’API n’accepte pas de rôle ou d’historique fourni par le navigateur.

Validation : sources JavaScript vérifiées si Node est disponible. L’accès au fournisseur IA exige une clé API valide et une extension cURL active dans XAMPP.
