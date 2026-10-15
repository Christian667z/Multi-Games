<?php
// Configuration serveur : ne jamais placer la clé dans le JavaScript.
return [
 'api_key' => getenv('SPACE_API_KEY') ?: '',
 'api_url' => 'https://api.openai.com/v1/chat/completions',
 'model' => getenv('SPACE_MODEL') ?: 'gpt-4.1-mini',
 // Routes allow-listées, relatives à space-core/index.php.
 'pages' => [
  'accueil' => '../index.php',
  'compte' => '../account.php',
 ],
 'games' => [
  'morpion' => '../games/morpion/index.php',
 ],
 'grid_sizes' => ['3', '5', '10', '12', '15', '19'],
];
