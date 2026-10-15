<?php
// Script de détection des changements de fichiers pour Auto-Refresh en dev sous XAMPP
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$baseDir = dirname(__DIR__);
$allowedExtensions = ['php', 'js', 'css', 'html', 'json'];

// Répertoires à ignorer (pour éviter les boucles de rechargement avec les sauvegardes de parties)
$ignoreDirs = ['.git', 'data', 'temp', 'logs'];

$maxMtime = 0;
$latestFile = '';

function scanDirectory($dir, &$maxMtime, &$latestFile, $allowedExtensions, $ignoreDirs, $baseDir) {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            if (in_array($item, $ignoreDirs, true)) continue;
            scanDirectory($path, $maxMtime, $latestFile, $allowedExtensions, $ignoreDirs, $baseDir);
        } else {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExtensions, true)) {
                $mtime = filemtime($path);
                if ($mtime > $maxMtime) {
                    $maxMtime = $mtime;
                    $latestFile = str_replace($baseDir . DIRECTORY_SEPARATOR, '', $path);
                }
            }
        }
    }
}

scanDirectory($baseDir, $maxMtime, $latestFile, $allowedExtensions, $ignoreDirs, $baseDir);

echo json_encode([
    'timestamp'  => $maxMtime,
    'latestFile' => $latestFile,
    'time'       => date('H:i:s', $maxMtime)
]);
