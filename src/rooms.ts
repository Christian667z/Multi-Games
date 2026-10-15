import fs from 'fs';
import path from 'path';

export interface RoomPlayer {
  id: string;
  name: string;
  lastSeen: number;
}

export interface RoomMove {
  index: number;
  symbol: string;
}

export interface RoomData {
  roomId: string;
  size: number;
  winLen: number;
  targetWins: number;
  status: 'waiting' | 'playing' | 'finished' | 'abandoned';
  playerX: RoomPlayer | null;
  playerO: RoomPlayer | null;
  currentTurn: 'X' | 'O';
  startingPlayer: 'X' | 'O';
  board: (string | null)[];
  winner: string | null;
  winningCombo: number[];
  scores: { X: number; O: number; ties: number };
  rematchVotes: string[];
  moveHistory: RoomMove[];
  pendingAction: any | null;
  lastActionResult: any | null;
  tournamentWinner: string | null;
  updatedAt: number;
}

const ROOMS_DIR = path.resolve('data/rooms');

if (!fs.existsSync(ROOMS_DIR)) {
  try {
    fs.mkdirSync(ROOMS_DIR, { recursive: true });
  } catch {}
}

export function cleanOldRooms(): void {
  try {
    if (!fs.existsSync(ROOMS_DIR)) return;
    const files = fs.readdirSync(ROOMS_DIR);
    const now = Math.floor(Date.now() / 1000);
    for (const file of files) {
      if (file.endsWith('.json')) {
        const filePath = path.join(ROOMS_DIR, file);
        const stats = fs.statSync(filePath);
        if (now - Math.floor(stats.mtimeMs / 1000) > 3600) {
          try {
            fs.unlinkSync(filePath);
          } catch {}
        }
      }
    }
  } catch {}
}

export function getRoomFilePath(roomId: string): string {
  const safeId = roomId.toUpperCase().replace(/[^A-Z0-9]/g, '');
  return path.join(ROOMS_DIR, `${safeId}.json`);
}

export function getRoom(roomId: string): RoomData | null {
  const file = getRoomFilePath(roomId);
  if (!fs.existsSync(file)) return null;
  try {
    const raw = fs.readFileSync(file, 'utf-8');
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

export function saveRoom(room: RoomData): void {
  const file = getRoomFilePath(room.roomId);
  room.updatedAt = Math.floor(Date.now() / 1000);
  fs.writeFileSync(file, JSON.stringify(room, null, 2), 'utf-8');
}

export function deleteRoom(roomId: string): boolean {
  const file = getRoomFilePath(roomId);
  if (fs.existsSync(file)) {
    try {
      fs.unlinkSync(file);
      return true;
    } catch {
      return false;
    }
  }
  return false;
}

export function listAllRooms(): Array<{ id: string; status: string; players: number; last_seen: number }> {
  cleanOldRooms();
  const rooms: Array<{ id: string; status: string; players: number; last_seen: number }> = [];
  if (!fs.existsSync(ROOMS_DIR)) return rooms;
  const files = fs.readdirSync(ROOMS_DIR);
  for (const file of files) {
    if (file.endsWith('.json')) {
      const id = file.replace('.json', '');
      const room = getRoom(id);
      if (room) {
        const lastSeen = Math.max(room.playerX?.lastSeen || 0, room.playerO?.lastSeen || 0);
        rooms.push({
          id,
          status: room.status,
          players: (room.playerX ? 1 : 0) + (room.playerO ? 1 : 0),
          last_seen: lastSeen,
        });
      }
    }
  }
  return rooms;
}

export function checkBoardWinner(board: (string | null)[], size = 3, winLen = 3): { winner: string; combo: number[] } | null {
  // Rows
  for (let r = 0; r < size; r++) {
    for (let c = 0; c <= size - winLen; c++) {
      const sym = board[r * size + c];
      if (!sym) continue;
      let match = true;
      const winningCombo = [r * size + c];
      for (let k = 1; k < winLen; k++) {
        if (board[r * size + c + k] !== sym) {
          match = false;
          break;
        }
        winningCombo.push(r * size + c + k);
      }
      if (match) return { winner: sym, combo: winningCombo };
    }
  }

  // Columns
  for (let c = 0; c < size; c++) {
    for (let r = 0; r <= size - winLen; r++) {
      const sym = board[r * size + c];
      if (!sym) continue;
      let match = true;
      const winningCombo = [r * size + c];
      for (let k = 1; k < winLen; k++) {
        if (board[(r + k) * size + c] !== sym) {
          match = false;
          break;
        }
        winningCombo.push((r + k) * size + c);
      }
      if (match) return { winner: sym, combo: winningCombo };
    }
  }

  // Diagonal \
  for (let r = 0; r <= size - winLen; r++) {
    for (let c = 0; c <= size - winLen; c++) {
      const sym = board[r * size + c];
      if (!sym) continue;
      let match = true;
      const winningCombo = [r * size + c];
      for (let k = 1; k < winLen; k++) {
        if (board[(r + k) * size + (c + k)] !== sym) {
          match = false;
          break;
        }
        winningCombo.push((r + k) * size + (c + k));
      }
      if (match) return { winner: sym, combo: winningCombo };
    }
  }

  // Diagonal /
  for (let r = 0; r <= size - winLen; r++) {
    for (let c = winLen - 1; c < size; c++) {
      const sym = board[r * size + c];
      if (!sym) continue;
      let match = true;
      const winningCombo = [r * size + c];
      for (let k = 1; k < winLen; k++) {
        if (board[(r + k) * size + (c - k)] !== sym) {
          match = false;
          break;
        }
        winningCombo.push((r + k) * size + (c - k));
      }
      if (match) return { winner: sym, combo: winningCombo };
    }
  }

  const isFull = board.every(cell => cell !== null && cell !== '');
  if (isFull) return { winner: 'TIE', combo: [] };

  return null;
}

export function applyRoomAction(room: RoomData, action: string): void {
  room.moveHistory = room.moveHistory || [];
  const previousStatus = room.status;
  const previousWinner = room.winner;

  if (action === 'change_grid') {
    const size = Number(room.pendingAction?.size || 3);
    room.size = size;
    room.winLen = size === 3 ? 3 : size === 5 ? 4 : 5;
  }

  if (action === 'undo') {
    const lastMove = room.moveHistory.pop();
    if (lastMove) {
      room.board[lastMove.index] = null;
      room.currentTurn = lastMove.symbol as 'X' | 'O';
      room.status = 'playing';
      room.winner = null;
      room.winningCombo = [];
      room.tournamentWinner = null;
      if (previousStatus === 'finished') {
        if (previousWinner === 'X' || previousWinner === 'O') {
          room.scores[previousWinner] = Math.max(0, room.scores[previousWinner] - 1);
        } else if (previousWinner === 'TIE') {
          room.scores.ties = Math.max(0, room.scores.ties - 1);
        }
      }
    }
  } else {
    if (action === 'reset_all') {
      room.scores = { X: 0, O: 0, ties: 0 };
      room.tournamentWinner = null;
    }
    if (action === 'menu') {
      room.status = 'abandoned';
      room.pendingAction = null;
      room.updatedAt = Math.floor(Date.now() / 1000);
      return;
    }
    const nextStarter = (room.startingPlayer || 'X') === 'X' ? 'O' : 'X';
    room.startingPlayer = nextStarter;
    room.currentTurn = nextStarter;
    room.board = Array(room.size * room.size).fill(null);
    room.winner = null;
    room.winningCombo = [];
    room.tournamentWinner = null;
    room.status = room.playerO ? 'playing' : 'waiting';
    room.moveHistory = [];
  }
  room.pendingAction = null;
  room.updatedAt = Math.floor(Date.now() / 1000);
}
