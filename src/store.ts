import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import bcrypt from 'bcryptjs';

export interface User {
  id: number;
  email: string;
  display_name: string;
  password_hash: string;
  role: 'player' | 'admin';
  created_at: number;
  last_seen_at: number;
  is_banned: number;
}

export interface GameSession {
  id: string;
  session_key: string;
  user_id: number | null;
  mode: 'local' | 'bot' | 'wifi';
  bot_level: string | null;
  board_size: number;
  result: 'X' | 'O' | 'TIE' | null;
  started_at: number;
  completed_at: number | null;
}

export interface BotMemory {
  user_id: number;
  learning_level: number;
  mistakes: Array<{
    board: string;
    move: number;
    size: number;
    winLength: number;
    key?: string;
    failures?: number;
  }>;
  games_learned: number;
  updated_at: number;
}

export interface GameTheme {
  id: number;
  theme_key: string;
  name: string;
  primary_color: string;
  background_color: string;
  custom: number;
}

export interface GameSymbol {
  id: number;
  symbol: string;
  label: string;
  custom: number;
}

export interface RoomPlayer {
  id: string;
  name: string;
  lastSeen: number;
}

export interface Room {
  roomId: string;
  size: number;
  winLen: number;
  targetWins: number;
  status: 'waiting' | 'playing' | 'finished' | 'abandoned';
  playerX: RoomPlayer | null;
  playerO: RoomPlayer | null;
  currentTurn: 'X' | 'O';
  startingPlayer: 'X' | 'O';
  board: Array<string | null>;
  winner: string | null;
  winningCombo: number[];
  scores: { X: number; O: number; ties: number };
  rematchVotes: string[];
  moveHistory: Array<{ index: number; symbol: string }>;
  pendingAction: {
    id: string;
    action: string;
    requesterId: string;
    requesterName: string;
    createdAt: number;
    size?: number;
  } | null;
  lastActionResult: any;
  tournamentWinner: string | null;
  updatedAt: number;
}

export const MG_ADMIN_EMAIL = 'spacedev@thegoat.com';

const DATA_DIR = path.resolve(process.cwd(), 'data');
const DB_FILE = path.join(DATA_DIR, 'multigames-db.json');

const DEFAULT_THEMES: GameTheme[] = [
  { id: 1, theme_key: 'red', name: 'Rouge et Noir', primary_color: '#dc2626', background_color: '#09090b', custom: 0 },
  { id: 2, theme_key: 'amber', name: 'Ambre / Or', primary_color: '#d97706', background_color: '#0c0e12', custom: 0 },
  { id: 3, theme_key: 'purple', name: 'Violet', primary_color: '#9333ea', background_color: '#090710', custom: 0 },
  { id: 4, theme_key: 'cyan', name: 'Cyan Électrique', primary_color: '#0891b2', background_color: '#060e14', custom: 0 },
  { id: 5, theme_key: 'emerald', name: 'Émeraude', primary_color: '#059669', background_color: '#05100b', custom: 0 },
];

const DEFAULT_SYMBOLS: GameSymbol[] = [
  { id: 1, symbol: '✕', label: 'Croix', custom: 0 },
  { id: 2, symbol: '○', label: 'Cercle', custom: 0 },
  { id: 3, symbol: '★', label: 'Étoile', custom: 0 },
  { id: 4, symbol: '♥', label: 'Cœur', custom: 0 },
  { id: 5, symbol: '◆', label: 'Losange', custom: 0 },
  { id: 6, symbol: '▲', label: 'Triangle', custom: 0 },
  { id: 7, symbol: '🐱', label: 'Chat', custom: 0 },
  { id: 8, symbol: '🐶', label: 'Chien', custom: 0 },
  { id: 9, symbol: '🔥', label: 'Feu', custom: 0 },
  { id: 10, symbol: '⚡', label: 'Éclair', custom: 0 },
  { id: 11, symbol: '🌙', label: 'Lune', custom: 0 },
  { id: 12, symbol: '🌟', label: 'Étoile brillante', custom: 0 },
  { id: 13, symbol: '🍀', label: 'Trèfle', custom: 0 },
  { id: 14, symbol: '👾', label: 'Alien', custom: 0 },
  { id: 15, symbol: '🎯', label: 'Cible', custom: 0 },
  { id: 16, symbol: '🛡️', label: 'Bouclier', custom: 0 },
];

class DataStore {
  public users: User[] = [];
  public gameSessions: GameSession[] = [];
  public botMemories: Map<number, BotMemory> = new Map();
  public themes: GameTheme[] = [...DEFAULT_THEMES];
  public symbols: GameSymbol[] = [...DEFAULT_SYMBOLS];
  public rooms: Map<string, Room> = new Map();
  private nextUserId = 1;
  private nextThemeId = 6;
  private nextSymbolId = 17;

  constructor() {
    this.init();
  }

  private init() {
    if (!fs.existsSync(DATA_DIR)) {
      try {
        fs.mkdirSync(DATA_DIR, { recursive: true });
      } catch (err) {
        console.warn('Could not create data dir:', err);
      }
    }

    if (fs.existsSync(DB_FILE)) {
      try {
        const raw = fs.readFileSync(DB_FILE, 'utf-8');
        const data = JSON.parse(raw);
        this.users = data.users || [];
        this.gameSessions = data.gameSessions || [];
        if (data.botMemories && Array.isArray(data.botMemories)) {
          this.botMemories = new Map(data.botMemories.map((bm: BotMemory) => [bm.user_id, bm]));
        }
        this.themes = data.themes && data.themes.length > 0 ? data.themes : [...DEFAULT_THEMES];
        this.symbols = data.symbols && data.symbols.length > 0 ? data.symbols : [...DEFAULT_SYMBOLS];
        this.nextUserId = this.users.reduce((max, u) => Math.max(max, u.id), 0) + 1;
        this.nextThemeId = this.themes.reduce((max, t) => Math.max(max, t.id), 0) + 1;
        this.nextSymbolId = this.symbols.reduce((max, s) => Math.max(max, s.id), 0) + 1;
      } catch (e) {
        console.warn('Failed to parse database file, starting clean:', e);
      }
    }

    // Seed default admin if not present
    const adminUser = this.users.find(u => u.email.toLowerCase() === MG_ADMIN_EMAIL.toLowerCase());
    const adminPassword = process.env.MG_ADMIN_PASSWORD || 'spacedev@thegoat.com123456';
    if (!adminUser) {
      this.users.push({
        id: this.nextUserId++,
        email: MG_ADMIN_EMAIL,
        display_name: 'Space Admin',
        password_hash: bcrypt.hashSync(adminPassword, 10),
        role: 'admin',
        created_at: Math.floor(Date.now() / 1000),
        last_seen_at: Math.floor(Date.now() / 1000),
        is_banned: 0,
      });
      this.persist();
    }
  }

  public persist() {
    try {
      const data = {
        users: this.users,
        gameSessions: this.gameSessions,
        botMemories: Array.from(this.botMemories.values()),
        themes: this.themes,
        symbols: this.symbols,
      };
      fs.writeFileSync(DB_FILE, JSON.stringify(data, null, 2), 'utf-8');
    } catch (e) {
      console.warn('Failed to persist database file:', e);
    }
  }

  public createUser(email: string, displayName: string, passwordHash: string): User {
    const user: User = {
      id: this.nextUserId++,
      email: email.toLowerCase(),
      display_name: displayName,
      password_hash: passwordHash,
      role: 'player',
      created_at: Math.floor(Date.now() / 1000),
      last_seen_at: Math.floor(Date.now() / 1000),
      is_banned: 0,
    };
    this.users.push(user);
    this.persist();
    return user;
  }

  public getUserById(id: number): User | null {
    return this.users.find(u => u.id === id) || null;
  }

  public getUserByEmail(email: string): User | null {
    return this.users.find(u => u.email.toLowerCase() === email.toLowerCase()) || null;
  }

  public getCustomThemeCss(): string {
    const customThemes = this.themes.filter(t => t.custom === 1);
    let css = '';
    for (const theme of customThemes) {
      const key = theme.theme_key.replace(/[^a-z0-9_-]/g, '');
      const primary = /^#[0-9a-fA-F]{6}$/.test(theme.primary_color) ? theme.primary_color : '#dc2626';
      const background = /^#[0-9a-fA-F]{6}$/.test(theme.background_color) ? theme.background_color : '#09090b';
      css += `[data-theme="${key}"]{--primary:${primary};--primary-hover:${primary};--bg-main:${background};--bg-gradient:radial-gradient(ellipse at 50% 0%,${primary}22 0%,${background} 75%);--color-x:${primary};--win-line:${primary};--theme-color-preview:${primary};}`;
    }
    return css;
  }

  public addTheme(key: string, name: string, primary: string, background: string): GameTheme {
    const theme: GameTheme = {
      id: this.nextThemeId++,
      theme_key: key.toLowerCase(),
      name,
      primary_color: primary,
      background_color: background,
      custom: 1,
    };
    this.themes.push(theme);
    this.persist();
    return theme;
  }

  public deleteTheme(id: number): boolean {
    const idx = this.themes.findIndex(t => t.id === id && t.custom === 1);
    if (idx !== -1) {
      this.themes.splice(idx, 1);
      this.persist();
      return true;
    }
    return false;
  }

  public addSymbol(symbol: string, label: string): GameSymbol {
    const item: GameSymbol = {
      id: this.nextSymbolId++,
      symbol,
      label,
      custom: 1,
    };
    this.symbols.push(item);
    this.persist();
    return item;
  }

  public deleteSymbol(id: number): boolean {
    const idx = this.symbols.findIndex(s => s.id === id && s.custom === 1);
    if (idx !== -1) {
      this.symbols.splice(idx, 1);
      this.persist();
      return true;
    }
    return false;
  }

  public cleanOldRooms() {
    const now = Math.floor(Date.now() / 1000);
    for (const [roomId, room] of this.rooms.entries()) {
      if (now - room.updatedAt > 3600) {
        this.rooms.delete(roomId);
      }
    }
  }
}

export const db = new DataStore();

export function checkBoardWinner(board: Array<string | null>, size: number = 3, winLen: number = 3): { winner: string; combo: number[] } | null {
  // Lignes
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
  // Colonnes
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
  // Diagonale \
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
  // Diagonale /
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

export function applyRoomAction(room: Room, action: string): void {
  room.moveHistory = room.moveHistory || [];
  const previousStatus = room.status;
  const previousWinner = room.winner;

  if (action === 'change_grid') {
    const size = room.pendingAction?.size || 3;
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
      if (previousStatus === 'finished' && (previousWinner === 'X' || previousWinner === 'O')) {
        room.scores[previousWinner] = Math.max(0, room.scores[previousWinner] - 1);
      } else if (previousStatus === 'finished' && previousWinner === 'TIE') {
        room.scores.ties = Math.max(0, room.scores.ties - 1);
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
