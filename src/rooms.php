<?php
declare(strict_types=1);

function room_directory(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'rooms';
}

function room_file_path(string $roomId): string
{
    $safeId = strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId));
    if (!preg_match('/^[A-Z0-9]{4}$/', $safeId)) {
        throw new InvalidArgumentException('Code de salon invalide.');
    }
    return room_directory() . DIRECTORY_SEPARATOR . $safeId . '.json';
}

function clean_old_rooms(): void
{
    $directory = room_directory();
    if (!is_dir($directory)) {
        return;
    }
    $now = time();
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        if (is_file($file) && $now - (int)filemtime($file) > 3600 && !@unlink($file)) {
            error_log('Impossible de supprimer le salon expiré : ' . basename($file));
        }
    }
}

function get_room(string $roomId): ?array
{
    $file = room_file_path($roomId);
    if (!is_file($file)) {
        return null;
    }
    $contents = file_get_contents($file);
    if ($contents === false) {
        throw new RuntimeException('Impossible de lire le salon.');
    }
    try {
        $room = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        error_log('Fichier de salon JSON invalide (' . basename($file) . '): ' . $error->getMessage());
        return null;
    }
    return is_array($room) ? $room : null;
}

function save_room(array $room): void
{
    $directory = room_directory();
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Impossible de créer le dossier des salons.');
    }
    $file = room_file_path((string)($room['roomId'] ?? ''));
    $room['updatedAt'] = time();
    $json = json_encode($room, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($file, $json, LOCK_EX) === false) {
        throw new RuntimeException('Impossible d’enregistrer le salon.');
    }
}

function delete_room(string $roomId): bool
{
    $file = room_file_path($roomId);
    if (!is_file($file)) {
        return false;
    }
    if (!unlink($file)) {
        throw new RuntimeException('Impossible de supprimer le salon.');
    }
    return true;
}

function list_all_rooms(): array
{
    clean_old_rooms();
    $rooms = [];
    foreach (glob(room_directory() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $id = strtoupper(pathinfo($file, PATHINFO_FILENAME));
        if (!preg_match('/^[A-Z0-9]{4}$/', $id)) {
            continue;
        }
        $room = get_room($id);
        if ($room) {
            $rooms[] = [
                'id' => $id,
                'status' => $room['status'] ?? 'waiting',
                'players' => (int)isset($room['playerX']) + (int)isset($room['playerO']),
                'last_seen' => max((int)($room['playerX']['lastSeen'] ?? 0), (int)($room['playerO']['lastSeen'] ?? 0)),
            ];
        }
    }
    return $rooms;
}

function check_board_winner(array $board, int $size = 3, int $winLength = 3): ?array
{
    if ($size < 1 || $winLength < 1 || $winLength > $size || count($board) !== $size * $size) {
        return null;
    }
    $directions = [[0, 1], [1, 0], [1, 1], [1, -1]];
    for ($row = 0; $row < $size; $row++) {
        for ($column = 0; $column < $size; $column++) {
            $symbol = $board[$row * $size + $column] ?? null;
            if ($symbol === null || $symbol === '') {
                continue;
            }
            foreach ($directions as [$rowStep, $columnStep]) {
                $endRow = $row + ($winLength - 1) * $rowStep;
                $endColumn = $column + ($winLength - 1) * $columnStep;
                if ($endRow < 0 || $endRow >= $size || $endColumn < 0 || $endColumn >= $size) {
                    continue;
                }
                $combo = [];
                for ($offset = 0; $offset < $winLength; $offset++) {
                    $combo[] = ($row + $offset * $rowStep) * $size + ($column + $offset * $columnStep);
                }
                if (count(array_unique(array_map(static fn(int $index): mixed => $board[$index] ?? null, $combo))) === 1) {
                    return ['winner' => (string)$symbol, 'combo' => $combo];
                }
            }
        }
    }
    foreach ($board as $cell) {
        if ($cell === null || $cell === '') {
            return null;
        }
    }
    return ['winner' => 'TIE', 'combo' => []];
}

function apply_room_action(array &$room, string $action): void
{
    $room['moveHistory'] ??= [];
    $previousStatus = $room['status'];
    $previousWinner = $room['winner'] ?? null;

    if ($action === 'change_grid') {
        $size = (int)($room['pendingAction']['size'] ?? 3);
        $room['size'] = $size;
        $room['winLen'] = $size === 3 ? 3 : ($size === 5 ? 4 : 5);
    }

    if ($action === 'undo') {
        $lastMove = array_pop($room['moveHistory']);
        if ($lastMove) {
            $room['board'][$lastMove['index']] = null;
            $room['currentTurn'] = $lastMove['symbol'];
            $room['status'] = 'playing';
            $room['winner'] = null;
            $room['winningCombo'] = [];
            $room['tournamentWinner'] = null;
            if ($previousStatus === 'finished') {
                if (in_array($previousWinner, ['X', 'O'], true)) {
                    $room['scores'][$previousWinner] = max(0, $room['scores'][$previousWinner] - 1);
                } elseif ($previousWinner === 'TIE') {
                    $room['scores']['ties'] = max(0, $room['scores']['ties'] - 1);
                }
            }
        }
    } else {
        if ($action === 'reset_all') {
            $room['scores'] = ['X' => 0, 'O' => 0, 'ties' => 0];
            $room['tournamentWinner'] = null;
        }
        if ($action === 'menu') {
            $room['status'] = 'abandoned';
            $room['pendingAction'] = null;
            $room['updatedAt'] = time();
            return;
        }
        $nextStarter = ($room['startingPlayer'] ?? 'X') === 'X' ? 'O' : 'X';
        $room['startingPlayer'] = $nextStarter;
        $room['currentTurn'] = $nextStarter;
        $room['board'] = array_fill(0, $room['size'] * $room['size'], null);
        $room['winner'] = null;
        $room['winningCombo'] = [];
        $room['tournamentWinner'] = null;
        $room['status'] = $room['playerO'] ? 'playing' : 'waiting';
        $room['moveHistory'] = [];
    }
    $room['pendingAction'] = null;
    $room['updatedAt'] = time();
}
