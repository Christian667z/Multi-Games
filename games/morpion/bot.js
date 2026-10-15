/**
 * Multi-Games - Intelligence Artificielle Morpion & Gomoku (3x3 et 10x10)
 * Niveaux : Novice, Intermédiaire, Avancé, Légende, Évolutif
 */
const MorpionBot = (function() {
    let adaptiveStreak = 0; // Victoires joueur = +, Défaites joueur = -
    let learnedMistakes = [];

    // Directions pour Gomoku (horizontal, vertical, diag \, diag /)
    const DIRECTIONS = [
        [0, 1],   // horizontal
        [1, 0],   // vertical
        [1, 1],   // diag \
        [1, -1]   // diag /
    ];

    function checkWinner(board, size = 3, winLen = 3) {
        // Lignes
        for (let r = 0; r < size; r++) {
            for (let c = 0; c <= size - winLen; c++) {
                const sym = board[r * size + c];
                if (!sym) continue;
                let match = true;
                for (let k = 1; k < winLen; k++) {
                    if (board[r * size + c + k] !== sym) { match = false; break; }
                }
                if (match) return sym;
            }
        }
        // Colonnes
        for (let c = 0; c < size; c++) {
            for (let r = 0; r <= size - winLen; r++) {
                const sym = board[r * size + c];
                if (!sym) continue;
                let match = true;
                for (let k = 1; k < winLen; k++) {
                    if (board[(r + k) * size + c] !== sym) { match = false; break; }
                }
                if (match) return sym;
            }
        }
        // Diagonale \
        for (let r = 0; r <= size - winLen; r++) {
            for (let c = 0; c <= size - winLen; c++) {
                const sym = board[r * size + c];
                if (!sym) continue;
                let match = true;
                for (let k = 1; k < winLen; k++) {
                    if (board[(r + k) * size + (c + k)] !== sym) { match = false; break; }
                }
                if (match) return sym;
            }
        }
        // Diagonale /
        for (let r = 0; r <= size - winLen; r++) {
            for (let c = winLen - 1; c < size; c++) {
                const sym = board[r * size + c];
                if (!sym) continue;
                let match = true;
                for (let k = 1; k < winLen; k++) {
                    if (board[(r + k) * size + (c - k)] !== sym) { match = false; break; }
                }
                if (match) return sym;
            }
        }

        if (board.every(cell => cell !== null && cell !== '')) return 'TIE';
        return null;
    }

    function getEmptyIndices(board) {
        const empties = [];
        for (let i = 0; i < board.length; i++) {
            if (!board[i]) empties.push(i);
        }
        return empties;
    }

    // Récupérer les cases voisines de pièces déjà posées (optimisation 10x10)
    function getRelevantEmpties(board, size = 10, distance = 2) {
        const relevant = new Set();
        let hasAnyPiece = false;

        for (let r = 0; r < size; r++) {
            for (let c = 0; c < size; c++) {
                if (board[r * size + c]) {
                    hasAnyPiece = true;
                    for (let dr = -distance; dr <= distance; dr++) {
                        for (let dc = -distance; dc <= distance; dc++) {
                            const nr = r + dr;
                            const nc = c + dc;
                            if (nr >= 0 && nr < size && nc >= 0 && nc < size) {
                                const idx = nr * size + nc;
                                if (!board[idx]) relevant.add(idx);
                            }
                        }
                    }
                }
            }
        }

        if (!hasAnyPiece) {
            // Plateau vide : jouer vers le centre
            const center = Math.floor(size / 2);
            return [center * size + center];
        }

        return Array.from(relevant);
    }

    // Évaluation heuristique tactique Gomoku (5 à aligner)
    function evaluateGomokuMove(board, index, sym, oppSym, size = 10) {
        let score = 0;
        const r = Math.floor(index / size);
        const c = index % size;

        // Proximité du centre
        const centerDist = Math.abs(r - size / 2) + Math.abs(c - size / 2);
        score += Math.max(0, 10 - centerDist);

        for (let [dr, dc] of DIRECTIONS) {
            // Analyse dans la direction
            let countSym = 1;
            let openEnds = 0;

            // Vers l'avant
            let step = 1;
            while (step <= 4) {
                const nr = r + dr * step;
                const nc = c + dc * step;
                if (nr < 0 || nr >= size || nc < 0 || nc >= size) break;
                const val = board[nr * size + nc];
                if (val === sym) {
                    countSym++;
                    step++;
                } else if (val === null) {
                    openEnds++;
                    break;
                } else {
                    break;
                }
            }

            // Vers l'arrière
            step = 1;
            while (step <= 4) {
                const nr = r - dr * step;
                const nc = c - dc * step;
                if (nr < 0 || nr >= size || nc < 0 || nc >= size) break;
                const val = board[nr * size + nc];
                if (val === sym) {
                    countSym++;
                    step++;
                } else if (val === null) {
                    openEnds++;
                    break;
                } else {
                    break;
                }
            }

            // Pondération offensive
            if (countSym >= 5) score += 100000;
            else if (countSym === 4 && openEnds >= 1) score += 12000;
            else if (countSym === 3 && openEnds === 2) score += 2500;
            else if (countSym === 3 && openEnds === 1) score += 600;
            else if (countSym === 2 && openEnds === 2) score += 200;
            else if (countSym === 2 && openEnds === 1) score += 50;

            // Analyse défensive (bloquer l'adversaire sur cette ligne)
            let countOpp = 1;
            let openOppEnds = 0;

            step = 1;
            while (step <= 4) {
                const nr = r + dr * step;
                const nc = c + dc * step;
                if (nr < 0 || nr >= size || nc < 0 || nc >= size) break;
                const val = board[nr * size + nc];
                if (val === oppSym) {
                    countOpp++;
                    step++;
                } else if (val === null) {
                    openOppEnds++;
                    break;
                } else {
                    break;
                }
            }

            step = 1;
            while (step <= 4) {
                const nr = r - dr * step;
                const nc = c - dc * step;
                if (nr < 0 || nr >= size || nc < 0 || nc >= size) break;
                const val = board[nr * size + nc];
                if (val === oppSym) {
                    countOpp++;
                    step++;
                } else if (val === null) {
                    openOppEnds++;
                    break;
                } else {
                    break;
                }
            }

            // Pondération défensive
            if (countOpp >= 5) score += 80000;
            else if (countOpp === 4 && openOppEnds >= 1) score += 10000;
            else if (countOpp === 3 && openOppEnds === 2) score += 2000;
            else if (countOpp === 3 && openOppEnds === 1) score += 400;
            else if (countOpp === 2 && openOppEnds === 2) score += 150;
        }

        return score;
    }

    // Minimax optimal pour grille 3x3
    function minimax(board, depth, isMaximizing, botSym, playerSym) {
        const winner = checkWinner(board, 3, 3);
        if (winner === botSym) return 10 - depth;
        if (winner === playerSym) return depth - 10;
        if (winner === 'TIE') return 0;

        const empties = getEmptyIndices(board);

        if (isMaximizing) {
            let maxEval = -Infinity;
            for (let idx of empties) {
                board[idx] = botSym;
                let ev = minimax(board, depth + 1, false, botSym, playerSym);
                board[idx] = null;
                maxEval = Math.max(maxEval, ev);
            }
            return maxEval;
        } else {
            let minEval = Infinity;
            for (let idx of empties) {
                board[idx] = playerSym;
                let ev = minimax(board, depth + 1, true, botSym, playerSym);
                board[idx] = null;
                minEval = Math.min(minEval, ev);
            }
            return minEval;
        }
    }

    // 1. Coup Novice : aléatoire, limité aux cases proches sur les grandes grilles.
    function getNoviceMove(board, size = 3) {
        const empties = size > 3 ? getRelevantEmpties(board, size, 1) : getEmptyIndices(board);
        if (empties.length === 0) return null;
        return empties[Math.floor(Math.random() * empties.length)];
    }

    // 2. Coup Intermédiaire
    function getIntermediateMove(board, botSym, playerSym, size = 3, winLen = 3) {
        const empties = size > 3 ? getRelevantEmpties(board, size, 2) : getEmptyIndices(board);
        if (empties.length === 0) return null;

        // Victoire immédiate
        for (let idx of empties) {
            board[idx] = botSym;
            if (checkWinner(board, size, winLen) === botSym) {
                board[idx] = null;
                return idx;
            }
            board[idx] = null;
        }

        // Bloquer joueur
        for (let idx of empties) {
            board[idx] = playerSym;
            if (checkWinner(board, size, winLen) === playerSym) {
                board[idx] = null;
                return idx;
            }
            board[idx] = null;
        }

        // Centre
        const center = Math.floor(size / 2) * size + Math.floor(size / 2);
        if (board[center] === null) return center;

        if (size > 3) {
            let bestMove = empties[0];
            let bestScore = -Infinity;
            for (const idx of empties) {
                const score = evaluateGomokuMove(board, idx, botSym, playerSym, size)
                    + evaluateGomokuMove(board, idx, playerSym, botSym, size) * 0.9;
                if (score > bestScore) {
                    bestScore = score;
                    bestMove = idx;
                }
            }
            return bestMove;
        }
        return empties[Math.floor(Math.random() * empties.length)];
    }

    // 3. Minimax sur 3x3, heuristique tactique bornée sur les grandes grilles.
    function getLegendMove(board, botSym, playerSym, size = 3, winLen = 3) {
        if (size > 3) {
            const candidates = getRelevantEmpties(board, size, 2);
            if (candidates.length === 0) return Math.floor(size * size / 2);

            // 1. Priorité absolue : Victoire immédiate du bot sur n'importe quelle case candidate
            for (const idx of candidates) {
                board[idx] = botSym;
                const winsNow = checkWinner(board, size, winLen) === botSym;
                board[idx] = null;
                if (winsNow) return idx;
            }

            // 2. Bloquer immédiatement toute victoire adverse
            for (const idx of candidates) {
                board[idx] = playerSym;
                const blocksWin = checkWinner(board, size, winLen) === playerSym;
                board[idx] = null;
                if (blocksWin) return idx;
            }

            let bestScore = -Infinity;
            let bestMove = candidates[0];

            for (let idx of candidates) {
                const score = evaluateGomokuMove(board, idx, botSym, playerSym, size)
                    + evaluateGomokuMove(board, idx, playerSym, botSym, size) * 1.1;
                if (score > bestScore) {
                    bestScore = score;
                    bestMove = idx;
                }
            }
            return bestMove;
        }

        // Grille 3x3 Minimax
        const empties = getEmptyIndices(board);
        if (empties.length === 0) return null;

        if (empties.length === 9) {
            const openers = [0, 2, 4, 6, 8];
            return openers[Math.floor(Math.random() * openers.length)];
        }

        let bestScore = -Infinity;
        let bestMove = empties[0];

        for (let idx of empties) {
            board[idx] = botSym;
            let score = minimax(board, 0, false, botSym, playerSym);
            board[idx] = null;
            if (score > bestScore) {
                bestScore = score;
                bestMove = idx;
            }
        }
        return bestMove;
    }

    // 4. Coup Avancé (Minimax / Évaluateur avec 15% d'imprécision humaine)
    function getAdvancedMove(board, botSym, playerSym, size = 3, winLen = 3) {
        if (Math.random() < 0.15) {
            return getIntermediateMove(board, botSym, playerSym, size, winLen);
        }
        return getLegendMove(board, botSym, playerSym, size, winLen);
    }

    // 5. Coup Évolutif
    function getAdaptiveMove(board, botSym, playerSym, size = 3, winLen = 3) {
        let move;
        if (adaptiveStreak >= 2) move = getLegendMove(board, botSym, playerSym, size, winLen);
        else if (adaptiveStreak === 1) move = getAdvancedMove(board, botSym, playerSym, size, winLen);
        else if (adaptiveStreak === 0) move = getIntermediateMove(board, botSym, playerSym, size, winLen);
        else move = getNoviceMove(board, size);

        const boardKey = board.map(cell => cell || '-').join('');
        const lesson = learnedMistakes.find(item =>
            item.board === boardKey
            && item.size === size
            && item.winLength === winLen
            && item.failures > 0
        );
        if (!lesson || move !== lesson.move) return move;

        const emptyCells = getEmptyIndices(board);
        const opponentThreats = emptyCells.filter(index => {
            const nextBoard = [...board];
            nextBoard[index] = playerSym;
            return checkWinner(nextBoard, size, winLen) === playerSym;
        });
        if (opponentThreats.length === 1 && opponentThreats[0] === move) return move;

        const alternatives = emptyCells.filter(index => index !== lesson.move);
        if (alternatives.length === 0) return move;

        let bestMove = alternatives[0];
        let bestScore = -Infinity;
        alternatives.forEach(index => {
            board[index] = botSym;
            const score = size === 3
                ? minimax(board, 0, false, botSym, playerSym)
                : evaluateGomokuMove(board, index, botSym, playerSym, size)
                    + evaluateGomokuMove(board, index, playerSym, botSym, size) * 1.1;
            board[index] = null;
            if (score > bestScore) {
                bestScore = score;
                bestMove = index;
            }
        });
        return bestMove;
    }

    return {
        setLearningProfile: function(profile) {
            adaptiveStreak = Number.isInteger(profile?.learningLevel)
                ? Math.max(-2, Math.min(3, profile.learningLevel))
                : 0;
            learnedMistakes = Array.isArray(profile?.mistakes)
                ? profile.mistakes.filter(item =>
                    item && typeof item.board === 'string'
                    && Number.isInteger(item.move)
                    && Number.isInteger(item.size)
                    && Number.isInteger(item.winLength)
                    && Number.isInteger(item.failures)
                )
                : [];
        },
        recordGameResult: function(playerWon, isTie) {
            if (isTie) return;
            if (playerWon) {
                adaptiveStreak = Math.min(3, adaptiveStreak + 1);
            } else {
                adaptiveStreak = Math.max(-2, adaptiveStreak - 1);
            }
        },
        resetAdaptive: function() {
            adaptiveStreak = 0;
        },
        normalizeLevel: function(level) {
            if (!level || typeof level !== 'string') {
                throw new Error(`Niveau de bot invalide : ${JSON.stringify(level)}`);
            }
            const cleaned = level
                .trim()
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '');

            if (cleaned === 'novice' || cleaned === 'facile' || cleaned === 'easy') {
                return 'novice';
            }
            if (cleaned === 'medium' || cleaned === 'intermediate' || cleaned === 'intermediaire' || cleaned === 'moyen') {
                return 'medium';
            }
            if (cleaned === 'advanced' || cleaned === 'avance' || cleaned === 'difficile' || cleaned === 'hard') {
                return 'advanced';
            }
            if (cleaned === 'legend' || cleaned === 'legende' || cleaned === 'expert' || cleaned === 'impossible') {
                return 'legend';
            }
            if (cleaned === 'adaptive' || cleaned === 'evolutif' || cleaned === 'adaptatif' || cleaned === 'learning') {
                return 'adaptive';
            }

            throw new Error(`Niveau de bot inconnu : "${level}". Niveaux autorisés : novice, intermediate (ou intermédiaire), advanced (ou avancé), legend (ou légende), adaptive (ou évolutif).`);
        },
        computeMove: function(level, board, botSym = 'O', playerSym = 'X', size = 3, winLen = 3) {
            const canonicalLevel = this.normalizeLevel(level);

            switch (canonicalLevel) {
                case 'novice':
                    return getNoviceMove(board, size);
                case 'medium':
                    return getIntermediateMove(board, botSym, playerSym, size, winLen);
                case 'advanced':
                    return getAdvancedMove(board, botSym, playerSym, size, winLen);
                case 'legend':
                    return getLegendMove(board, botSym, playerSym, size, winLen);
                case 'adaptive':
                    return getAdaptiveMove(board, botSym, playerSym, size, winLen);
                default:
                    throw new Error(`Niveau de bot non reconnu : "${canonicalLevel}"`);
            }
        }
    };
})();
