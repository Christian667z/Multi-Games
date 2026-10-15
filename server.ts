import express, { Request, Response, NextFunction } from 'express';
import session from 'express-session';
import cookieParser from 'cookie-parser';
import path from 'path';
import crypto from 'crypto';
import bcrypt from 'bcryptjs';
import fs from 'fs';
import { db, User } from './src/db.js';
import { askSpace, SpaceUserContext } from './src/space.js';
import {
  cleanOldRooms,
  getRoom,
  saveRoom,
  deleteRoom,
  listAllRooms,
  checkBoardWinner,
  applyRoomAction,
  RoomData,
} from './src/rooms.js';

declare module 'express-session' {
  interface SessionData {
    userId?: number;
    username?: string;
    csrfToken?: string;
    spaceHistory?: Array<{ role: string; content: string }>;
    spaceScope?: string;
    loginAttempts?: { count: number; startedAt: number };
  }
}

declare global {
  namespace Express {
    interface Request {
      user?: User | null;
    }
  }
}

const app = express();
const PORT = 3000;

app.set('view engine', 'ejs');
app.set('views', path.resolve('views'));

app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true, limit: '10mb' }));
app.use(cookieParser());

app.use(
  session({
    secret: 'multi-games-pvp-secret-key-4k',
    resave: false,
    saveUninitialized: true,
    cookie: {
      httpOnly: true,
      secure: false,
      sameSite: 'lax',
      maxAge: 7 * 24 * 3600 * 1000,
    },
  })
);

// Session CSRF & Current User Middleware
app.use((req: Request, res: Response, next: NextFunction) => {
  if (!req.session.csrfToken) {
    req.session.csrfToken = crypto.randomBytes(32).toString('hex');
  }
  if (req.session.userId) {
    req.user = db.getUserById(req.session.userId);
    if (!req.user || req.user.is_banned) {
      req.user = null;
      delete req.session.userId;
      delete req.session.username;
    }
  } else {
    req.user = null;
  }
  next();
});

// Static assets
app.use('/assets', express.static(path.resolve('assets')));
app.use('/space-core/assets', express.static(path.resolve('space-core/assets')));
app.use('/games/morpion', (req: Request, res: Response, next: NextFunction) => {
  if (req.path.endsWith('.php') || req.path === '/' || req.path === '') {
    return next();
  }
  express.static(path.resolve('games/morpion'), { index: false })(req, res, next);
});
app.use('/dev', (req: Request, res: Response, next: NextFunction) => {
  if (req.path.endsWith('.php')) return next();
  express.static(path.resolve('dev'))(req, res, next);
});

// Live reload endpoint for dev/livereload.js
const serverStartTime = Math.floor(Date.now() / 1000);
app.get('/dev/livereload.php', (req: Request, res: Response) => {
  res.setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
  res.json({
    timestamp: serverStartTime,
    latestFile: 'server.ts',
    time: new Date().toLocaleTimeString('fr-FR'),
  });
});

// Helper for CSRF verification
function verifyCsrf(req: Request, token?: string): boolean {
  const sessionToken = req.session.csrfToken;
  const headerToken = req.headers['x-csrf-token'] as string | undefined;
  const candidate = token || headerToken || req.body?.csrf_token;
  if (!sessionToken || !candidate) return false;
  try {
    return crypto.timingSafeEqual(Buffer.from(sessionToken), Buffer.from(candidate));
  } catch {
    return false;
  }
}

// ----------------------------------------------------
// 1. HUB / ACCUEIL
// ----------------------------------------------------
const GAMES = [
  {
    id: 'morpion',
    name: 'Morpion by Space',
    desc: "3x3 Classique ou 5 à aligner Gomoku. 1v1 Local, 5 niveaux d'IA ou Défi en Wi-Fi LAN.",
    url: 'games/morpion/index.php',
    icon: '✕ ○',
    badge: 'Disponible ⚡',
    players: '1 - 2 Joueurs',
    active: true,
  },
  {
    id: 'connect4',
    name: 'Puissance 4',
    desc: 'Faites glisser vos pions et alignez-en 4 pour remporter la manche PvP.',
    url: '#',
    icon: '🔴 🟡',
    badge: 'En développement',
    players: '2 Joueurs',
    active: false,
  },
  {
    id: 'pong',
    name: 'Cyber Pong Duel',
    desc: "L'arcade légendaire revisitée avec effets lumineux et physique ultra rapide.",
    url: '#',
    icon: '🏓 ⚡',
    badge: 'Bientôt',
    players: '2 Joueurs',
    active: false,
  },
  {
    id: 'naval',
    name: 'Bataille Navale LAN',
    desc: 'Placez votre flotte secrète et coulez les navires adverses sur le réseau Wi-Fi.',
    url: '#',
    icon: '🚢 💥',
    badge: 'Bientôt',
    players: '2 Joueurs',
    active: false,
  },
];

app.get(['/', '/index.php'], (req: Request, res: Response) => {
  res.render('index', {
    user: req.user,
    gameThemes: db.getThemes(),
    customThemeCss: db.getCustomThemeCss(),
    csrfToken: req.session.csrfToken,
    games: GAMES,
  });
});

// ----------------------------------------------------
// 2. MORPION GAME VIEW
// ----------------------------------------------------
app.get(['/games/morpion', '/games/morpion/', '/games/morpion/index.php'], (req: Request, res: Response) => {
  if (req.path === '/games/morpion') {
    return res.redirect(301, '/games/morpion/');
  }

  const user = req.user;
  const playerDisplayName = user ? user.display_name : 'Joueur 1';
  let requestedGridSize: number | null = null;
  const gq = parseInt(req.query.grid_size as string, 10);
  if ([3, 5, 10, 12, 15, 19].includes(gq)) {
    requestedGridSize = gq;
  }

  const hostIp = (req.headers.host || '127.0.0.1').split(':')[0];
  const lanBaseUrl = `/games/morpion/`;
  const csrfToken = req.session.csrfToken || '';
  const customThemeCss = db.getCustomThemeCss();

  const gameThemes = db.getThemes();
  const gameSymbols = db.getSymbols();

  res.render('morpion', {
    user,
    gameThemes,
    gameSymbols,
    customThemeCss,
    csrfToken,
    requestedGridSize,
    playerDisplayName,
    lanBaseUrl,
    serverIp: hostIp,
  });
});

// ----------------------------------------------------
// 3. ACCOUNT PAGE (LOGIN, REGISTER, PROFILE)
// ----------------------------------------------------
function renderAccountPage(req: Request, res: Response, error = '') {
  const user = req.user;
  const csrfToken = req.session.csrfToken || '';
  const customThemeCss = db.getCustomThemeCss();

  let playerStats = { total: 0, completed: 0, bot_games: 0 };
  let botStats = { learning_level: 0, games_learned: 0 };
  if (user) {
    playerStats = db.getUserGameStats(user.id);
    const mem = db.getBotMemory(user.id);
    botStats = { learning_level: mem.learning_level, games_learned: mem.games_learned };
  }

  res.render('account', {
    user,
    csrfToken,
    customThemeCss,
    error,
    playerStats,
    botStats,
  });
}

app.get(['/account', '/account.php'], (req: Request, res: Response) => {
  renderAccountPage(req, res);
});

app.post('/account.php', (req: Request, res: Response) => {
  const action = req.body.action || '';
  if (!verifyCsrf(req)) {
    return renderAccountPage(req, res, 'La session du formulaire a expiré. Rechargez la page et réessayez.');
  }

  if (action === 'logout') {
    delete req.session.userId;
    delete req.session.username;
    return res.redirect('account.php');
  }

  if (action === 'login') {
    const email = (req.body.email || '').trim().toLowerCase();
    const password = req.body.password || '';
    const now = Math.floor(Date.now() / 1000);

    const attempts = req.session.loginAttempts || { count: 0, startedAt: now };
    if (now - attempts.startedAt > 900) {
      attempts.count = 0;
      attempts.startedAt = now;
    }

    if (attempts.count >= 8) {
      return renderAccountPage(req, res, 'Trop de tentatives. Attendez 15 minutes avant de réessayer.');
    }

    const user = db.getUserByEmail(email);
    if (user && !user.is_banned && bcrypt.compareSync(password, user.password_hash)) {
      req.session.userId = user.id;
      req.session.username = user.display_name;
      delete req.session.loginAttempts;
      return res.redirect(user.role === 'admin' ? 'admin.php' : 'account.php');
    }

    attempts.count++;
    req.session.loginAttempts = attempts;
    return renderAccountPage(req, res, 'Adresse e-mail ou mot de passe incorrect.');
  }

  if (action === 'register') {
    const name = (req.body.display_name || '').trim();
    const email = (req.body.email || '').trim().toLowerCase();
    const password = req.body.password || '';

    if (name.length < 2 || name.length > 30) {
      return renderAccountPage(req, res, 'Le pseudo doit contenir entre 2 et 30 caractères.');
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email.length > 190) {
      return renderAccountPage(req, res, 'Entrez une adresse e-mail valide.');
    }
    if (password.length < 10) {
      return renderAccountPage(req, res, 'Choisissez un mot de passe d’au moins 10 caractères.');
    }
    if (email === 'spacedev@thegoat.com') {
      return renderAccountPage(req, res, 'Cette adresse e-mail est réservée.');
    }

    if (db.getUserByEmail(email)) {
      return renderAccountPage(req, res, 'Un compte existe déjà avec cette adresse e-mail.');
    }

    const hash = bcrypt.hashSync(password, 10);
    const user = db.createUser(email, name, hash, 'player');
    req.session.userId = user.id;
    req.session.username = user.display_name;
    return res.redirect('account.php');
  }

  return renderAccountPage(req, res, 'Action inconnue.');
});

// ----------------------------------------------------
// 4. ADMIN DASHBOARD
// ----------------------------------------------------
app.get(['/admin', '/admin.php'], (req: Request, res: Response) => {
  const user = req.user;
  if (!user || user.role !== 'admin') {
    return res.redirect('account.php');
  }

  const csrfToken = req.session.csrfToken || '';
  const customThemeCss = db.getCustomThemeCss();
  const summary = db.getSummaryStats();
  const botLevels = db.getBotLevelsStats();
  const players = db.getPlayersAdminList();
  const now = Math.floor(Date.now() / 1000);

  res.render('admin', {
    user,
    csrfToken,
    customThemeCss,
    summary,
    botLevels,
    players,
    now,
  });
});

// ----------------------------------------------------
// 5. SPACE CORE VIEW & API
// ----------------------------------------------------
app.get(['/space-core', '/space-core/', '/space-core/index.php'], (req: Request, res: Response) => {
  if (req.path === '/space-core') {
    return res.redirect(301, '/space-core/');
  }
  res.render('space', {
    csrfToken: req.session.csrfToken || '',
  });
});

app.post(['/space-core/api_space.php', '/api_space.php', '/api/space'], async (req: Request, res: Response) => {
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store');

  const clientCsrf = (req.headers['x-csrf-token'] as string) || req.body?.csrf_token;
  if (!verifyCsrf(req, clientCsrf)) {
    return res.status(403).json({ error: 'Session expirée. Recharge la page.' });
  }

  const user = req.user;
  const userStats = user ? db.getUserGameStats(user.id) : null;
  const userContext: SpaceUserContext = {
    user_id: user ? user.id : null,
    username: user ? user.display_name : 'Invité',
    role: user ? user.role : 'guest',
    user_stats: userStats,
  };

  const scope = String(user?.id ?? 'guest');
  if (req.session.spaceScope !== scope) {
    req.session.spaceHistory = [];
    req.session.spaceScope = scope;
  }

  const operation = req.body?.operation;
  if (operation === 'clear') {
    req.session.spaceHistory = [];
    return res.json({ text: 'Nouvelle discussion ouverte.', action: null });
  }

  if (operation === 'hello') {
    return res.json({
      text: `Bienvenue ${userContext.username} ! Je suis Space. On joue ou on discute ?`,
      action: null,
    });
  }

  const message = req.body?.message;
  if (!message || typeof message !== 'string' || message.trim() === '') {
    return res.status(400).json({ error: 'Écris un message de 4 000 caractères maximum.' });
  }

  const history = req.session.spaceHistory || [];
  const result = await askSpace(message, userContext, history);

  history.push({ role: 'user', content: message });
  history.push({ role: 'assistant', content: result.text });
  req.session.spaceHistory = history.slice(-16);

  return res.json(result);
});

// ----------------------------------------------------
// 6. MORPION MULTIPLAYER API (API.PHP & /API/MORPION)
// ----------------------------------------------------
app.all(['/games/morpion/api.php', '/api/morpion', '/api.php'], async (req: Request, res: Response) => {
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');

  const input = { ...req.query, ...req.body };
  const action = input.action || '';

  const user = req.user;

  switch (action) {
    case 'game_options': {
      return res.json({
        success: true,
        themes: db.getThemes().map(t => ({
          key: t.theme_key,
          name: t.name,
          primaryColor: t.primary_color,
          backgroundColor: t.background_color,
        })),
        symbols: db.getSymbols().map(s => ({
          symbol: s.symbol,
          label: s.label,
        })),
      });
    }

    case 'admin_stats': {
      if (!user || user.role !== 'admin') {
        return res.status(403).json({ success: false, error: 'Accès administrateur requis.' });
      }
      return res.json({
        success: true,
        summary: db.getSummaryStats(),
        bot_levels: db.getBotLevelsStats(),
        popular_modes: db.getPopularModes(),
        players: db.getPlayersAdminList(),
        themes: db.getThemes().map(t => ({
          id: t.id,
          key: t.theme_key,
          name: t.name,
          primary_color: t.primary_color,
          background_color: t.background_color,
          custom: t.custom,
        })),
        symbols: db.getSymbols(),
      });
    }

    case 'admin_rooms': {
      if (!user || user.role !== 'admin') {
        return res.status(403).json({ success: false, error: 'Accès administrateur requis.' });
      }
      return res.json({
        success: true,
        rooms: listAllRooms(),
      });
    }

    case 'admin_delete_room': {
      if (!user || user.role !== 'admin') {
        return res.status(403).json({ success: false, error: 'Accès administrateur requis.' });
      }
      if (!verifyCsrf(req)) {
        return res.status(403).json({ success: false, error: 'Jeton de sécurité invalide.' });
      }
      const roomId = String(input.room_id || '').toUpperCase();
      if (!/^[A-Z0-9]{4}$/.test(roomId)) {
        return res.status(400).json({ success: false, error: 'Code de salon invalide.' });
      }
      const ok = deleteRoom(roomId);
      if (!ok) {
        return res.status(404).json({ success: false, error: 'Impossible de supprimer ce salon.' });
      }
      return res.json({ success: true });
    }

    case 'admin_manage_user': {
      if (!user || user.role !== 'admin') {
        return res.status(403).json({ success: false, error: 'Accès administrateur requis.' });
      }
      if (!verifyCsrf(req)) {
        return res.status(403).json({ success: false, error: 'Jeton de sécurité invalide.' });
      }
      const userId = parseInt(input.user_id, 10);
      const operation = input.operation;
      if (!userId || userId === user.id) {
        return res.status(400).json({ success: false, error: 'Compte cible invalide.' });
      }
      const target = db.getUserById(userId);
      if (!target || target.role === 'admin') {
        return res.status(403).json({ success: false, error: 'Ce compte ne peut pas être modifié ici.' });
      }

      if (operation === 'delete') {
        db.deleteUser(userId);
      } else if (operation === 'ban' || operation === 'unban') {
        db.updateUser(userId, { is_banned: operation === 'ban' ? 1 : 0 });
      } else if (operation === 'edit') {
        const name = String(input.display_name || '').trim();
        const email = String(input.email || '').trim().toLowerCase();
        if (name.length < 2 || name.length > 30 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          return res.status(400).json({ success: false, error: 'Pseudo ou e-mail invalide.' });
        }
        const existing = db.getUserByEmail(email);
        if (existing && existing.id !== userId) {
          return res.status(409).json({ success: false, error: 'Cet e-mail est déjà utilisé.' });
        }
        db.updateUser(userId, { display_name: name, email });
      } else {
        return res.status(400).json({ success: false, error: 'Opération invalide.' });
      }

      return res.json({ success: true });
    }

    case 'admin_manage_theme': {
      if (!user || user.role !== 'admin') {
        return res.status(403).json({ success: false, error: 'Accès administrateur requis.' });
      }
      if (!verifyCsrf(req)) {
        return res.status(403).json({ success: false, error: 'Jeton de sécurité invalide.' });
      }
      const operation = input.operation;
      if (operation === 'add') {
        const name = String(input.name || '').trim();
        const primary = String(input.primary_color || '').trim();
        const background = String(input.background_color || '').trim();
        const key = String(input.key || '').trim().toLowerCase();

        if (
          name.length < 2 ||
          name.length > 30 ||
          !/^[a-z][a-z0-9-]{1,24}$/.test(key) ||
          !/^#[0-9a-fA-F]{6}$/.test(primary) ||
          !/^#[0-9a-fA-F]{6}$/.test(background)
        ) {
          return res.status(400).json({ success: false, error: 'Nom, identifiant ou couleur de thème invalide.' });
        }
        if (db.getThemes().some(t => t.theme_key === key)) {
          return res.status(409).json({ success: false, error: 'Cet identifiant de thème existe déjà.' });
        }
        db.addTheme(key, name, primary, background);
      } else if (operation === 'delete') {
        const id = parseInt(input.id, 10);
        const ok = db.deleteTheme(id);
        if (!ok) {
          return res.status(400).json({ success: false, error: 'Seuls les thèmes personnalisés peuvent être supprimés.' });
        }
      } else {
        return res.status(400).json({ success: false, error: 'Opération de thème invalide.' });
      }
      return res.json({ success: true });
    }

    case 'admin_manage_symbol': {
      if (!user || user.role !== 'admin') {
        return res.status(403).json({ success: false, error: 'Accès administrateur requis.' });
      }
      if (!verifyCsrf(req)) {
        return res.status(403).json({ success: false, error: 'Jeton de sécurité invalide.' });
      }
      const operation = input.operation;
      if (operation === 'add') {
        const symbol = String(input.symbol || '').trim();
        const label = String(input.label || '').trim();
        if (!symbol || symbol.length > 8 || !label || label.length > 30) {
          return res.status(400).json({ success: false, error: 'Symbole ou nom invalide.' });
        }
        if (db.getSymbols().some(s => s.symbol === symbol)) {
          return res.status(409).json({ success: false, error: 'Ce symbole existe déjà.' });
        }
        db.addSymbol(symbol, label);
      } else if (operation === 'delete') {
        const id = parseInt(input.id, 10);
        const ok = db.deleteSymbol(id);
        if (!ok) {
          return res.status(400).json({ success: false, error: 'Seuls les symboles personnalisés peuvent être supprimés.' });
        }
      } else {
        return res.status(400).json({ success: false, error: 'Opération de symbole invalide.' });
      }
      return res.json({ success: true });
    }

    case 'presence': {
      if (!user) {
        return res.json({ success: true, authenticated: false });
      }
      if (!verifyCsrf(req)) {
        return res.status(403).json({ success: false, error: 'Jeton de sécurité invalide.' });
      }
      db.updateUser(user.id, { last_seen_at: Math.floor(Date.now() / 1000) });
      return res.json({ success: true, authenticated: true });
    }

    case 'bot_profile': {
      if (!user) {
        return res.json({ success: true, profile: null });
      }
      const mem = db.getBotMemory(user.id);
      return res.json({
        success: true,
        profile: {
          learningLevel: mem.learning_level,
          mistakes: mem.mistakes,
          gamesLearned: mem.games_learned,
        },
      });
    }

    case 'start_game': {
      const mode = input.mode;
      const bot_level = input.bot_level || null;
      const size = parseInt(input.board_size, 10) || 3;

      if (!['local', 'bot', 'wifi'].includes(mode) || ![3, 5, 10, 12, 15, 19].includes(size)) {
        return res.status(400).json({ success: false, error: 'Paramètres de partie invalides.' });
      }

      const gameId = crypto.randomBytes(16).toString('hex');
      db.createSession({
        id: gameId,
        session_key: req.sessionID || crypto.randomBytes(8).toString('hex'),
        user_id: user ? user.id : null,
        mode,
        bot_level: mode === 'bot' ? bot_level : null,
        board_size: size,
        result: null,
        started_at: Math.floor(Date.now() / 1000),
        completed_at: null,
      });

      return res.json({ success: true, game_id: gameId });
    }

    case 'finish_game': {
      const gameId = String(input.game_id || '');
      const winner = input.winner;
      if (!/^[a-f0-9]{32}$/.test(gameId) || !['X', 'O', 'TIE'].includes(winner)) {
        return res.status(400).json({ success: false, error: 'Résultat de partie invalide.' });
      }

      const session = db.getSessionById(gameId);
      if (!session) {
        return res.status(404).json({ success: false, error: 'Partie introuvable pour cette session.' });
      }

      if (session.completed_at !== null) {
        return res.json({ success: true, already_recorded: true });
      }

      db.updateSession(gameId, {
        result: winner as 'X' | 'O' | 'TIE',
        completed_at: Math.floor(Date.now() / 1000),
      });

      // Adaptive bot learning
      if (session.mode === 'bot' && session.bot_level === 'adaptive' && session.user_id !== null) {
        const mem = db.getBotMemory(session.user_id);
        let learningLevel = mem.learning_level;
        if (winner === 'X') learningLevel = Math.min(3, learningLevel + 1);
        else if (winner === 'O') learningLevel = Math.max(-2, learningLevel - 1);

        const decisions = Array.isArray(input.decisions) ? input.decisions : [];
        const mistakes = [...mem.mistakes];

        if (winner === 'X' && decisions.length > 0) {
          const last = decisions[decisions.length - 1];
          if (last && last.board && typeof last.move === 'number') {
            const key = `${last.board}:${last.size}:${last.winLength}`;
            const existingMistake = mistakes.find(m => m.key === key && m.move === last.move);
            if (existingMistake) {
              existingMistake.failures = Math.min(20, (existingMistake.failures || 0) + 1);
            } else {
              mistakes.push({
                board: last.board,
                move: last.move,
                size: last.size,
                winLength: last.winLength,
                key,
                failures: 1,
              });
            }
          }
        }

        db.updateBotMemory(session.user_id, {
          learning_level: learningLevel,
          mistakes: mistakes.slice(-100),
          games_learned: mem.games_learned + 1,
        });
      }

      return res.json({ success: true });
    }

    // ----------------- Wi-Fi ROOMS -----------------
    case 'create_room': {
      let size = parseInt(input.size, 10) || 3;
      if (![3, 5, 10, 12, 15, 19].includes(size)) size = 3;

      const winLen = size === 3 ? 3 : size === 5 ? 4 : 5;
      let targetWins = parseInt(input.target_wins, 10) || 1;
      if (![1, 2, 3, 5].includes(targetWins)) targetWins = 1;

      let hostName = String(input.player_name || 'Joueur 1').trim().slice(0, 20);
      if (!hostName) hostName = 'Joueur 1';

      const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
      let roomId = '';
      for (let i = 0; i < 4; i++) {
        roomId += chars[Math.floor(Math.random() * chars.length)];
      }

      const playerId = crypto.randomBytes(16).toString('hex');
      const now = Math.floor(Date.now() / 1000);

      const roomData: RoomData = {
        roomId,
        size,
        winLen,
        targetWins,
        status: 'waiting',
        playerX: { id: playerId, name: hostName, lastSeen: now },
        playerO: null,
        currentTurn: 'X',
        startingPlayer: 'X',
        board: Array(size * size).fill(null),
        winner: null,
        winningCombo: [],
        scores: { X: 0, O: 0, ties: 0 },
        rematchVotes: [],
        moveHistory: [],
        pendingAction: null,
        lastActionResult: null,
        tournamentWinner: null,
        updatedAt: now,
      };

      saveRoom(roomData);

      return res.json({
        success: true,
        roomId,
        playerId,
        symbol: 'X',
        room: roomData,
      });
    }

    case 'join_room': {
      const roomId = String(input.room_id || '').toUpperCase().trim();
      let guestName = String(input.player_name || 'Joueur 2').trim().slice(0, 20);
      if (!guestName) guestName = 'Joueur 2';
      const existingPlayerId = String(input.player_id || '').trim();

      const room = getRoom(roomId);
      if (!room) {
        return res.json({ success: false, error: 'Salon introuvable' });
      }

      const now = Math.floor(Date.now() / 1000);

      // Reconnect
      if (existingPlayerId) {
        if (room.playerX && room.playerX.id === existingPlayerId) {
          room.playerX.lastSeen = now;
          saveRoom(room);
          return res.json({ success: true, roomId, playerId: existingPlayerId, symbol: 'X', room });
        }
        if (room.playerO && room.playerO.id === existingPlayerId) {
          room.playerO.lastSeen = now;
          saveRoom(room);
          return res.json({ success: true, roomId, playerId: existingPlayerId, symbol: 'O', room });
        }
      }

      // New Player O
      if (room.playerO === null) {
        const playerId = crypto.randomBytes(16).toString('hex');
        room.playerO = {
          id: playerId,
          name: guestName,
          lastSeen: now,
        };
        room.status = 'playing';
        saveRoom(room);

        return res.json({ success: true, roomId, playerId, symbol: 'O', room });
      }

      return res.json({ success: false, error: 'Ce salon est déjà complet (2/2 joueurs)' });
    }

    case 'get_state': {
      const roomId = String(input.room_id || '').toUpperCase().trim();
      const playerId = String(input.player_id || '').trim();

      const room = getRoom(roomId);
      if (!room) {
        return res.json({ success: false, error: 'Salon introuvable' });
      }

      const now = Math.floor(Date.now() / 1000);
      let opponentDisconnected = false;
      if (room.status === 'playing') {
        if (room.playerX && room.playerX.id === playerId) {
          if (room.playerO && now - room.playerO.lastSeen > 12) {
            opponentDisconnected = true;
          }
        } else if (room.playerO && room.playerO.id === playerId) {
          if (room.playerX && now - room.playerX.lastSeen > 12) {
            opponentDisconnected = true;
          }
        }
      }

      if (playerId) {
        if (room.playerX && room.playerX.id === playerId) {
          room.playerX.lastSeen = now;
          saveRoom(room);
        } else if (room.playerO && room.playerO.id === playerId) {
          room.playerO.lastSeen = now;
          saveRoom(room);
        }
      }

      return res.json({
        success: true,
        room: { ...room, opponentDisconnected },
      });
    }

    case 'play_move': {
      const roomId = String(input.room_id || '').toUpperCase().trim();
      const playerId = String(input.player_id || '').trim();
      const index = parseInt(input.index, 10);

      const room = getRoom(roomId);
      if (!room) {
        return res.json({ success: false, error: 'Salon introuvable' });
      }

      let sym: 'X' | 'O' | null = null;
      if (room.playerX && room.playerX.id === playerId) sym = 'X';
      if (room.playerO && room.playerO.id === playerId) sym = 'O';

      if (!sym) {
        return res.json({ success: false, error: 'Joueur non autorisé' });
      }
      if (room.status !== 'playing') {
        return res.json({ success: false, error: "La partie n'est pas en cours" });
      }
      if (room.currentTurn !== sym) {
        return res.json({ success: false, error: "Ce n'est pas votre tour" });
      }
      if (index < 0 || index >= room.board.length || room.board[index] !== null) {
        return res.json({ success: false, error: 'Case invalide ou déjà occupée' });
      }

      room.moveHistory = room.moveHistory || [];
      room.moveHistory.push({ index, symbol: sym });
      room.board[index] = sym;
      room.rematchVotes = [];

      const winRes = checkBoardWinner(room.board, room.size, room.winLen);
      if (winRes !== null) {
        room.status = 'finished';
        room.winner = winRes.winner;
        room.winningCombo = winRes.combo;

        if (winRes.winner === 'X') room.scores.X++;
        else if (winRes.winner === 'O') room.scores.O++;
        else room.scores.ties++;

        if (room.targetWins > 1) {
          if (room.scores.X >= room.targetWins) room.tournamentWinner = 'X';
          else if (room.scores.O >= room.targetWins) room.tournamentWinner = 'O';
        }
      } else {
        room.currentTurn = sym === 'X' ? 'O' : 'X';
      }

      saveRoom(room);
      return res.json({ success: true, room });
    }

    case 'rematch': {
      const roomId = String(input.room_id || '').toUpperCase().trim();
      const playerId = String(input.player_id || '').trim();

      const room = getRoom(roomId);
      if (!room) {
        return res.json({ success: false, error: 'Salon introuvable' });
      }

      room.rematchVotes = room.rematchVotes || [];
      if (!room.rematchVotes.includes(playerId)) {
        room.rematchVotes.push(playerId);
      }

      const bothVoted = room.rematchVotes.length >= 2 || (room.playerO === null && room.rematchVotes.length >= 1);
      if (bothVoted) {
        const nextStart = (room.startingPlayer || 'X') === 'X' ? 'O' : 'X';
        room.startingPlayer = nextStart;
        room.currentTurn = nextStart;
        room.board = Array(room.size * room.size).fill(null);
        room.moveHistory = [];
        room.status = 'playing';
        room.winner = null;
        room.winningCombo = [];
        room.rematchVotes = [];

        if (room.tournamentWinner !== null) {
          room.scores = { X: 0, O: 0, ties: 0 };
          room.tournamentWinner = null;
        }
      }

      saveRoom(room);
      return res.json({ success: true, room, bothVoted });
    }

    case 'leave_room': {
      const roomId = String(input.room_id || '').toUpperCase().trim();
      const playerId = String(input.player_id || '').trim();

      const room = getRoom(roomId);
      if (room) {
        const isX = room.playerX && room.playerX.id === playerId;
        const isO = room.playerO && room.playerO.id === playerId;
        if (isX || isO) {
          room.status = 'abandoned';
          saveRoom(room);
          return res.json({ success: true });
        }
      }
      return res.json({ success: false });
    }

    case 'request_room_action': {
      const roomId = String(input.room_id || '').toUpperCase().trim();
      const playerId = String(input.player_id || '').trim();
      const operation = input.operation;
      const requestId = String(input.request_id || '');
      const requestedAction = String(input.requested_action || '');
      const allowed = ['undo', 'next_round', 'reset_all', 'menu', 'change_grid'];
      const requestedSize = parseInt(input.size, 10) || 3;
      const approved = Boolean(input.approved);

      const room = getRoom(roomId);
      if (!room) {
        return res.json({ success: false, error: 'Salon introuvable' });
      }

      const isX = room.playerX?.id === playerId;
      const isO = room.playerO?.id === playerId;
      if (!isX && !isO) {
        return res.json({ success: false, error: 'Joueur non autorisé' });
      }

      const now = Math.floor(Date.now() / 1000);

      if (operation === 'request') {
        if (room.pendingAction && now - (room.pendingAction.createdAt || 0) > 30) {
          room.pendingAction = null;
        }
        if (!allowed.includes(requestedAction) || room.pendingAction) {
          return res.json({ success: false, error: 'Une autre demande est déjà en attente' });
        }
        if (requestedAction === 'undo' && (!room.moveHistory || room.moveHistory.length === 0)) {
          return res.json({ success: false, error: 'Aucun coup à annuler' });
        }
        room.pendingAction = {
          id: crypto.randomBytes(12).toString('hex'),
          action: requestedAction,
          requesterId: playerId,
          requesterName: isX ? room.playerX!.name : room.playerO!.name,
          createdAt: now,
          size: requestedSize,
        };
      } else if (operation === 'respond') {
        const pending = room.pendingAction;
        if (!pending || pending.id !== requestId || pending.requesterId === playerId) {
          return res.json({ success: false, error: 'Demande introuvable ou non autorisée' });
        }
        if (approved) {
          applyRoomAction(room, pending.action);
          room.lastActionResult = { id: requestId, approved: true, by: playerId };
        } else {
          room.pendingAction = null;
          room.lastActionResult = { id: requestId, approved: false, by: playerId };
        }
      } else if (operation === 'cancel') {
        const pending = room.pendingAction;
        if (!pending || pending.id !== requestId || pending.requesterId !== playerId) {
          return res.json({ success: false, error: 'Cette demande ne peut pas être annulée' });
        }
        room.pendingAction = null;
        room.lastActionResult = { id: requestId, approved: false, cancelled: true, by: playerId };
      }

      saveRoom(room);
      return res.json({ success: true, room });
    }

    default:
      return res.json({ success: false, error: 'Action inconnue' });
  }
});

app.listen(PORT, '0.0.0.0', () => {
  console.log(`Multi-Games server running on http://0.0.0.0:${PORT}`);
});
