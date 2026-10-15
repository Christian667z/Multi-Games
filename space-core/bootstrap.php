<?php
require_once dirname(__DIR__) . '/includes/app.php';
mg_start_session();
$config = require __DIR__.'/config.php';
if (empty($_SESSION['space_csrf'])) $_SESSION['space_csrf'] = bin2hex(random_bytes(32));
function space_context(): array {
 $context = ['user_id'=>null,'username'=>'Invité','role'=>'guest','user_stats'=>null];
 $user = mg_current_user();
 if (!$user) return $context;

 $context['user_id']=(int)$user['id'];
 $context['username']=$user['display_name'];
 $context['role']=$user['role'];

 $statement=mg_db()->prepare(
  'SELECT COUNT(*) AS total,
          COALESCE(SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS completed,
          COALESCE(SUM(CASE WHEN mode = \'bot\' THEN 1 ELSE 0 END), 0) AS bot_games
   FROM game_sessions WHERE user_id = :user_id'
 );
 $statement->execute([':user_id'=>$user['id']]);
 $context['user_stats']=$statement->fetch();
 return $context;
}
