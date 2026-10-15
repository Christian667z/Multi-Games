import fs from 'fs';
import path from 'path';
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
  avatar?: string;
  theme_preference?: string;
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

export interface BotMistake {
  board: string;
  move: number;
  size: number;
  winLength: number;
  key: string;
  failures: number;
}

export interface BotMemory {
  user_id: number;
  learning_level: number;
  mistakes: BotMistake[];
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

const DATA_DIR = path.resolve('data');
const DB_FILE = path.join(DATA_DIR, 'multigames.json');

const DEFAULT_THEMES: GameTheme[] = [
  { id: 1, theme_key: 'red', name: 'Rouge et Noir', primary_color: '#dc2626', background_color: '#09090b', custom: 0 },
  { id: 2, theme_key: 'amber', name: 'Ambre / Or', primary_color: '#d97706', background_color: '#0c0e12', custom: 0 },
  { id: 3, theme_key: 'purple', name: 'Violet', primary_color: '#9333ea', background_color: '#090710', custom: 0 },
  { id: 4, theme_key: 'cyan', name: 'Cyan Électrique', primary_color: '#0891b2', background_color: '#060e14', custom: 0 },
  { id: 5, theme_key: 'emerald', name: 'Émeraude', primary_color: '#059669', background_color: '#05100b', custom: 0 },
];

const DEFAULT_SYMBOLS: GameSymbol[] = [
  { id: 1, symbol: '✕', label: 'Croix classique', custom: 0 },
  { id: 2, symbol: '○', label: 'Cercle classique', custom: 0 },
  { id: 3, symbol: '⚡', label: 'Éclair néon', custom: 0 },
  { id: 4, symbol: '✦', label: 'Étoile diamant', custom: 0 },
];

class Database {
  private users: User[] = [];
  private sessions: GameSession[] = [];
  private botMemory: Map<number, BotMemory> = new Map();
  private themes: GameTheme[] = [];
  private symbols: GameSymbol[] = [];
  private nextUserId = 1;
  private nextThemeId = 6;
  private nextSymbolId = 5;

  constructor() {
    this.init();
  }

  private init() {
    if (!fs.existsSync(DATA_DIR)) {
      try {
        fs.mkdirSync(DATA_DIR, { recursive: true });
      } catch {}
    }

    if (fs.existsSync(DB_FILE)) {
      try {
        const raw = fs.readFileSync(DB_FILE, 'utf-8');
        const data = JSON.parse(raw);
        this.users = data.users || [];
        for (const u of this.users) {
          if (!u.avatar) u.avatar = u.role === 'admin' ? '👑' : '🚀';
          if (!u.theme_preference) u.theme_preference = 'red';
        }
        this.sessions = data.sessions || [];
        this.themes = data.themes || [...DEFAULT_THEMES];
        this.symbols = data.symbols || [...DEFAULT_SYMBOLS];
        if (data.botMemory) {
          for (const item of data.botMemory) {
            this.botMemory.set(item.user_id, item);
          }
        }
        this.nextUserId = Math.max(1, ...this.users.map(u => u.id + 1));
        this.nextThemeId = Math.max(6, ...this.themes.map(t => t.id + 1));
        this.nextSymbolId = Math.max(5, ...this.symbols.map(s => s.id + 1));
      } catch (err) {
        console.warn('Could not read existing db file, initializing defaults');
        this.resetDefaults();
      }
    } else {
      this.resetDefaults();
    }

    // Always ensure default admin user exists
    this.ensureAdminUser();
    this.save();
  }

  private resetDefaults() {
    this.users = [];
    this.sessions = [];
    this.botMemory.clear();
    this.themes = [...DEFAULT_THEMES];
    this.symbols = [...DEFAULT_SYMBOLS];
  }

  public save() {
    try {
      if (!fs.existsSync(DATA_DIR)) {
        fs.mkdirSync(DATA_DIR, { recursive: true });
      }
      const data = {
        users: this.users,
        sessions: this.sessions,
        botMemory: Array.from(this.botMemory.values()),
        themes: this.themes,
        symbols: this.symbols,
      };
      fs.writeFileSync(DB_FILE, JSON.stringify(data, null, 2), 'utf-8');
    } catch (err) {
      console.warn('Failed to persist database to file:', err);
    }
  }

  private ensureAdminUser() {
    const adminEmail = 'spacedev@thegoat.com';
    const existing = this.users.find(u => u.email.toLowerCase() === adminEmail);
    if (!existing) {
      const adminPass = process.env.MG_ADMIN_PASSWORD || 'admin_password_123456';
      const hash = bcrypt.hashSync(adminPass, 10);
      this.users.push({
        id: this.nextUserId++,
        email: adminEmail,
        display_name: 'Space Admin',
        password_hash: hash,
        role: 'admin',
        created_at: Math.floor(Date.now() / 1000),
        last_seen_at: Math.floor(Date.now() / 1000),
        is_banned: 0,
        avatar: '👑',
        theme_preference: 'red',
      });
    } else {
      if (!existing.avatar) existing.avatar = '👑';
      if (!existing.theme_preference) existing.theme_preference = 'red';
    }
  }

  // Users
  public getUserById(id: number): User | null {
    return this.users.find(u => u.id === id) || null;
  }

  public getUserByEmail(email: string): User | null {
    const norm = email.trim().toLowerCase();
    return this.users.find(u => u.email.toLowerCase() === norm) || null;
  }

  public createUser(email: string, displayName: string, passwordHash: string, role: 'player' | 'admin' = 'player', avatar = '🚀', themePreference = 'red'): User {
    const user: User = {
      id: this.nextUserId++,
      email: email.trim().toLowerCase(),
      display_name: displayName.trim(),
      password_hash: passwordHash,
      role,
      created_at: Math.floor(Date.now() / 1000),
      last_seen_at: Math.floor(Date.now() / 1000),
      is_banned: 0,
      avatar: avatar || '🚀',
      theme_preference: themePreference || 'red',
    };
    this.users.push(user);
    this.save();
    return user;
  }

  public updateUser(id: number, updates: Partial<User>): boolean {
    const user = this.getUserById(id);
    if (!user) return false;
    Object.assign(user, updates);
    this.save();
    return true;
  }

  public deleteUser(id: number): boolean {
    const idx = this.users.findIndex(u => u.id === id && u.role === 'player');
    if (idx === -1) return false;
    this.users.splice(idx, 1);
    this.botMemory.delete(id);
    this.save();
    return true;
  }

  public getAllPlayers(): User[] {
    return this.users.filter(u => u.role === 'player');
  }

  // Sessions
  public getSessionById(id: string): GameSession | null {
    return this.sessions.find(s => s.id === id) || null;
  }

  public createSession(session: GameSession): void {
    this.sessions.push(session);
    this.save();
  }

  public updateSession(id: string, updates: Partial<GameSession>): boolean {
    const session = this.getSessionById(id);
    if (!session) return false;
    Object.assign(session, updates);
    this.save();
    return true;
  }

  public getUserGameStats(userId: number) {
    const userSessions = this.sessions.filter(s => s.user_id === userId);
    const total = userSessions.length;
    const completed = userSessions.filter(s => s.completed_at !== null).length;
    const botGames = userSessions.filter(s => s.mode === 'bot').length;
    return { total, completed, bot_games: botGames };
  }

  public getUserGameHistory(userId: number, limit = 50) {
    const userSessions = this.sessions
      .filter(s => s.user_id === userId)
      .sort((a, b) => b.started_at - a.started_at)
      .slice(0, limit);

    return userSessions.map(s => {
      let outcome: 'win' | 'loss' | 'tie' | 'in_progress' = 'in_progress';
      let outcomeLabel = 'En cours';
      let outcomeBadge = 'badge-in-progress';

      if (s.completed_at !== null) {
        if (s.mode === 'bot') {
          // Joueur est toujours X contre le bot
          if (s.result === 'X') {
            outcome = 'win';
            outcomeLabel = 'Victoire';
            outcomeBadge = 'badge-win';
          } else if (s.result === 'O') {
            outcome = 'loss';
            outcomeLabel = 'Défaite';
            outcomeBadge = 'badge-loss';
          } else if (s.result === 'TIE') {
            outcome = 'tie';
            outcomeLabel = 'Match nul';
            outcomeBadge = 'badge-tie';
          }
        } else if (s.mode === 'local') {
          if (s.result === 'TIE') {
            outcome = 'tie';
            outcomeLabel = 'Match nul';
            outcomeBadge = 'badge-tie';
          } else {
            outcome = 'win';
            outcomeLabel = `Gagnant : ${s.result}`;
            outcomeBadge = 'badge-win';
          }
        } else if (s.mode === 'wifi') {
          if (s.result === 'TIE') {
            outcome = 'tie';
            outcomeLabel = 'Match nul';
            outcomeBadge = 'badge-tie';
          } else if (s.result === 'X' || s.result === 'O') {
            outcome = 'win';
            outcomeLabel = `Gagnant : ${s.result}`;
            outcomeBadge = 'badge-win';
          }
        }
      }

      const botNames: Record<string, string> = {
        novice: 'Novice 🐣',
        medium: 'Intermédiaire 🤖',
        advanced: 'Avancé 🧠',
        legend: 'Légende 👑',
        adaptive: 'Évolutif 🧬',
      };

      const modeNames: Record<string, string> = {
        bot: `Contre Bot (${botNames[s.bot_level || ''] || s.bot_level || 'Standard'})`,
        local: '1v1 Local (Même écran)',
        wifi: 'Duel Wi-Fi LAN',
      };

      return {
        id: s.id,
        mode: s.mode,
        modeName: modeNames[s.mode] || s.mode,
        bot_level: s.bot_level,
        board_size: s.board_size,
        grid_desc: s.board_size === 3 ? '3×3 Classique' : `${s.board_size}×${s.board_size} Gomoku`,
        result: s.result,
        outcome,
        outcomeLabel,
        outcomeBadge,
        started_at: s.started_at,
        completed_at: s.completed_at,
        formatted_date: new Date(s.started_at * 1000).toLocaleString('fr-FR', {
          day: '2-digit',
          month: '2-digit',
          year: 'numeric',
          hour: '2-digit',
          minute: '2-digit',
        }),
        duration: s.completed_at ? Math.max(1, s.completed_at - s.started_at) : null,
      };
    });
  }

  public getUserDetailedStats(userId: number) {
    const userSessions = this.sessions.filter(s => s.user_id === userId);
    const total = userSessions.length;
    const completed = userSessions.filter(s => s.completed_at !== null).length;

    let wins = 0;
    let losses = 0;
    let ties = 0;
    let botGames = 0;
    let wifiGames = 0;
    let localGames = 0;

    const botLevelBreakdown: Record<string, { total: number; wins: number; losses: number; ties: number }> = {
      novice: { total: 0, wins: 0, losses: 0, ties: 0 },
      medium: { total: 0, wins: 0, losses: 0, ties: 0 },
      advanced: { total: 0, wins: 0, losses: 0, ties: 0 },
      legend: { total: 0, wins: 0, losses: 0, ties: 0 },
      adaptive: { total: 0, wins: 0, losses: 0, ties: 0 },
    };

    for (const s of userSessions) {
      if (s.mode === 'bot') {
        botGames++;
        const lvl = s.bot_level || 'medium';
        if (!botLevelBreakdown[lvl]) {
          botLevelBreakdown[lvl] = { total: 0, wins: 0, losses: 0, ties: 0 };
        }
        botLevelBreakdown[lvl].total++;
        if (s.result === 'X') {
          wins++;
          botLevelBreakdown[lvl].wins++;
        } else if (s.result === 'O') {
          losses++;
          botLevelBreakdown[lvl].losses++;
        } else if (s.result === 'TIE') {
          ties++;
          botLevelBreakdown[lvl].ties++;
        }
      } else if (s.mode === 'wifi') {
        wifiGames++;
        if (s.result === 'X' || s.result === 'O') wins++;
        else if (s.result === 'TIE') ties++;
      } else if (s.mode === 'local') {
        localGames++;
        if (s.result === 'X' || s.result === 'O') wins++;
        else if (s.result === 'TIE') ties++;
      }
    }

    const decisive = wins + losses;
    const winRate = decisive > 0 ? Math.round((wins / decisive) * 100) : 0;
    const points = wins * 3 + ties * 1;

    return {
      total,
      completed,
      wins,
      losses,
      ties,
      win_rate: winRate,
      points,
      bot_games: botGames,
      wifi_games: wifiGames,
      local_games: localGames,
      bot_level_breakdown: botLevelBreakdown,
    };
  }

  public getLeaderboard(limit = 50) {
    const allUsers = this.users.filter(u => !u.is_banned);
    const entries = allUsers.map(user => {
      const stats = this.getUserDetailedStats(user.id);
      return {
        id: user.id,
        display_name: user.display_name,
        avatar: user.avatar || '🚀',
        role: user.role,
        total_games: stats.total,
        completed_games: stats.completed,
        wins: stats.wins,
        losses: stats.losses,
        ties: stats.ties,
        win_rate: stats.win_rate,
        points: stats.points,
        last_seen_at: user.last_seen_at,
      };
    });

    entries.sort((a, b) => {
      if (b.points !== a.points) return b.points - a.points;
      if (b.wins !== a.wins) return b.wins - a.wins;
      if (b.win_rate !== a.win_rate) return b.win_rate - a.win_rate;
      return b.completed_games - a.completed_games;
    });

    return entries.slice(0, limit).map((entry, index) => ({
      rank: index + 1,
      ...entry,
    }));
  }

  public getUserRank(userId: number): number {
    const leaderboard = this.getLeaderboard(1000);
    const found = leaderboard.find(e => e.id === userId);
    return found ? found.rank : leaderboard.length + 1;
  }

  // Bot Memory
  public getBotMemory(userId: number): BotMemory {
    if (!this.botMemory.has(userId)) {
      const memory: BotMemory = {
        user_id: userId,
        learning_level: 0,
        mistakes: [],
        games_learned: 0,
        updated_at: Math.floor(Date.now() / 1000),
      };
      this.botMemory.set(userId, memory);
      return memory;
    }
    return this.botMemory.get(userId)!;
  }

  public updateBotMemory(userId: number, memory: Partial<BotMemory>): void {
    const current = this.getBotMemory(userId);
    Object.assign(current, memory, { updated_at: Math.floor(Date.now() / 1000) });
    this.botMemory.set(userId, current);
    this.save();
  }

  // Themes
  public getThemes(): GameTheme[] {
    return this.themes;
  }

  public addTheme(themeKey: string, name: string, primaryColor: string, backgroundColor: string): GameTheme {
    const theme: GameTheme = {
      id: this.nextThemeId++,
      theme_key: themeKey,
      name,
      primary_color: primaryColor,
      background_color: backgroundColor,
      custom: 1,
    };
    this.themes.push(theme);
    this.save();
    return theme;
  }

  public deleteTheme(id: number): boolean {
    const idx = this.themes.findIndex(t => t.id === id && t.custom === 1);
    if (idx === -1) return false;
    this.themes.splice(idx, 1);
    this.save();
    return true;
  }

  // Symbols
  public getSymbols(): GameSymbol[] {
    return this.symbols;
  }

  public addSymbol(symbol: string, label: string): GameSymbol {
    const sym: GameSymbol = {
      id: this.nextSymbolId++,
      symbol,
      label,
      custom: 1,
    };
    this.symbols.push(sym);
    this.save();
    return sym;
  }

  public deleteSymbol(id: number): boolean {
    const idx = this.symbols.findIndex(s => s.id === id && s.custom === 1);
    if (idx === -1) return false;
    this.symbols.splice(idx, 1);
    this.save();
    return true;
  }

  public getCustomThemeCss(): string {
    let css = '';
    for (const theme of this.themes.filter(t => t.custom)) {
      const key = theme.theme_key.replace(/[^a-z0-9_-]/g, '');
      const primary = /^#[0-9a-fA-F]{6}$/.test(theme.primary_color) ? theme.primary_color : '#dc2626';
      const background = /^#[0-9a-fA-F]{6}$/.test(theme.background_color) ? theme.background_color : '#09090b';
      css += `[data-theme="${key}"]{--primary:${primary};--primary-hover:${primary};--bg-main:${background};--bg-gradient:radial-gradient(ellipse at 50% 0%,${primary}22 0%,${background} 75%);--color-x:${primary};--win-line:${primary};--theme-color-preview:${primary};}`;
    }
    return css;
  }

  // Admin stats
  public getSummaryStats() {
    const now = Math.floor(Date.now() / 1000);
    const players = this.getAllPlayers();
    const online = players.filter(p => p.last_seen_at >= now - 90).length;
    const games = this.sessions.length;
    const completed = this.sessions.filter(s => s.completed_at !== null).length;
    const active_games = this.sessions.filter(s => s.completed_at === null && s.started_at >= now - 3600).length;
    const bot_games = this.sessions.filter(s => s.mode === 'bot').length;
    const trained_accounts = this.botMemory.size;

    return {
      players: players.length,
      online,
      games,
      completed,
      active_games,
      bot_games,
      trained_accounts,
    };
  }

  public getBotLevelsStats() {
    const botSessions = this.sessions.filter(s => s.mode === 'bot');
    const map = new Map<string, { games: number; bot_wins: number; player_wins: number; ties: number }>();

    for (const s of botSessions) {
      const lvl = s.bot_level || 'non précisé';
      if (!map.has(lvl)) {
        map.set(lvl, { games: 0, bot_wins: 0, player_wins: 0, ties: 0 });
      }
      const st = map.get(lvl)!;
      st.games++;
      if (s.result === 'O') st.bot_wins++;
      else if (s.result === 'X') st.player_wins++;
      else if (s.result === 'TIE') st.ties++;
    }

    return Array.from(map.entries()).map(([bot_level, stats]) => ({
      bot_level,
      ...stats,
    })).sort((a, b) => b.games - a.games);
  }

  public getPopularModes() {
    const counts: Record<string, number> = {};
    for (const s of this.sessions) {
      counts[s.mode] = (counts[s.mode] || 0) + 1;
    }
    return Object.entries(counts).map(([mode, games]) => ({ mode, games })).sort((a, b) => b.games - a.games);
  }

  public getPlayersAdminList() {
    return this.getAllPlayers().map(p => {
      const mem = this.botMemory.get(p.id);
      const games = this.sessions.filter(s => s.user_id === p.id).length;
      return {
        id: p.id,
        display_name: p.display_name,
        email: p.email,
        created_at: p.created_at,
        last_seen_at: p.last_seen_at,
        is_banned: p.is_banned,
        games,
        learning_level: mem?.learning_level || 0,
        games_learned: mem?.games_learned || 0,
      };
    });
  }
}

export const db = new Database();
