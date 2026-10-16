/**
 * Multi-Games - Logique Complète Morpion (Local, Bot & Wi-Fi)
 * Version Robuste : Concurrence, Reconnexion, Tournoi, Animations, Clavier & a11y
 */

const MorpionGame = (function() {
    // État du jeu
    let mode = 'local'; // 'local', 'bot', 'wifi'
    let botLevel = 'medium';
    let isRanked = false; // true pendant un match classé (points + XP classés)
    let botStartChoice = 'player'; // 'player' (X), 'bot' (O), 'alternate'
    let boardSize = 3;  // 3 ou 10
    let winLength = 3;  // 3 ou 5
    let board = [];
    let moveHistory = [];
    let currentPlayer = 'X';
    let startingPlayer = 'X';
    let isGameOver = false;
    let isBotThinking = false;
    let botTimer = null;
    let focusedCellIndex = 0;

    let scores = { X: 0, O: 0, ties: 0 };
    let playerNames = { X: 'Joueur 1', O: 'Joueur 2' };

    // Mode Tournoi
    let isTournament = false;
    let tournamentTarget = 3; // 1er à X victoires

    // Mode Wi-Fi
    let wifiRoomId = null;
    let wifiPlayerId = null;
    let wifiSymbol = null;
    let wifiOpponentPresent = false;
    let wifiPollTimer = null;
    let activeTrackedGameId = null;
    let pendingTrackedGame = null;
    let botDecisionHistory = [];
    let pendingGameAction = null;
    let activeRoomPromptId = null;
    let seenRoomActionResultId = null;
    let playerSymbols = {
        X: localStorage.getItem('mg_symbol_x') || '✕',
        O: localStorage.getItem('mg_symbol_o') || '○'
    };

    const gameActionNames = {
        undo: 'annuler le dernier coup',
        next_round: 'recommencer la manche',
        reset_all: 'réinitialiser les scores et la partie',
        menu: 'retourner au menu principal',
        change_grid: 'changer la taille de la grille'
    };

    function symbolFor(player) {
        return playerSymbols[player] || player;
    }

    function getWinLength(size) {
        return size === 3 ? 3 : (size === 5 ? 4 : 5);
    }

    function beginTrackedGame(gameMode, level = null, size = boardSize, opponentName = '') {
        activeTrackedGameId = null;
        const startPromise = fetch('api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.MG_CSRF || ''
            },
            body: JSON.stringify({
                action: 'start_game',
                mode: gameMode,
                game_id: 'morpion',
                opponent_name: opponentName || (gameMode === 'bot' ? 'Space' : (gameMode === 'wifi' ? 'Joueur Wi-Fi' : 'Joueur 2')),
                bot_level: level,
                board_size: size,
                is_ranked: isRanked
            })
        }).then(async response => {
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || `Enregistrement de la partie impossible (${response.status}).`);
            }
            return data.game_id;
        }).catch(error => {
            console.error(error);
            return null;
        });
        pendingTrackedGame = startPromise;
        startPromise.then(gameId => {
            if (pendingTrackedGame === startPromise) {
                activeTrackedGameId = gameId;
            }
        });
    }

    async function finishTrackedGame(winner, decisions = []) {
        const pendingGame = pendingTrackedGame;
        const gameId = activeTrackedGameId || (pendingGame ? await pendingGame : null);
        if (pendingTrackedGame === pendingGame) {
            activeTrackedGameId = null;
            pendingTrackedGame = null;
        }
        if (!gameId) return;

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.MG_CSRF || ''
                },
                body: JSON.stringify({
                    action: 'finish_game',
                    game_id: gameId,
                    winner,
                    decisions
                })
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || `Enregistrement du résultat impossible (${response.status}).`);
            }
            // Le serveur renvoie le détail des points/XP ; on l'affiche au joueur.
            if (data.rewards) {
                showRewards(data.rewards);
            } else if (isRanked && !window.MG_RANKED?.authenticated) {
                /* partie non classée : rien à afficher */
            }
        } catch (error) {
            console.error(error);
        }
    }

    async function loadBotLearningProfile() {
        const response = await fetch('api.php?action=bot_profile', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || `Chargement de la mémoire du bot impossible (${response.status}).`);
        }
        MorpionBot.setLearningProfile(data.profile);
    }

    // ==========================================
    // STATISTIQUES PERSISTANTES (localStorage)
    // ==========================================
    function getStats() {
        return {
            wins: parseInt(localStorage.getItem('mg_stat_wins') || '0', 10),
            losses: parseInt(localStorage.getItem('mg_stat_losses') || '0', 10),
            ties: parseInt(localStorage.getItem('mg_stat_ties') || '0', 10),
            total: parseInt(localStorage.getItem('mg_stat_total') || '0', 10)
        };
    }

    function recordStat(outcome) {
        const stats = getStats();
        stats.total++;
        if (outcome === 'win') stats.wins++;
        else if (outcome === 'loss') stats.losses++;
        else if (outcome === 'tie') stats.ties++;

        localStorage.setItem('mg_stat_wins', stats.wins);
        localStorage.setItem('mg_stat_losses', stats.losses);
        localStorage.setItem('mg_stat_ties', stats.ties);
        localStorage.setItem('mg_stat_total', stats.total);

        updateStatsPill();
    }

    /**
     * La puce de l'en-tête n'affiche plus les compteurs locaux (qui restaient
     * bloqués sur « 0 V | 0 D » tant que le navigateur n'avait rien mémorisé)
     * mais le profil classé réel : rang, points et niveau.
     */
    function updateStatsPill() {
        const modal = document.getElementById('stats-modal');
        if (modal && modal.style.display === 'flex') refreshRankedProfile();
    }

    // ==========================================
    // PROFIL CLASSÉ : RANG, POINTS, NIVEAU, XP
    // ==========================================
    function rankState() {
        if (!window.MG_RANKED) {
            window.MG_RANKED = { authenticated: false, points: 0, rank: null, level: null, tiers: [], season: 1 };
        }
        return window.MG_RANKED;
    }

    function setText(id, value) {
        const node = document.getElementById(id);
        if (node && value !== undefined && value !== null) node.textContent = String(value);
    }

    /** Reflète le profil classé dans l'en-tête, la carte Classé et les modales. */
    function paintRankChip(profile) {
        if (!profile) return;
        const state = rankState();
        const rank = profile.rank || state.rank;
        const level = profile.level || state.level;
        if (!rank) return;

        setText('rank-chip-icon', rank.icon);
        setText('rank-chip-label', rank.label);
        setText('rank-chip-points', rank.points);
        if (level) {
            setText('rank-chip-level', level.level);
            const fill = document.getElementById('rank-chip-xp');
            if (fill) fill.style.width = (level.progress || 0) + '%';
        }
        setText('ranked-card-icon', rank.icon);
        setText('ranked-card-label', rank.label);
        setText('ranked-card-sub', rank.points + ' pts · Niveau ' + (level ? level.level : 1) +
            ' · Saison ' + (profile.season || state.season || 1));
        setText('ranked-modal-icon', rank.icon);
        setText('ranked-modal-label', rank.label);
        setText('ranked-modal-sub', rank.points + ' pts' + (level ? ' · Niveau ' + level.level : ''));
        setText('stats-rank-icon', rank.icon);
        setText('stats-rank-label', rank.label);
        if (level) {
            setText('stats-rank-sub', rank.points + ' pts · Niveau ' + level.level +
                ' · ' + level.into + '/' + (level.next || '—') + ' XP');
        }
        document.querySelectorAll('.ranked-tier').forEach(chip => {
            chip.classList.toggle('is-current', chip.dataset.tier === rank.key);
        });
    }

    /** Recharge le profil classé depuis le serveur (source de vérité). */
    async function refreshRankedProfile() {
        if (!window.MG_RANKED || !window.MG_RANKED.authenticated) return null;
        try {
            const response = await fetch('api.php?action=ranked_profile', { cache: 'no-store' });
            const data = await response.json();
            if (!response.ok || !data.success || !data.profile) return null;
            window.MG_RANKED = Object.assign({}, rankState(), data.profile, { authenticated: true });
            paintRankChip(data.profile);
            return data.profile;
        } catch (error) {
            return null;
        }
    }

    /** Affiche la modale de fin de partie avec les points et l'XP gagnés. */
    function showRewards(rewards) {
        const modal = document.getElementById('reward-modal');
        if (!modal || !rewards) return;

        const ranked = rewards.ranked === true;
        const points = Number(rewards.points || 0);
        const outcomeLabel = { win: 'Victoire', loss: 'Défaite', tie: 'Égalité' }[rewards.outcome] || '';

        setText('reward-title', ranked ? 'Match classé terminé' : 'Partie terminée');
        setText('reward-subtitle', ranked
            ? (outcomeLabel ? outcomeLabel + ' — ' : '') + (rewards.label || '')
            : 'Partie amicale enregistrée.');

        const pointsBox = document.getElementById('reward-points-box');
        if (ranked) {
            setText('reward-points', (points > 0 ? '+' : '') + points);
            if (pointsBox) {
                pointsBox.classList.toggle('is-up', points > 0);
                pointsBox.classList.toggle('is-down', points < 0);
                pointsBox.classList.toggle('is-neutral', points === 0);
            }
            setText('reward-note', (rewards.points_before ?? 0) + ' → ' + (rewards.points_after ?? 0) + ' points classés');
        } else {
            setText('reward-points', 'Aucun');
            if (pointsBox) {
                pointsBox.classList.remove('is-up', 'is-down');
                pointsBox.classList.add('is-neutral');
            }
            setText('reward-note', 'Les parties amicales rapportent de l’XP mais aucun point classé.');
        }

        setText('reward-xp', '+' + Number(rewards.xp || 0));
        setText('reward-rank-icon', rewards.rank_after ? rewards.rank_after.icon : '🎮');
        setText('reward-rank-label', rewards.rank_after ? rewards.rank_after.label : 'Amical');

        const fill = document.getElementById('reward-progress-fill');
        if (fill) fill.style.width = (rewards.rank_after ? rewards.rank_after.progress : 0) + '%';

        const levelUp = document.getElementById('reward-levelup');
        if (levelUp) {
            if (rewards.level_up) {
                levelUp.hidden = false;
                levelUp.textContent = '🎉 Niveau ' + rewards.level_after + ' atteint !';
            } else {
                levelUp.hidden = true;
            }
        }

        if (rewards.profile) paintRankChip(rewards.profile);
        modal.style.display = 'flex';
    }

    // ==========================================
    // CONFETTI
    // ==========================================
    function launchConfetti() {
        const canvas = document.createElement('canvas');
        canvas.style.position = 'fixed';
        canvas.style.inset = '0';
        canvas.style.width = '100vw';
        canvas.style.height = '100vh';
        canvas.style.pointerEvents = 'none';
        canvas.style.zIndex = '9999';
        document.body.appendChild(canvas);

        const ctx = canvas.getContext('2d');
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;

        const particles = [];
        const colors = ['#dc2626', '#ef4444', '#f87171', '#ffffff', '#e4e4e7'];

        for (let i = 0; i < 80; i++) {
            particles.push({
                x: canvas.width / 2,
                y: canvas.height / 2,
                vx: (Math.random() - 0.5) * 16,
                vy: (Math.random() - 0.7) * 18,
                size: Math.random() * 7 + 4,
                color: colors[Math.floor(Math.random() * colors.length)],
                rotation: Math.random() * 360,
                vRot: (Math.random() - 0.5) * 12,
                alpha: 1
            });
        }

        let start = performance.now();
        function frame(now) {
            const elapsed = now - start;
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            let alive = false;
            for (let p of particles) {
                p.x += p.vx;
                p.y += p.vy;
                p.vy += 0.35;
                p.rotation += p.vRot;
                p.alpha -= 0.014;

                if (p.alpha > 0) {
                    alive = true;
                    ctx.save();
                    ctx.globalAlpha = p.alpha;
                    ctx.translate(p.x, p.y);
                    ctx.rotate((p.rotation * Math.PI) / 180);
                    ctx.fillStyle = p.color;
                    ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size);
                    ctx.restore();
                }
            }

            if (alive && elapsed < 3000) {
                requestAnimationFrame(frame);
            } else {
                canvas.remove();
            }
        }
        requestAnimationFrame(frame);
    }

    // ===== Trait de victoire =====
    let winLineKey = '';

    function clearWinLine() {
        document.getElementById('win-line-svg')?.remove();
        winLineKey = '';
        document.querySelectorAll('.board-cell.winning-cell').forEach(cell => {
            cell.classList.remove('winning-cell');
        });
    }

    function drawWinLine(combo, animate = true) {
        if (!combo || combo.length < 2) return;

        const key = combo.join(',');
        // Déjà dessiné pour cette victoire : on ne relance pas l'animation
        if (animate && key === winLineKey && document.getElementById('win-line-svg')) return;

        const container = document.getElementById('board-container') || document.getElementById('game-board') || document.getElementById('board');
        const firstCell = document.querySelector(`.board-cell[data-index="${combo[0]}"]`);
        const lastCell  = document.querySelector(`.board-cell[data-index="${combo[combo.length - 1]}"]`);
        if (!container || !firstCell || !lastCell) return;

        document.getElementById('win-line-svg')?.remove();
        winLineKey = key;

        const c = container.getBoundingClientRect();
        const a = firstCell.getBoundingClientRect();
        const b = lastCell.getBoundingClientRect();

        let x1 = a.left + a.width / 2 - c.left;
        let y1 = a.top  + a.height / 2 - c.top;
        let x2 = b.left + b.width / 2 - c.left;
        let y2 = b.top  + b.height / 2 - c.top;

        // Prolonge le trait un peu au-delà des centres des cases
        const dx = x2 - x1, dy = y2 - y1;
        const dist = Math.hypot(dx, dy) || 1;
        const ext = a.width * 0.32;
        x1 -= (dx / dist) * ext; y1 -= (dy / dist) * ext;
        x2 += (dx / dist) * ext; y2 += (dy / dist) * ext;

        const length = Math.hypot(x2 - x1, y2 - y1);

        const NS = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('id', 'win-line-svg');
        svg.setAttribute('class', 'win-line-svg');

        const line = document.createElementNS(NS, 'line');
        line.setAttribute('class', 'win-line' + (animate ? '' : ' no-anim'));
        line.setAttribute('x1', x1); line.setAttribute('y1', y1);
        line.setAttribute('x2', x2); line.setAttribute('y2', y2);
        line.setAttribute('stroke-width', Math.max(5, a.width * 0.12));
        line.style.setProperty('--len', length);

        combo.forEach(idx => {
            document.querySelector(`.board-cell[data-index="${idx}"]`)?.classList.add('winning-cell');
        });

        svg.appendChild(line);
        container.appendChild(svg);
    }

    // Si la fenêtre change de taille, on repositionne le trait (sans rejouer l'animation)
    window.addEventListener('resize', () => {
        if (winLineKey) drawWinLine(winLineKey.split(',').map(Number), false);
    });

    // ==========================================
    // INITIALISATION & RENDU
    // ==========================================
    function initBoard() {
        clearWinLine();
        if (botTimer) {
            clearTimeout(botTimer);
            botTimer = null;
        }
        isBotThinking = false;
        isGameOver = false;

        board = new Array(boardSize * boardSize).fill(null);
        moveHistory = [];

        const banner = document.getElementById('status-banner');
        if (banner) banner.style.display = 'none';

        renderBoard();
        updateScoreboard();
        updateTurnIndicator();

        // Si le bot commence la manche
        if (mode === 'bot' && currentPlayer === 'O') {
            triggerBotMove();
        }
    }

    function renderBoard() {
        const boardEl = document.getElementById('game-board') || document.getElementById('board');
        if (!boardEl) return;

        // Uniformise la grille selon la consigne :
        // Le plateau utilise display: grid, repeat(N, 1fr) et gap: 0
        boardEl.className = 'board-grid';
        boardEl.style.display = 'grid';
        boardEl.style.gridTemplateColumns = `repeat(${boardSize}, 1fr)`;
        boardEl.style.gridTemplateRows = `repeat(${boardSize}, 1fr)`;
        boardEl.style.gridAutoRows = '1fr';
        boardEl.style.gap = '0';
        boardEl.style.setProperty('--grid-n', boardSize);

        const gridSizeSelect = document.getElementById('arena-grid-size');
        if (gridSizeSelect) gridSizeSelect.value = String(boardSize);
        boardEl.setAttribute('role', 'grid');
        boardEl.setAttribute('aria-label', `Plateau de morpion ${boardSize} par ${boardSize}`);

        // Les cases sont créées une seule fois puis mises à jour sur place :
        // reconstruire tout le plateau à chaque coup provoquait un clignotement,
        // relançait les écouteurs et empêchait toute transition CSS (et devenait
        // coûteux sur une grille 19x19, soit 361 cases).
        const existing = boardEl.children;
        const sameGrid = boardEl.dataset.gridSize === String(boardSize) && existing.length === board.length;

        if (!sameGrid) {
            boardEl.replaceChildren();
            boardEl.dataset.gridSize = String(boardSize);
        }

        const prevBoard = moveHistory.length ? moveHistory[moveHistory.length - 1].board : null;
        let lastIdx = -1;
        if (prevBoard) {
            for (let i = 0; i < board.length; i++) {
                if (board[i] && !prevBoard[i]) {
                    lastIdx = i;
                    break;
                }
            }
        }

        board.forEach((val, idx) => {
            let cell = sameGrid ? existing[idx] : null;
            if (!cell) {
                cell = document.createElement('div');
                cell.dataset.index = idx;
                cell.setAttribute('role', 'gridcell');
                cell.addEventListener('click', () => handleCellClick(idx));
                cell.addEventListener('keydown', (e) => handleCellKeyDown(e, idx));
                boardEl.appendChild(cell);
            }
            const classes = 'board-cell cell'
                + (val ? ` cell-${val.toLowerCase()} filled` : '')
                + (idx === lastIdx ? ' last-move' : '');
            if (cell.className !== classes) cell.className = classes;
            const symbol = val ? symbolFor(val) : '';
            if (cell.textContent !== symbol) cell.textContent = symbol;
            cell.setAttribute('tabindex', idx === focusedCellIndex ? '0' : '-1');

            const row = Math.floor(idx / boardSize) + 1;
            const col = (idx % boardSize) + 1;
            cell.setAttribute('aria-label', `Ligne ${row}, Colonne ${col}, ${val ? 'symbole ' + val : 'case vide'}`);
        });
    }

    // ==========================================
    // CLAVIER & ACCESSIBILITÉ
    // ==========================================
    function handleCellKeyDown(e, idx) {
        let newIdx = idx;
        const row = Math.floor(idx / boardSize);
        const col = idx % boardSize;

        if (e.key === 'ArrowRight') {
            e.preventDefault();
            newIdx = row * boardSize + Math.min(boardSize - 1, col + 1);
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            newIdx = row * boardSize + Math.max(0, col - 1);
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            newIdx = Math.min(boardSize - 1, row + 1) * boardSize + col;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            newIdx = Math.max(0, row - 1) * boardSize + col;
        } else if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            handleCellClick(idx);
            return;
        }

        if (newIdx !== idx) {
            focusedCellIndex = newIdx;
            const newCell = document.querySelector(`.board-cell[data-index="${newIdx}"]`);
            if (newCell) {
                document.querySelectorAll('.board-cell').forEach(c => c.setAttribute('tabindex', '-1'));
                newCell.setAttribute('tabindex', '0');
                newCell.focus();
            }
        }
    }

    // ==========================================
    // VÉRIFICATION DE VICTOIRE
    // ==========================================
    function checkWinCondition() {
        // Lignes
        for (let r = 0; r < boardSize; r++) {
            for (let c = 0; c <= boardSize - winLength; c++) {
                const sym = board[r * boardSize + c];
                if (!sym) continue;
                let match = true;
                const combo = [r * boardSize + c];
                for (let k = 1; k < winLength; k++) {
                    if (board[r * boardSize + c + k] !== sym) { match = false; break; }
                    combo.push(r * boardSize + c + k);
                }
                if (match) return { winner: sym, combo };
            }
        }
        // Colonnes
        for (let c = 0; c < boardSize; c++) {
            for (let r = 0; r <= boardSize - winLength; r++) {
                const sym = board[r * boardSize + c];
                if (!sym) continue;
                let match = true;
                const combo = [r * boardSize + c];
                for (let k = 1; k < winLength; k++) {
                    if (board[(r + k) * boardSize + c] !== sym) { match = false; break; }
                    combo.push((r + k) * boardSize + c);
                }
                if (match) return { winner: sym, combo };
            }
        }
        // Diagonale \
        for (let r = 0; r <= boardSize - winLength; r++) {
            for (let c = 0; c <= boardSize - winLength; c++) {
                const sym = board[r * boardSize + c];
                if (!sym) continue;
                let match = true;
                const combo = [r * boardSize + c];
                for (let k = 1; k < winLength; k++) {
                    if (board[(r + k) * boardSize + (c + k)] !== sym) { match = false; break; }
                    combo.push((r + k) * boardSize + (c + k));
                }
                if (match) return { winner: sym, combo };
            }
        }
        // Diagonale /
        for (let r = 0; r <= boardSize - winLength; r++) {
            for (let c = winLength - 1; c < boardSize; c++) {
                const sym = board[r * boardSize + c];
                if (!sym) continue;
                let match = true;
                const combo = [r * boardSize + c];
                for (let k = 1; k < winLength; k++) {
                    if (board[(r + k) * boardSize + (c - k)] !== sym) { match = false; break; }
                    combo.push((r + k) * boardSize + (c - k));
                }
                if (match) return { winner: sym, combo };
            }
        }

        if (board.every(cell => cell !== null && cell !== '')) {
            return { winner: 'TIE', combo: [] };
        }
        return null;
    }

    // ==========================================
    // GESTION DU CLIC SUR CASE
    // ==========================================
    function handleCellClick(index) {
        if (isGameOver || board[index] !== null) return;

        // En mode bot : refuser le clic si c'est au bot ou s'il réfléchit
        if (mode === 'bot') {
            if (currentPlayer !== 'X' || isBotThinking) return;
        }

        // En mode Wi-Fi : vérifier que c'est le tour du joueur
        if (mode === 'wifi') {
            if (currentPlayer !== wifiSymbol) return;
            sendWifiMove(index);
            return;
        }

        // Mode Local ou Bot
        executeMove(index, currentPlayer);

        if (mode === 'bot' && !isGameOver && currentPlayer === 'O') {
            triggerBotMove();
        }
    }

    function triggerBotMove() {
        isBotThinking = true;
        updateTurnIndicator();

        const delay = boardSize > 5 ? 300 : 350;
        botTimer = setTimeout(() => {
            if (isGameOver || mode !== 'bot') {
                isBotThinking = false;
                return;
            }

            const botMove = MorpionBot.computeMove(botLevel, [...board], 'O', 'X', boardSize, winLength);
            isBotThinking = false;

            if (botMove !== null && board[botMove] === null && !isGameOver) {
                botDecisionHistory.push({
                    board: board.map(cell => cell || '-').join(''),
                    move: botMove,
                    size: boardSize,
                    winLength
                });
                executeMove(botMove, 'O');
            }
        }, delay);
    }

    function executeMove(index, symbol) {
        moveHistory.push({
            board: [...board],
            currentPlayer,
            scores: { ...scores }
        });
        board[index] = symbol;

        if (symbol === 'X') SoundEffects.playX();
        else SoundEffects.playO();

        renderBoard();

        const winResult = checkWinCondition();
        if (winResult) {
            handleGameEnd(winResult);
        } else {
            currentPlayer = currentPlayer === 'X' ? 'O' : 'X';
            updateScoreboard();
            updateTurnIndicator();
        }
    }

    function handleGameEnd(result) {
        isGameOver = true;
        if (botTimer) { clearTimeout(botTimer); botTimer = null; }
        isBotThinking = false;
        finishTrackedGame(result.winner, mode === 'bot' ? botDecisionHistory.slice(-1) : []);

        if (result.winner === 'TIE') {
            scores.ties++;
            SoundEffects.tie();
            showRoundEndModal('Match nul ! Choisissez la suite de la partie.');
            if (mode === 'bot') {
                MorpionBot.recordGameResult(false, true);
                recordStat('tie');
            }
        } else {
            scores[result.winner]++;
            SoundEffects.win();

            if (result.combo) {
                drawWinLine(result.combo, true);
            }

            const winnerName = playerNames[result.winner] || `Joueur ${result.winner}`;
            showRoundEndModal(`Victoire de ${winnerName} ! Choisissez la suite de la partie.`);

            if (mode === 'bot') {
                const playerWon = (result.winner === 'X');
                MorpionBot.recordGameResult(playerWon, false);
                recordStat(playerWon ? 'win' : 'loss');
            } else if (mode === 'local') {
                recordStat('win');
            }
        }

        updateScoreboard();
        updateTurnIndicator();

        // Fin de Tournoi
        if (isTournament) {
            if (scores.X >= tournamentTarget || scores.O >= tournamentTarget) {
                const grandWinner = scores.X >= tournamentTarget ? playerNames.X : playerNames.O;
                showRoundEndModal(`Tournoi remporté par ${grandWinner} (${scores.X} - ${scores.O}).`);
            }
        }
    }

    function showRoundEndModal(message) {
        const modal = document.getElementById('round-end-modal');
        const text = document.getElementById('round-end-message');
        if (text) text.textContent = message;
        if (modal) modal.style.display = 'flex';
    }

    function closeActionModals() {
        ['round-end-modal', 'action-request-modal'].forEach(id => {
            const modal = document.getElementById(id);
            if (modal) modal.style.display = 'none';
        });
    }

    function openActionRequest(message, { canRespond = true, canCancel = false } = {}) {
        const modal = document.getElementById('action-request-modal');
        const text = document.getElementById('action-request-message');
        const buttons = document.getElementById('action-request-buttons');
        const accept = document.getElementById('action-request-accept');
        const decline = document.getElementById('action-request-decline');
        const close = document.getElementById('action-request-close');
        if (text) text.textContent = message;
        if (buttons) buttons.hidden = false;
        if (accept) accept.hidden = !canRespond;
        if (decline) decline.hidden = !canRespond;
        if (close) close.hidden = !canCancel;
        if (modal) modal.style.display = 'flex';
    }

    function performLocalGameAction(action, size = boardSize) {
        closeActionModals();
        pendingGameAction = null;
        if (action === 'menu') {
            showMenuView();
            return;
        }
        if (action === 'undo') {
            if (!moveHistory.length) {
                showStatusBanner('Aucun coup à annuler.');
                return;
            }
            const wasGameOver = isGameOver;
            if (botTimer) {
                clearTimeout(botTimer);
                botTimer = null;
                isBotThinking = false;
            }
            let previous = moveHistory.pop();
            if (mode === 'bot' && currentPlayer === 'X' && previous && previous.board.some(cell => cell !== null)) {
                previous = moveHistory.pop() || previous;
            }
            board = [...previous.board];
            currentPlayer = previous.currentPlayer;
            scores = { ...previous.scores };
            isGameOver = false;
            clearWinLine();
            renderBoard();
            updateScoreboard();
            updateTurnIndicator();
            if (wasGameOver) beginTrackedGame(mode, mode === 'bot' ? botLevel : null, boardSize);
            if (mode === 'bot' && currentPlayer === 'O') triggerBotMove();
            return;
        }
        if (action === 'change_grid') {
            boardSize = size;
            winLength = getWinLength(size);
        } else if (action === 'reset_all') {
            scores = { X: 0, O: 0, ties: 0 };
        } else if (mode === 'bot' && botStartChoice === 'alternate') {
            startingPlayer = startingPlayer === 'X' ? 'O' : 'X';
        } else if (mode !== 'bot') {
            startingPlayer = startingPlayer === 'X' ? 'O' : 'X';
        }
        currentPlayer = startingPlayer;
        initBoard();
        botDecisionHistory = [];
        beginTrackedGame(mode, mode === 'bot' ? botLevel : null, boardSize);
    }

    async function requestWifiAction(action, size = boardSize) {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'request_room_action',
                room_id: wifiRoomId,
                player_id: wifiPlayerId,
                operation: 'request',
                requested_action: action,
                size
            })
        });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.error || 'La demande n’a pas pu être envoyée.');
        activeRoomPromptId = data.room?.pendingAction?.id || null;
        openActionRequest(`Demande envoyée : ${gameActionNames[action]}. En attente de la réponse de votre adversaire.`, {
            canRespond: false,
            canCancel: true
        });
        syncWifiState(data.room);
    }

    async function respondWifiAction(approved) {
        const pending = pendingGameAction;
        if (!pending || mode !== 'wifi') return;
        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'request_room_action',
                    room_id: wifiRoomId,
                    player_id: wifiPlayerId,
                    operation: 'respond',
                    request_id: pending.id,
                    approved
                })
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.error || 'Réponse impossible.');
            pendingGameAction = null;
            activeRoomPromptId = null;
            closeActionModals();
            syncWifiState(data.room);
        } catch (error) {
            showStatusBanner(error.message);
        }
    }

    async function cancelWifiAction() {
        if (mode !== 'wifi' || !activeRoomPromptId) return;
        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'request_room_action',
                    room_id: wifiRoomId,
                    player_id: wifiPlayerId,
                    operation: 'cancel',
                    request_id: activeRoomPromptId
                })
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.error || 'Annulation impossible.');
            pendingGameAction = null;
            activeRoomPromptId = null;
            closeActionModals();
            syncWifiState(data.room);
        } catch (error) {
            showStatusBanner(error.message);
        }
    }

    function requestGameAction(action, size = boardSize) {
        if (!gameActionNames[action]) return;
        if (mode === 'wifi') {
            if (action === 'menu' && !wifiOpponentPresent) {
                showMenuView();
                return;
            }
            requestWifiAction(action, size).catch(error => showStatusBanner(error.message));
            return;
        }
        if (action === 'undo' && !moveHistory.length) {
            showStatusBanner('Aucun coup à annuler.');
            return;
        }
        if (mode === 'bot') {
            openActionRequest(`Space accepte ${gameActionNames[action]}.`, { canRespond: false });
            setTimeout(() => performLocalGameAction(action, size), 350);
            return;
        }
        pendingGameAction = { action, size };
        const opponent = currentPlayer === 'X' ? playerNames.O : playerNames.X;
        openActionRequest(`${opponent} doit accepter la demande : ${gameActionNames[action]}.`);
    }

    function showStatusBanner(text) {
        const banner = document.getElementById('status-banner');
        if (banner) {
            banner.textContent = text;
            banner.style.display = 'block';
        }
    }

    function updateTurnIndicator() {
        const indicator = document.getElementById('turn-indicator');
        if (!indicator) return;

        if (isGameOver) {
            indicator.textContent = 'Partie terminée — Cliquez sur Rejouer';
            indicator.className = 'turn-indicator';
            setBotThinking(false);
            return;
        }

        if (mode === 'wifi') {
            setBotThinking(false);
            if (currentPlayer === wifiSymbol) {
                indicator.textContent = 'À votre tour de jouer ! (⚡)';
                indicator.className = 'turn-indicator turn-x';
            } else {
                indicator.textContent = 'En attente du coup de votre adversaire...';
                indicator.className = 'turn-indicator turn-o';
            }
            return;
        }

        if (mode === 'bot') {
            setBotThinking(isBotThinking);
            if (isBotThinking) {
                indicator.textContent = 'Le bot réfléchit... 🤖';
                indicator.className = 'turn-indicator turn-o';
            } else {
                indicator.textContent = 'À votre tour (✕)';
                indicator.className = 'turn-indicator turn-x';
            }
            return;
        }

        // Local
        setBotThinking(false);
        const name = playerNames[currentPlayer] || `Joueur ${currentPlayer}`;
        indicator.textContent = `Au tour de ${name} (${currentPlayer === 'X' ? '✕' : '○'})`;
        indicator.className = `turn-indicator turn-${currentPlayer.toLowerCase()}`;
    }

    /** Affiche / masque l'indicateur animé « le bot réfléchit ». */
    function setBotThinking(active) {
        const box = document.getElementById('player-o-box');
        if (box) box.classList.toggle('is-thinking', !!active && mode === 'bot');
    }

    function updateScoreboard() {
        const nameX = document.getElementById('name-x');
        const nameO = document.getElementById('name-o');
        const scoreX = document.getElementById('score-x');
        const scoreO = document.getElementById('score-o');
        const scoreTies = document.getElementById('score-ties');
        const playerXBox = document.getElementById('player-x-box');
        const playerOBox = document.getElementById('player-o-box');
        const symbolX = document.getElementById('symbol-x-score');
        const symbolO = document.getElementById('symbol-o-score');

        if (nameX) nameX.textContent = playerNames.X;
        if (nameO) nameO.textContent = playerNames.O;
        if (scoreX) scoreX.textContent = scores.X;
        if (scoreO) scoreO.textContent = scores.O;
        if (scoreTies) scoreTies.textContent = scores.ties;
        if (symbolX) symbolX.textContent = symbolFor('X');
        if (symbolO) symbolO.textContent = symbolFor('O');

        if (playerXBox && playerOBox) {
            if (currentPlayer === 'X' && !isGameOver) {
                playerXBox.classList.add('active');
                playerOBox.classList.remove('active');
            } else if (currentPlayer === 'O' && !isGameOver) {
                playerOBox.classList.add('active');
                playerXBox.classList.remove('active');
            } else {
                playerXBox.classList.remove('active');
                playerOBox.classList.remove('active');
            }
        }
    }

    function showTournamentVictoryModal(winnerName, scoreX, scoreO) {
        document.getElementById('tournament-winner-name').textContent = winnerName;
        document.getElementById('tournament-final-scores').textContent = `${scoreX} - ${scoreO}`;
        document.getElementById('tournament-victory-modal').style.display = 'flex';
        SoundEffects.win();
    }

    function showGameView() {
        document.getElementById('menu-view').style.display = 'none';
        document.getElementById('game-view').style.display = 'flex';
        // En pleine partie la page ne défile plus : le pied de page est masqué
        // pour que le plateau tienne intégralement dans la fenêtre.
        document.body.classList.add('in-game');
        const banner = document.getElementById('status-banner');
        if (banner) banner.style.display = 'none';
    }

    function showMenuView() {
        if (botTimer) { clearTimeout(botTimer); botTimer = null; }
        if (wifiPollTimer) { clearInterval(wifiPollTimer); wifiPollTimer = null; }

        if (mode === 'wifi' && wifiRoomId && wifiPlayerId) {
            // Prévenir le serveur de la sortie
            fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'leave_room', room_id: wifiRoomId, player_id: wifiPlayerId })
            }).catch(() => {});
            sessionStorage.removeItem('mg_wifi_room');
            sessionStorage.removeItem('mg_wifi_player');
            sessionStorage.removeItem('mg_wifi_symbol');
        }

        closeActionModals();
        document.body.classList.remove('in-game');
        document.getElementById('game-view').style.display = 'none';
        document.getElementById('menu-view').style.display = 'block';
    }

    // ==========================================
    // MULTIJOUEUR WI-FI AVEC RECONNEXION
    // ==========================================
    async function startWifiHost(size = 3, targetWins = 1) {
        boardSize = size;
        winLength = getWinLength(size);
        const hostName = document.getElementById('wifi-host-name')?.value || 'Joueur 1';

        try {
            const res = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'create_room',
                    size: boardSize,
                    target_wins: targetWins,
                    player_name: hostName
                })
            });
            const data = await res.json();
            if (data.success) {
                wifiRoomId = data.roomId;
                wifiPlayerId = data.playerId;
                wifiSymbol = 'X';
                mode = 'wifi';
                playerNames.X = `${hostName} (Vous)`;
                playerNames.O = 'En attente...';

                // Sauvegarder pour reconnexion
                sessionStorage.setItem('mg_wifi_room', wifiRoomId);
                sessionStorage.setItem('mg_wifi_player', wifiPlayerId);
                sessionStorage.setItem('mg_wifi_symbol', wifiSymbol);

                document.getElementById('wifi-room-code').textContent = data.roomId;

                // Construction avec l'IP LAN PHP sécurisée
                const baseUrl = window.LAN_BASE_URL || (window.location.origin + window.location.pathname);
                const shareUrl = baseUrl + '?room=' + data.roomId;
                const linkInput = document.getElementById('wifi-share-link');
                if (linkInput) linkInput.value = shareUrl;

                closeAllModals();
                document.getElementById('wifi-host-modal').style.display = 'flex';
                beginTrackedGame('wifi', null, boardSize);
                startWifiPolling();
            }
        } catch (e) {
            alert('Impossible de créer le salon Wi-Fi');
        }
    }

    async function joinWifiRoom(roomId, name = 'Joueur 2') {
        const cleanRoom = roomId.trim().toUpperCase();
        const existingPlayerId = sessionStorage.getItem('mg_wifi_player') || '';

        try {
            const res = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'join_room',
                    room_id: cleanRoom,
                    player_name: name,
                    player_id: existingPlayerId
                })
            });
            const data = await res.json();
            if (data.success) {
                wifiRoomId = data.roomId;
                wifiPlayerId = data.playerId;
                wifiSymbol = data.symbol;
                mode = 'wifi';
                boardSize = data.room.size;
                winLength = data.room.winLen;
                scores = data.room.scores;

                sessionStorage.setItem('mg_wifi_room', wifiRoomId);
                sessionStorage.setItem('mg_wifi_player', wifiPlayerId);
                sessionStorage.setItem('mg_wifi_symbol', wifiSymbol);

                if (wifiSymbol === 'X') {
                    playerNames.X = (data.room.playerX?.name || 'Hôte') + ' (Vous)';
                    playerNames.O = data.room.playerO?.name || 'Joueur 2';
                } else {
                    playerNames.X = data.room.playerX?.name || 'Hôte';
                    playerNames.O = `${name} (Vous)`;
                }

                closeAllModals();
                showGameView();
                initBoard();
                beginTrackedGame('wifi', null, boardSize);
                startWifiPolling();
            } else {
                alert(data.error || 'Erreur lors de la connexion au salon');
            }
        } catch (e) {
            alert('Erreur réseau lors de la tentative de connexion');
        }
    }

    async function sendWifiMove(index) {
        try {
            const res = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'play_move',
                    room_id: wifiRoomId,
                    player_id: wifiPlayerId,
                    index: index
                })
            });
            const data = await res.json();
            if (data.success) {
                syncWifiState(data.room);
            }
        } catch (e) {}
    }

    function startWifiPolling() {
        if (wifiPollTimer) clearInterval(wifiPollTimer);
        wifiPollTimer = setInterval(async () => {
            if (!wifiRoomId) return;
            try {
                const res = await fetch(`api.php?action=get_state&room_id=${wifiRoomId}&player_id=${wifiPlayerId}&t=${Date.now()}`);
                const data = await res.json();
                if (data.success) {
                    syncWifiState(data.room);
                }
            } catch (e) {}
        }, 500);
    }

    function syncWifiState(room) {
        // Détection d'abandon
        if (room.status === 'abandoned') {
            if (wifiPollTimer) {
                clearInterval(wifiPollTimer);
                wifiPollTimer = null;
            }
            sessionStorage.removeItem('mg_wifi_room');
            sessionStorage.removeItem('mg_wifi_player');
            sessionStorage.removeItem('mg_wifi_symbol');
            mode = 'local';
            showMenuView();
            return;
        }

        // Avertissement de déconnexion
        if (room.opponentDisconnected) {
            showStatusBanner('⚠️ Adversaire inactif (connexion lente ou perdue)...');
        }

        // Mise à jour de la grille
        const previousStatus = isGameOver;
        board = room.board;
        boardSize = room.size;
        winLength = room.winLen;
        currentPlayer = room.currentTurn;
        scores = room.scores;
        wifiOpponentPresent = Boolean(room.playerX && room.playerO);
        isGameOver = room.status === 'finished';

        if (wifiSymbol === 'X') {
            playerNames.X = (room.playerX?.name || 'Hôte') + ' (Vous)';
            playerNames.O = room.playerO ? room.playerO.name : 'En attente d\'un ami...';
        } else {
            playerNames.X = room.playerX?.name || 'Hôte';
            playerNames.O = (room.playerO?.name || 'Joueur 2') + ' (Vous)';
        }

        renderBoard();
        updateScoreboard();
        updateTurnIndicator();

        // Si partie terminée
        if (room.status === 'finished') {
            isGameOver = true;
            if (!previousStatus) {
                finishTrackedGame(room.winner, []);
                const result = room.winner === 'TIE'
                    ? 'Match nul ! Choisissez la suite de la partie.'
                    : `Victoire de ${playerNames[room.winner]} ! Choisissez la suite de la partie.`;
                showRoundEndModal(result);
            }

            // Victoire tournoi Wi-Fi
            if (room.tournamentWinner && !previousStatus) {
                const tourneyWinnerName = room.tournamentWinner === 'X' ? room.playerX?.name : room.playerO?.name;
                showRoundEndModal(`Tournoi remporté par ${tourneyWinnerName} (${room.scores.X} - ${room.scores.O}).`);
            }
        } else {
            // Nouvelle manche : on réactive le jeu et on efface le trait
            isGameOver = false;
            clearWinLine();
            const banner = document.getElementById('status-banner');
            if (banner && !room.opponentDisconnected) banner.style.display = 'none';

            // Fermer la modale d'attente d'hôte si elle était ouverte
            const hostModal = document.getElementById('wifi-host-modal');
            if (hostModal && hostModal.style.display === 'flex') {
                hostModal.style.display = 'none';
                mode = 'wifi';
                showGameView();
            }
            if (previousStatus) beginTrackedGame('wifi', null, boardSize);
        }

        const pendingAction = room.pendingAction;
        if (pendingAction && pendingAction.id !== activeRoomPromptId) {
            activeRoomPromptId = pendingAction.id;
            if (pendingAction.requesterId === wifiPlayerId) {
                openActionRequest(`Demande envoyée : ${gameActionNames[pendingAction.action] || pendingAction.action}. En attente de la réponse de votre adversaire.`, {
                    canRespond: false,
                    canCancel: true
                });
            } else {
                pendingGameAction = pendingAction;
                openActionRequest(`${pendingAction.requesterName} demande à ${gameActionNames[pendingAction.action] || pendingAction.action}.`);
            }
        }

        const actionResult = room.lastActionResult;
        if (actionResult && actionResult.id !== seenRoomActionResultId) {
            seenRoomActionResultId = actionResult.id;
            if (activeRoomPromptId === actionResult.id) {
                activeRoomPromptId = null;
                pendingGameAction = null;
                closeActionModals();
                if (actionResult.cancelled) showStatusBanner('Demande annulée.');
                else if (!actionResult.approved) showStatusBanner('Votre adversaire a refusé la demande.');
                else if (actionResult.by !== wifiPlayerId) showStatusBanner('Demande acceptée.');
            }
        }

        // Retour visuel sur le bouton Rejouer
        const rematchBtn = document.getElementById('btn-rematch');
        if (rematchBtn) {
            if (room.rematchVotes && room.rematchVotes.includes(wifiPlayerId) && room.status === 'finished') {
                rematchBtn.textContent = '⏳ En attente de l\'adversaire...';
            } else {
                rematchBtn.textContent = '🔄 Rejouer';
            }
        }
    }

    function closeAllModals() {
        document.querySelectorAll('.modal-overlay').forEach(m => m.style.display = 'none');
        // Si on ferme la modale d'attente hôte sans jouer, arrêter le polling
        const hostModal = document.getElementById('wifi-host-modal');
        if (hostModal && hostModal.style.display === 'none' && document.getElementById('game-view').style.display === 'none') {
            if (wifiPollTimer) clearInterval(wifiPollTimer);
        }
    }

    // Tentative de restauration de session Wi-Fi au rechargement de page
    function checkSessionRecovery() {
        const savedRoom = sessionStorage.getItem('mg_wifi_room');
        const savedPlayer = sessionStorage.getItem('mg_wifi_player');
        if (savedRoom && savedPlayer) {
            wifiRoomId = savedRoom;
            wifiPlayerId = savedPlayer;
            wifiSymbol = sessionStorage.getItem('mg_wifi_symbol') || 'O';
            mode = 'wifi';
            showGameView();
            startWifiPolling();
        }
    }

    function toggleBoardSize() {
        const container = document.getElementById('board-container');
        if (container) {
            container.classList.toggle('board-xl');
            const isXl = container.classList.contains('board-xl');
            localStorage.setItem('mg_board_xl', isXl ? 'true' : 'false');
            const btn = document.getElementById('btn-toggle-size');
            if (btn) btn.innerHTML = isXl ? '<span>🔍 Normal</span>' : '<span>🔍 Grand (XL)</span>';
            if (winLineKey) {
                setTimeout(() => drawWinLine(winLineKey.split(',').map(Number), false), 50);
            }
        }
    }

    return {
        startLocalMatch: function(size = 3, p1Name = 'Joueur 1', p2Name = 'Joueur 2', ranked = false) {
            isRanked = !!ranked;
            mode = 'local';
            isTournament = false;
            boardSize = size;
            winLength = getWinLength(size);
            startingPlayer = 'X';
            currentPlayer = 'X';
            playerNames = { X: p1Name, O: p2Name };
            scores = { X: 0, O: 0, ties: 0 };
            closeAllModals();
            showGameView();
            initBoard();
            beginTrackedGame('local', null, boardSize, p2Name);
        },
        startTournament: function(targetWins = 3, size = 3, p1Name = 'Joueur 1', p2Name = 'Joueur 2', ranked = false) {
            isRanked = !!ranked;
            mode = 'local';
            isTournament = true;
            tournamentTarget = targetWins;
            boardSize = size;
            winLength = getWinLength(size);
            startingPlayer = 'X';
            currentPlayer = 'X';
            playerNames = { X: p1Name, O: p2Name };
            scores = { X: 0, O: 0, ties: 0 };
            closeAllModals();
            showGameView();
            initBoard();
            beginTrackedGame('local', null, boardSize, p2Name);
        },
        startBotMatch: async function(level = 'medium', size = 3, starter = 'player', playerName = 'Vous', ranked = false) {
            isRanked = !!ranked;
            if (level === 'adaptive') {
                try {
                    await loadBotLearningProfile();
                } catch (error) {
                    console.error(error);
                    alert('La mémoire de votre bot n’a pas pu être chargée. Vérifiez votre connexion puis réessayez.');
                    return;
                }
            } else {
                MorpionBot.setLearningProfile(null);
            }
            mode = 'bot';
            isTournament = false;
            botLevel = level;
            botStartChoice = starter;
            boardSize = size;
            winLength = getWinLength(size);

            if (starter === 'bot') {
                startingPlayer = 'O';
            } else {
                startingPlayer = 'X';
            }
            currentPlayer = startingPlayer;

            playerNames = { X: playerName, O: 'Space' };
            scores = { X: 0, O: 0, ties: 0 };
            botDecisionHistory = [];

            closeAllModals();
            showGameView();
            initBoard();
            beginTrackedGame('bot', botLevel, boardSize);
        },
        /**
         * Lance un match classé. Les points et l'XP sont calculés par le serveur
         * à la fin de la manche (voir showRewards).
         */
        startRankedMatch: async function(opponent = 'bot', options = {}) {
            const opts = options || {};
            if (!rankState().authenticated) {
                window.location.assign('../../account.php');
                return;
            }
            if (opponent === 'bot') {
                await MorpionGame.startBotMatch(opts.level || 'medium', opts.size || 3, opts.starter || 'player', window.MG_PLAYER_NAME || 'Vous', true);
            } else if (opponent === 'wifi') {
                isRanked = true;
                await startWifiHost(opts.size || 3, opts.name);
            } else {
                MorpionGame.startLocalMatch(opts.size || 3, window.MG_PLAYER_NAME || 'Joueur 1', opts.name || 'Joueur 2', true);
            }
        },
        isRankedMatch: function() { return isRanked; },
        refreshRankedProfile: refreshRankedProfile,
        showRewards: showRewards,
        startWifiHost: startWifiHost,
        joinWifiRoom: joinWifiRoom,
        rematch: function() {
            if (mode === 'wifi') {
                beginTrackedGame('wifi', null, boardSize);
                fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'rematch',
                        room_id: wifiRoomId,
                        player_id: wifiPlayerId
                    })
                });
            } else {
                // Alterner qui commence
                if (mode === 'bot') {
                    if (botStartChoice === 'alternate') {
                        startingPlayer = (startingPlayer === 'X') ? 'O' : 'X';
                    } else if (botStartChoice === 'bot') {
                        startingPlayer = 'O';
                    } else {
                        startingPlayer = 'X';
                    }
                } else {
                    startingPlayer = (startingPlayer === 'X') ? 'O' : 'X';
                }
                currentPlayer = startingPlayer;
                initBoard();
                botDecisionHistory = [];
                beginTrackedGame(mode, mode === 'bot' ? botLevel : null, boardSize);
            }
        },
        exitToMenu: showMenuView,
        initEvents: function() {
            updateStatsPill();

            // Sélecteur de variante dans le menu (3x3 classique ou 5 à aligner)
            const syncPreferredRule = (rule, isUserAction = false) => {
                const btn3 = document.getElementById('btn-rule-3');
                const btn5 = document.getElementById('btn-rule-5');
                if (btn3 && btn5) {
                    if (rule === '5') {
                        btn5.classList.add('active');
                        btn3.classList.remove('active');
                    } else {
                        btn3.classList.add('active');
                        btn5.classList.remove('active');
                    }
                }
                const targetSize = rule === '5' ? '10' : '3';
                ['local-grid-size', 'bot-grid-size', 'wifi-grid-size', 'tournament-grid-select', 'arena-grid-size'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.value = targetSize;
                });
                if (isUserAction) {
                    SoundEffects.click();
                    localStorage.setItem('mg_preferred_rule', rule);
                }
            };

            const savedRule = localStorage.getItem('mg_preferred_rule') || '3';
            syncPreferredRule(savedRule, false);

            document.getElementById('btn-rule-3')?.addEventListener('click', () => syncPreferredRule('3', true));
            document.getElementById('btn-rule-5')?.addEventListener('click', () => syncPreferredRule('5', true));

            // Match Local : ouvre modale de configuration
            document.getElementById('btn-local-quick')?.addEventListener('click', () => {
                SoundEffects.click();
                document.getElementById('local-setup-modal').style.display = 'flex';
            });

            // Tournoi Local
            document.getElementById('btn-local-tournament')?.addEventListener('click', () => {
                SoundEffects.click();
                document.getElementById('tournament-modal').style.display = 'flex';
            });

            // Lancement Match Local après config
            document.getElementById('btn-confirm-local')?.addEventListener('click', () => {
                const p1 = document.getElementById('local-p1-name')?.value || 'Joueur 1';
                const p2 = document.getElementById('local-p2-name')?.value || 'Joueur 2';
                const size = parseInt(document.getElementById('local-grid-size')?.value || '3', 10);
                MorpionGame.startLocalMatch(size, p1, p2);
            });

            // Clic sur boutons de Bot : ouvre la modale de réglage du bot
            document.querySelectorAll('.btn-bot-level').forEach(btn => {
                btn.addEventListener('click', () => {
                    SoundEffects.click();
                    const level = btn.dataset.level;
                    document.getElementById('bot-level-selected').value = level;
                    const levelTitles = {
                        'novice': '🐣 Novice',
                        'medium': '🤖 Intermédiaire',
                        'advanced': '🧠 Avancé',
                        'legend': '👑 Légende',
                        'adaptive': '🧬 Adaptatif'
                    };
                    document.getElementById('bot-modal-level-name').textContent = levelTitles[level] || level;
                    document.getElementById('bot-setup-modal').style.display = 'flex';
                });
            });

            // Confirmation lancement Bot
            document.getElementById('btn-confirm-bot')?.addEventListener('click', () => {
                const level = document.getElementById('bot-level-selected')?.value || 'medium';
                const size = parseInt(document.getElementById('bot-grid-size')?.value || '3', 10);
                const starter = document.getElementById('bot-starter-select')?.value || 'player';
                const pName = document.getElementById('bot-player-name')?.value || 'Vous';
                MorpionGame.startBotMatch(level, size, starter, pName);
            });

            // Wi-Fi Hôte : ouvre modale de config Wi-Fi
            document.getElementById('btn-wifi-host')?.addEventListener('click', () => {
                SoundEffects.click();
                document.getElementById('wifi-create-modal').style.display = 'flex';
            });

            // Confirmation création Wi-Fi
            document.getElementById('btn-confirm-create-wifi')?.addEventListener('click', () => {
                const size = parseInt(document.getElementById('wifi-grid-size')?.value || '3', 10);
                const targetWins = parseInt(document.getElementById('wifi-target-wins')?.value || '1', 10);
                MorpionGame.startWifiHost(size, targetWins);
            });

            // Wi-Fi Rejoindre
            document.getElementById('btn-wifi-join')?.addEventListener('click', () => {
                SoundEffects.click();
                document.getElementById('wifi-join-modal').style.display = 'flex';
            });

            // Quitter la partie
            document.getElementById('btn-back-menu')?.addEventListener('click', () => {
                SoundEffects.click();
                requestGameAction('menu');
            });

            // Rejouer
            document.getElementById('btn-rematch')?.addEventListener('click', () => {
                SoundEffects.click();
                requestGameAction('next_round');
            });
            document.querySelectorAll('[data-game-action]').forEach(button => {
                button.addEventListener('click', () => requestGameAction(button.dataset.gameAction));
            });
            document.getElementById('arena-grid-size')?.addEventListener('change', event => {
                const size = Number.parseInt(event.target.value, 10);
                if ([3, 5, 10, 12, 15, 19].includes(size)) requestGameAction('change_grid', size);
            });
            document.getElementById('action-request-accept')?.addEventListener('click', () => {
                if (mode === 'wifi') {
                    respondWifiAction(true);
                } else if (pendingGameAction) {
                    performLocalGameAction(pendingGameAction.action, pendingGameAction.size);
                }
            });
            document.getElementById('action-request-decline')?.addEventListener('click', () => {
                if (mode === 'wifi') {
                    respondWifiAction(false);
                } else {
                    pendingGameAction = null;
                    closeActionModals();
                    renderBoard();
                }
            });
            document.getElementById('action-request-close')?.addEventListener('click', cancelWifiAction);

            const symbolXSelect = document.getElementById('symbol-x-select');
            const symbolOSelect = document.getElementById('symbol-o-select');
            const arenaSymbolXSelect = document.getElementById('arena-symbol-x-select');
            const arenaSymbolOSelect = document.getElementById('arena-symbol-o-select');
            if (symbolXSelect) symbolXSelect.value = playerSymbols.X;
            if (symbolOSelect) symbolOSelect.value = playerSymbols.O;
            if (arenaSymbolXSelect) arenaSymbolXSelect.value = playerSymbols.X;
            if (arenaSymbolOSelect) arenaSymbolOSelect.value = playerSymbols.O;
            const changeSymbol = (player, value) => {
                playerSymbols[player] = value;
                const settingsSelect = document.getElementById(player === 'X' ? 'symbol-x-select' : 'symbol-o-select');
                const arenaSelect = document.getElementById(player === 'X' ? 'arena-symbol-x-select' : 'arena-symbol-o-select');
                if (settingsSelect) settingsSelect.value = value;
                if (arenaSelect) arenaSelect.value = value;
                localStorage.setItem(player === 'X' ? 'mg_symbol_x' : 'mg_symbol_o', value);
                renderBoard();
                updateScoreboard();
            };
            symbolXSelect?.addEventListener('change', () => {
                changeSymbol('X', symbolXSelect.value);
            });
            symbolOSelect?.addEventListener('change', () => {
                changeSymbol('O', symbolOSelect.value);
            });
            arenaSymbolXSelect?.addEventListener('change', () => {
                changeSymbol('X', arenaSymbolXSelect.value);
            });
            arenaSymbolOSelect?.addEventListener('change', () => {
                changeSymbol('O', arenaSymbolOSelect.value);
            });
            function toggleBoardSize() {
                const container = document.getElementById('board-container');
                if (!container) return;
                const isXl = container.classList.toggle('board-xl');
                localStorage.setItem('mg_board_xl', isXl ? 'true' : 'false');
                const sizeBtn = document.getElementById('btn-toggle-size');
                if (sizeBtn) {
                    sizeBtn.innerHTML = isXl ? '<span>🔍 Normal</span>' : '<span>🔍 Grand</span>';
                }
            }

            // Bouton Agrandir les cases XL
            document.getElementById('btn-toggle-size')?.addEventListener('click', () => {
                SoundEffects.click();
                toggleBoardSize();
            });
            if (localStorage.getItem('mg_board_xl') === 'true') {
                document.getElementById('board-container')?.classList.add('board-xl');
                const sizeBtn = document.getElementById('btn-toggle-size');
                if (sizeBtn) sizeBtn.innerHTML = '<span>🔍 Normal</span>';
            }

            // Copie de lien Wi-Fi
            document.getElementById('btn-copy-wifi-link')?.addEventListener('click', () => {
                const linkInput = document.getElementById('wifi-share-link');
                if (linkInput) {
                    navigator.clipboard.writeText(linkInput.value);
                    const btn = document.getElementById('btn-copy-wifi-link');
                    btn.textContent = 'Copié ! ✅';
                    setTimeout(() => btn.textContent = 'Copier', 2000);
                }
            });

            // Bouton Partager dans la topbar
            document.getElementById('btn-share-trigger')?.addEventListener('click', () => {
                if (mode === 'wifi' && wifiRoomId) {
                    const baseUrl = window.LAN_BASE_URL || (window.location.origin + window.location.pathname);
                    navigator.clipboard.writeText(baseUrl + '?room=' + wifiRoomId);
                    alert(`Lien du salon copié dans le presse-papier !\n${baseUrl}?room=${wifiRoomId}`);
                } else {
                    document.getElementById('wifi-create-modal').style.display = 'flex';
                }
            });

            // ==========================================
            // MODE CLASSÉ (RANKED)
            // ==========================================
            document.getElementById('rank-chip')?.addEventListener('click', (event) => {
                if (!rankState().authenticated) return; // le lien mène à la page de connexion
                event.preventDefault();
                refreshRankedProfile();
                const modal = document.getElementById('stats-modal');
                if (modal) modal.style.display = 'flex';
            });

            document.getElementById('btn-ranked-guide')?.addEventListener('click', () => {
                const modal = document.getElementById('ranked-guide-modal');
                if (modal) modal.style.display = 'flex';
            });

            document.getElementById('btn-ranked-start')?.addEventListener('click', () => {
                if (!rankState().authenticated) {
                    window.location.assign('../../account.php');
                    return;
                }
                paintRankChip(rankState().authenticated ? rankState() : null);
                refreshRankedProfile();
                const modal = document.getElementById('ranked-setup-modal');
                if (modal) modal.style.display = 'flex';
            });

            const rankedOpponent = document.getElementById('ranked-opponent');
            const syncRankedGroups = () => {
                const value = rankedOpponent ? rankedOpponent.value : 'bot';
                const botGroup = document.getElementById('ranked-bot-level-group');
                const localGroup = document.getElementById('ranked-local-group');
                const starterGroup = document.getElementById('ranked-starter-group');
                const note = document.getElementById('ranked-modal-note');
                if (botGroup) botGroup.hidden = value !== 'bot';
                if (localGroup) localGroup.hidden = value !== 'local';
                if (starterGroup) starterGroup.hidden = value === 'wifi';
                if (note) {
                    note.textContent = value === 'wifi'
                        ? 'Un salon classé est ouvert : chaque joueur connecté gagne ou perd des points.'
                        : 'Les points sont calculés par le serveur à la fin de la manche.';
                }
            };
            rankedOpponent?.addEventListener('change', syncRankedGroups);
            syncRankedGroups();

            document.getElementById('btn-confirm-ranked')?.addEventListener('click', () => {
                const opponent = rankedOpponent ? rankedOpponent.value : 'bot';
                const size = Number((document.getElementById('ranked-grid-size') || {}).value) || 3;
                const starter = (document.getElementById('ranked-starter') || {}).value || 'player';
                const level = (document.getElementById('ranked-bot-level') || {}).value || 'medium';
                const name = (document.getElementById('ranked-local-name') || {}).value || 'Joueur 2';
                closeAllModals();
                MorpionGame.startRankedMatch(opponent, {
                    level: level,
                    size: size,
                    starter: starter === 'opponent' ? 'bot' : starter,
                    name: name
                });
            });

            document.getElementById('reward-close')?.addEventListener('click', () => {
                const modal = document.getElementById('reward-modal');
                if (modal) modal.style.display = 'none';
            });
            document.getElementById('reward-next-round')?.addEventListener('click', () => {
                const modal = document.getElementById('reward-modal');
                if (modal) modal.style.display = 'none';
                MorpionGame.rematch();
            });

            // Le profil classé affiché provient du serveur (source de vérité).
            refreshRankedProfile();

            // Fermeture des modales
            document.querySelectorAll('.modal-close-btn').forEach(btn => {
                btn.addEventListener('click', closeAllModals);
            });

            // Confirmation rejoindre Wi-Fi
            document.getElementById('btn-confirm-join')?.addEventListener('click', () => {
                const code = document.getElementById('join-room-code')?.value;
                const name = document.getElementById('join-player-name')?.value;
                if (!code) {
                    alert('Veuillez entrer un code de salon');
                    return;
                }
                MorpionGame.joinWifiRoom(code, name || 'Joueur 2');
            });

            // Récupération automatique si paramètre ?room=XXXX dans l'URL
            const urlParams = new URLSearchParams(window.location.search);
            const autoRoom = urlParams.get('room');
            if (autoRoom) {
                document.getElementById('join-room-code').value = autoRoom;
                document.getElementById('wifi-join-modal').style.display = 'flex';
            } else {
                checkSessionRecovery();
            }

            // Prévenir l'adversaire lors de la fermeture de fenêtre
            window.addEventListener('beforeunload', () => {
                if (mode === 'wifi' && wifiRoomId && wifiPlayerId) {
                    const blob = new Blob([JSON.stringify({
                        action: 'leave_room',
                        room_id: wifiRoomId,
                        player_id: wifiPlayerId
                    })], { type: 'application/json' });
                    navigator.sendBeacon('api.php', blob);
                }
            });
        }
    };
})();

document.addEventListener('DOMContentLoaded', () => {
    MorpionGame.initEvents();
    const requestedSize = Number(window.SPACE_CORE_GRID_SIZE);
    if ([3, 5, 10, 12, 15, 19].includes(requestedSize)) {
        MorpionGame.startBotMatch('medium', requestedSize, 'player', window.MG_PLAYER_NAME || 'Vous');
    }
});
