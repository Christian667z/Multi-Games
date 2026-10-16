/**
 * Multi-Games — tableau de bord administrateur (navigation par onglets).
 *
 * Principes directeurs :
 *  - le rendu initial vient du serveur (views/admin.php) et le même jeu de
 *    données est rechargé depuis #admin-bootstrap, donc aucun écran « Chargement… » ;
 *  - chaque section possède une empreinte : on ne retouche le DOM que si les
 *    données ont réellement changé (l'ancien script reconstruisait tout toutes
 *    les 15 s et cassait le focus) ;
 *  - les échecs réseau n'effacent jamais les données déjà affichées ;
 *  - l'actualisation automatique se met en pause onglet masqué et pendant une
 *    saisie dans une boîte de dialogue ;
 *  - les univers de jeu sont décrits par un registre de sections : ajouter un
 *    onglet (Puissance 4, Pong…) revient à publier un nouvel « univers ».
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------ */
    /* Utilitaires DOM / formatage                                         */
    /* ------------------------------------------------------------------ */

    const $ = (selector, root) => (root || document).querySelector(selector);
    const $$ = (selector, root) => Array.prototype.slice.call((root || document).querySelectorAll(selector));

    const escapeSelector = (value) => (window.CSS && typeof window.CSS.escape === 'function')
        ? window.CSS.escape(String(value))
        : String(value).replace(/["\\\]]/g, '\\$&');

    function el(tag, props, children) {
        const node = document.createElement(tag);
        if (props) {
            Object.keys(props).forEach((key) => {
                const value = props[key];
                if (value === undefined || value === null || value === false) return;
                if (key === 'class') node.className = value;
                else if (key === 'text') node.textContent = String(value);
                else if (key === 'dataset') Object.assign(node.dataset, value);
                else if (key === 'style') Object.assign(node.style, value);
                else if (key === 'on') Object.keys(value).forEach((name) => node.addEventListener(name, value[name]));
                else if (key === 'colSpan' || key === 'rowSpan') node[key] = value;
                else if (key === 'min' || key === 'max') node.setAttribute(key, String(value));
                else if (key in node && key !== 'list' && key !== 'form') node[key] = value;
                else node.setAttribute(key, value === true ? '' : String(value));
            });
        }
        if (children) {
            [].concat(children).forEach((child) => {
                if (child === null || child === undefined || child === false) return;
                node.append(child instanceof Node ? child : document.createTextNode(String(child)));
            });
        }
        return node;
    }

    const integerFormat = new Intl.NumberFormat('fr-FR');
    const formatInt = (value) => {
        const number = Number(value);
        return integerFormat.format(Number.isFinite(number) ? number : 0);
    };
    const formatPercent = (value) => formatInt(Math.round(Number(value) || 0)) + ' %';
    const formatDate = (ts) => (Number(ts) > 0 ? new Date(Number(ts) * 1000).toLocaleDateString('fr-FR') : '—');
    const formatDateTime = (ts) => (Number(ts) > 0 ? new Date(Number(ts) * 1000).toLocaleString('fr-FR') : '—');

    const ONLINE_WINDOW = 90;
    const MODE_LABELS = { local: 'Hors ligne', bot: 'Contre Space', wifi: 'Wi-Fi' };
    const SEVERITY_META = {
        info: { icon: 'ℹ️', label: 'Information' },
        success: { icon: '✅', label: 'Succès' },
        warning: { icon: '⚠️', label: 'Avertissement' },
        danger: { icon: '⛔', label: 'Critique' },
    };

    /* Paliers de rang : miroir de mg_rank_tiers() côté PHP, utilisé uniquement
       lorsque l'API ne fournit pas encore de libellé (présence temps réel). */
    const RANK_TIERS = [
        { key: 'bronze', name: 'Bronze', icon: '🥉', min: 0 },
        { key: 'silver', name: 'Argent', icon: '🥈', min: 500 },
        { key: 'gold', name: 'Or', icon: '🥇', min: 1000 },
        { key: 'platinum', name: 'Platine', icon: '🛡️', min: 2000 },
        { key: 'diamond', name: 'Diamant', icon: '💎', min: 3500 },
        { key: 'legend', name: 'Légende', icon: '👑', min: 5000 },
    ];

    function rankFromPoints(points) {
        const value = Math.max(0, Number(points) || 0);
        let current = RANK_TIERS[0];
        RANK_TIERS.forEach((tier) => {
            if (value >= tier.min) current = tier;
        });
        return current;
    }

    function rankBadge(source) {
        const points = Number(source && source.ranked_points) || 0;
        const fallback = rankFromPoints(points);
        const icon = (source && source.rank_icon) || fallback.icon;
        const label = (source && source.rank_label) || fallback.name;
        return icon + ' ' + label;
    }

    /* ------------------------------------------------------------------ */
    /* Horloge (corrigée par l'heure serveur)                              */
    /* ------------------------------------------------------------------ */

    let clockOffset = 0;
    const nowSeconds = () => Math.floor(Date.now() / 1000) + clockOffset;

    function relTime(timestamp) {
        const value = Number(timestamp) || 0;
        if (value <= 0) return '—';
        const diff = Math.max(0, nowSeconds() - value);
        if (diff < 10) return "à l'instant";
        if (diff < 60) return 'il y a ' + diff + ' s';
        if (diff < 3600) return 'il y a ' + Math.floor(diff / 60) + ' min';
        if (diff < 86400) return 'il y a ' + Math.floor(diff / 3600) + ' h';
        return 'il y a ' + Math.floor(diff / 86400) + ' j';
    }

    const isOnline = (player) => !Number(player.is_banned) && Number(player.last_seen_at) >= nowSeconds() - ONLINE_WINDOW;

    /* ------------------------------------------------------------------ */
    /* Amorçage : données rendues par le serveur                           */
    /* ------------------------------------------------------------------ */

    const state = {
        apiUrl: (typeof window.MG_ADMIN_API === 'string' && window.MG_ADMIN_API) || 'games/morpion/api.php',
        csrfToken: typeof window.MG_CSRF === 'string' ? window.MG_CSRF : '',
        summary: {},
        botLevels: [],
        popularModes: [],
        players: [],
        themes: [],
        symbols: [],
        rooms: [],
        spaceKnowledge: [],
        spaceUsage: [],
        topPlayer: null,
        rankDistribution: [],
        rankTiers: RANK_TIERS.map((tier) => ({ key: tier.key, icon: tier.icon, name: tier.name })),
        morpion: { games: 0, completed: 0, ranked: 0 },
        contextLabels: {},
        platform: {},
        settings: {},
        social: null,
        socialSearch: '',
        livePlayers: [],
        leaderboard: [],
        morpionLeaders: [],
        morpionLive: [],
        gridUsage: [],
        events: [],
        eventCategories: [],
        signatures: {},
        selection: new Set(),
        filters: {
            search: '',
            status: 'all',
            rank: 'all',
            sortKey: 'last_seen_at',
            sortDir: 'desc',
        },
        page: 1,
        pageSize: 25,
        refreshMs: 15000,
        nextRefreshAt: 0,
        lastRefresh: 0,
        inFlight: false,
        hydrateStarted: false,
    };

    (function readBootstrap() {
        const node = $('#admin-bootstrap');
        if (!node) return;
        let payload = null;
        try {
            payload = JSON.parse(node.textContent || '{}');
        } catch (error) {
            payload = null;
        }
        if (!payload || typeof payload !== 'object') return;
        if (typeof payload.api === 'string' && payload.api) state.apiUrl = payload.api;
        if (typeof payload.csrf === 'string' && payload.csrf) state.csrfToken = payload.csrf;
        if (Array.isArray(payload.spaceKnowledge)) state.spaceKnowledge = payload.spaceKnowledge;
        if (Array.isArray(payload.spaceUsage)) state.spaceUsage = payload.spaceUsage;
        if (typeof payload.serverTime === 'number') clockOffset = payload.serverTime - Math.floor(Date.now() / 1000);
        if (payload.summary && typeof payload.summary === 'object') state.summary = payload.summary;
        ['botLevels', 'popularModes', 'players', 'themes', 'symbols', 'rooms'].forEach((key) => {
            if (Array.isArray(payload[key])) state[key] = payload[key];
        });
        if (payload.topPlayer && typeof payload.topPlayer === 'object') state.topPlayer = payload.topPlayer;
        if (Array.isArray(payload.rankDistribution) && payload.rankDistribution.length) {
            state.rankDistribution = payload.rankDistribution;
        }
        if (Array.isArray(payload.rankTiers) && payload.rankTiers.length) state.rankTiers = payload.rankTiers;
        if (payload.morpion && typeof payload.morpion === 'object') {
            state.morpion = Object.assign({ games: 0, completed: 0, ranked: 0 }, payload.morpion);
        }
        if (payload.contextLabels && typeof payload.contextLabels === 'object') {
            state.contextLabels = payload.contextLabels;
        }
        // Le serveur ne fournit pas la présence temps réel : on la déduit de la
        // liste des joueurs déjà rendue, sans requête supplémentaire.
        state.livePlayers = state.players.filter(isOnline);
    }());

    /* ------------------------------------------------------------------ */
    /* Transport HTTP                                                      */
    /* ------------------------------------------------------------------ */

    async function readJson(response) {
        const raw = await response.text();
        let payload = null;
        if (raw) {
            try {
                payload = JSON.parse(raw);
            } catch (error) {
                payload = null;
            }
        }
        if (!response.ok || !payload || payload.success !== true) {
            const message = (payload && payload.error) || ('Requête impossible (HTTP ' + response.status + ').');
            const failure = new Error(message);
            failure.status = response.status;
            throw failure;
        }
        return payload;
    }

    async function requestJson(url, options, timeoutMs) {
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        const timer = controller ? window.setTimeout(() => controller.abort(), timeoutMs) : null;
        try {
            const response = await fetch(url, Object.assign({}, options, {
                cache: 'no-store',
                credentials: 'same-origin',
                signal: controller ? controller.signal : undefined,
            }));
            return await readJson(response);
        } catch (error) {
            if (error && error.name === 'AbortError') {
                throw new Error('Le serveur n’a pas répondu à temps. Réessayez.');
            }
            if (error instanceof TypeError) {
                throw new Error('Connexion au serveur impossible. Vérifiez le réseau puis réessayez.');
            }
            throw error;
        } finally {
            if (timer) window.clearTimeout(timer);
        }
    }

    const apiGet = (action, params) => {
        const query = new URLSearchParams(Object.assign({ action: action }, params || {}));
        return requestJson(
            state.apiUrl + '?' + query.toString(),
            { method: 'GET', headers: { Accept: 'application/json' } },
            15000
        );
    };

    const apiPost = (action, values) => requestJson(
        state.apiUrl,
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-Token': state.csrfToken,
            },
            body: JSON.stringify(Object.assign({ action: action }, values || {})),
        },
        20000
    );

    /* ------------------------------------------------------------------ */
    /* Notifications                                                       */
    /* ------------------------------------------------------------------ */

    const toastHost = $('#admin-toasts');
    const MAX_TOASTS = 4;

    function toast(message, kind) {
        if (!toastHost || !message) return;
        const node = el('div', { class: 'admin-toast is-' + (kind || 'info') }, [
            el('p', { text: message }),
            el('button', {
                type: 'button',
                'aria-label': 'Fermer la notification',
                text: '×',
                on: { click: () => node.remove() },
            }),
        ]);
        toastHost.append(node);
        while (toastHost.children.length > MAX_TOASTS) toastHost.firstElementChild.remove();
        window.setTimeout(() => node.remove(), kind === 'error' ? 9000 : 5000);
    }

    function showError(error) {
        const node = $('#admin-error');
        if (!node) return;
        node.textContent = error && error.message ? error.message : String(error);
        node.hidden = false;
    }

    function hideError() {
        const node = $('#admin-error');
        if (!node) return;
        node.hidden = true;
        node.textContent = '';
    }

    /* ------------------------------------------------------------------ */
    /* Boîte de dialogue (remplace prompt/confirm)                         */
    /* ------------------------------------------------------------------ */

    const dialogState = { resolve: null, settings: null, inputs: {} };
    const dialogNode = $('#admin-dialog');
    const dialogSupported = !!(dialogNode && typeof dialogNode.showModal === 'function');

    function dialogFinish(value) {
        const resolve = dialogState.resolve;
        if (!resolve) return;
        dialogState.resolve = null;
        dialogState.settings = null;
        dialogState.inputs = {};
        if (dialogNode && dialogNode.open) dialogNode.close();
        resolve(value);
    }

    (function initDialog() {
        if (!dialogSupported) return;
        const form = $('#admin-dialog-form');
        const cancelNode = $('#admin-dialog-cancel');
        if (!form || !cancelNode) return;

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (!dialogState.resolve) return;
            const settings = dialogState.settings;
            const values = {};
            let error = '';

            settings.fields.forEach((field) => {
                const input = dialogState.inputs[field.name];
                if (!input) return;
                const raw = String(input.value || '').trim();
                values[field.name] = raw;
                if (error) return;
                if (field.required && raw === '') {
                    error = field.label + ' est obligatoire.';
                } else if (field.minLength && raw.length < field.minLength) {
                    error = field.label + ' doit contenir au moins ' + field.minLength + ' caractères.';
                } else if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(raw)) {
                    error = 'Adresse e-mail invalide.';
                } else if (field.type === 'number' && raw !== '' && !/^-?\d+$/.test(raw)) {
                    error = field.label + ' doit être un nombre entier.';
                } else if (field.type === 'number' && raw !== '') {
                    const numeric = Number(raw);
                    if (field.min !== undefined && numeric < field.min) error = field.label + ' ne peut pas être inférieur à ' + field.min + '.';
                    else if (field.max !== undefined && numeric > field.max) error = field.label + ' ne peut pas dépasser ' + field.max + '.';
                }
            });

            if (!error && typeof settings.validate === 'function') {
                error = settings.validate(values) || '';
            }
            if (error) {
                const errorNode = $('#admin-dialog-error');
                if (errorNode) {
                    errorNode.textContent = error;
                    errorNode.hidden = false;
                }
                return;
            }
            dialogFinish(values);
        });

        cancelNode.addEventListener('click', () => dialogFinish(null));
        dialogNode.addEventListener('cancel', (event) => {
            event.preventDefault();
            dialogFinish(null);
        });
        dialogNode.addEventListener('close', () => dialogFinish(null));
    }());

    function openDialog(options) {
        const settings = Object.assign({
            title: 'Confirmation',
            message: '',
            fields: [],
            confirmLabel: 'Confirmer',
            danger: false,
            validate: null,
        }, options || {});

        if (!dialogSupported) return legacyDialog(settings);

        return new Promise((resolve) => {
            // Un seul dialogue à la fois : le précédent est annulé proprement.
            dialogFinish(null);

            const titleNode = $('#admin-dialog-title');
            const messageNode = $('#admin-dialog-message');
            const fieldsHost = $('#admin-dialog-fields');
            const errorNode = $('#admin-dialog-error');
            const submitNode = $('#admin-dialog-submit');

            if (titleNode) titleNode.textContent = settings.title;
            if (messageNode) {
                messageNode.textContent = settings.message;
                messageNode.hidden = !settings.message;
            }
            if (errorNode) {
                errorNode.hidden = true;
                errorNode.textContent = '';
            }
            if (submitNode) {
                submitNode.textContent = settings.confirmLabel;
                submitNode.classList.toggle('is-primary', !settings.danger);
                submitNode.classList.toggle('is-danger', !!settings.danger);
            }
            if (fieldsHost) fieldsHost.replaceChildren();

            dialogState.inputs = {};
            settings.fields.forEach((field) => {
                const isTextarea = field.type === 'textarea';
                const input = isTextarea
                    ? el('textarea', {
                        id: 'admin-dialog-field-' + field.name,
                        rows: 3,
                        maxLength: field.maxLength || null,
                        placeholder: field.placeholder || '',
                        spellcheck: 'false',
                    })
                    : el('input', {
                        id: 'admin-dialog-field-' + field.name,
                        type: field.type || 'text',
                        maxLength: field.maxLength || null,
                        placeholder: field.placeholder || '',
                        autocomplete: 'off',
                        spellcheck: 'false',
                    });
                if (field.min !== undefined) input.setAttribute('min', String(field.min));
                if (field.max !== undefined) input.setAttribute('max', String(field.max));
                input.value = field.value === undefined || field.value === null ? '' : String(field.value);
                dialogState.inputs[field.name] = input;
                if (fieldsHost) {
                    fieldsHost.append(el('label', { for: input.id }, [
                        el('span', { text: field.label }),
                        input,
                        field.hint ? el('span', { class: 'admin-cell-sub', text: field.hint }) : null,
                    ]));
                }
            });

            dialogState.resolve = resolve;
            dialogState.settings = settings;
            dialogNode.showModal();

            const focusTarget = settings.fields.length
                ? dialogState.inputs[settings.fields[0].name]
                : submitNode;
            window.setTimeout(() => {
                if (focusTarget && typeof focusTarget.focus === 'function') focusTarget.focus();
            }, 0);
        });
    }

    /** Repli pour les navigateurs sans <dialog>. */
    function legacyDialog(settings) {
        return new Promise((resolve) => {
            if (!settings.fields.length) {
                resolve(window.confirm(settings.title + '\n\n' + settings.message) ? {} : null);
                return;
            }
            const values = {};
            for (let index = 0; index < settings.fields.length; index += 1) {
                const field = settings.fields[index];
                const answer = window.prompt(field.label, field.value === undefined ? '' : String(field.value));
                if (answer === null) {
                    resolve(null);
                    return;
                }
                values[field.name] = answer.trim();
            }
            resolve(values);
        });
    }

    const isDialogOpen = () => dialogState.resolve !== null;

    /* ------------------------------------------------------------------ */
    /* État global de chargement                                           */
    /* ------------------------------------------------------------------ */

    let busyDepth = 0;

    function applyBusy() {
        const busy = busyDepth > 0;
        const button = $('#admin-refresh');
        if (button) {
            button.disabled = busy;
            button.classList.toggle('is-busy', busy);
        }
        const page = $('#admin-page');
        if (page) page.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    const pushBusy = () => { busyDepth += 1; applyBusy(); };
    const popBusy = () => { busyDepth = Math.max(0, busyDepth - 1); applyBusy(); };

    let liveMode = 'idle';

    function setLive(mode) {
        liveMode = mode;
        const node = $('#admin-live');
        if (node) node.dataset.state = mode;
        updateLiveLabel();
    }

    function updateLiveLabel() {
        const label = $('#admin-live-label');
        if (!label) return;
        if (liveMode === 'loading') {
            label.textContent = 'Actualisation…';
            return;
        }
        if (liveMode === 'error') {
            label.textContent = 'Dernière requête en échec';
            return;
        }
        const parts = [state.lastRefresh ? 'À jour ' + relTime(Math.floor(state.lastRefresh / 1000)) : 'En attente'];
        if (state.refreshMs) {
            const remaining = Math.max(0, Math.ceil((state.nextRefreshAt - Date.now()) / 1000));
            parts.push('auto ' + (state.refreshMs / 1000) + ' s · dans ' + remaining + ' s');
        } else {
            parts.push('auto désactivée');
        }
        label.textContent = parts.join(' · ');
    }

    /* ------------------------------------------------------------------ */
    /* Préservation du focus lors d'un nouveau rendu                       */
    /* ------------------------------------------------------------------ */

    function withFocusPreserved(mutate) {
        const active = document.activeElement;
        const key = active && active.dataset ? active.dataset.focusKey : null;
        mutate();
        if (!key) return;
        const restored = document.querySelector('[data-focus-key="' + escapeSelector(key) + '"]');
        if (restored && typeof restored.focus === 'function') restored.focus({ preventScroll: true });
    }

    /* ------------------------------------------------------------------ */
    /* Rendu par section (avec empreinte)                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Applique une section : on ne touche au DOM que si l'empreinte a changé.
     * `render` reçoit la nouvelle valeur pour éviter toute lecture d'un état
     * intermédiaire.
     */
    function applySection(name, value, render) {
        const signature = JSON.stringify(value);
        if (state.signatures[name] === signature) return false;
        state.signatures[name] = signature;
        state[name] = value;
        render(value);
        return true;
    }

    /**
     * Rendu de liste par index : réutilise et met à jour les lignes existantes
     * (aucun re-rendu inutile, focus et sélection préservés) puis retire le reste.
     */
    /** Une ligne « aucun résultat » ne doit jamais être réutilisée comme ligne de données. */
    function isEmptyRow(row) {
        if (!row || !row.dataset) return false;
        if (row.dataset.emptyMessage !== undefined) return true;
        return !!row.querySelector('td.admin-empty, th.admin-empty');
    }

    function renderRows(body, items, keyOf, build, update, emptyMessage, emptyColumns) {
        if (!body) return;
        const emptyRow = Array.prototype.find.call(body.children, (row) => isEmptyRow(row));
        if (!items.length) {
            if (!emptyRow || emptyRow.dataset.emptyMessage !== emptyMessage) {
                body.replaceChildren(el('tr', null, [
                    el('td', { colSpan: emptyColumns, class: 'admin-empty', text: emptyMessage }),
                ]));
                const created = body.querySelector('tr.admin-empty');
                if (created) {
                    created.dataset.emptyMessage = emptyMessage;
                    created.dataset.rowKey = '';
                }
            }
            return;
        }
        // La ligne « aucun résultat » éventuelle n'a rien à faire dans une liste peuplée.
        if (emptyRow) emptyRow.remove();

        const existing = new Map();
        Array.prototype.slice.call(body.children).forEach((row) => {
            if (row.dataset && row.dataset.rowKey !== undefined && row.dataset.rowKey !== '') {
                existing.set(row.dataset.rowKey, row);
            }
        });

        let reference = null;
        items.forEach((item) => {
            const key = String(keyOf(item));
            let row = existing.get(key);
            if (!row) {
                // Repli positionnel : une ligne rendue par le serveur sans clé
                // (ou une ligne créée entre-temps) est réutilisée telle quelle.
                row = Array.prototype.find.call(body.children, (candidate) => candidate.dataset
                    && candidate.dataset.rowKey === undefined && !isEmptyRow(candidate));
            }
            if (row) {
                existing.delete(key);
                if (update) withFocusPreserved(() => update(row, item));
            } else {
                row = build(item);
                if (reference) reference.after(row);
                else body.prepend(row);
            }
            // La clé est inscrite dans le DOM : la vue serveur peut la fournir
            // elle aussi, ce qui rend le premier rendu incrémental dès le départ.
            row.dataset.rowKey = key;
            reference = row;
        });

        // Toute ligne laissée par un rendu précédent (et non reprise) est retirée.
        Array.prototype.slice.call(body.children).forEach((child) => {
            if (child !== emptyRow && child.dataset && child.dataset.rowKey === undefined) child.remove();
        });

        existing.forEach((row) => row.remove());
    }

    function renderSummary() {
        const values = Object.assign({
            live_now: state.livePlayers.length,
            live_playing: Number(state.platform.morpion && state.platform.morpion.playing_now) || 0,
            live_hub: Number(state.platform.morpion && state.platform.morpion.in_hub) || 0,
            live_space: Number(state.platform.morpion && state.platform.morpion.in_space) || 0,
        }, state.summary);

        Object.keys(values).forEach((key) => {
            const target = document.querySelector('[data-stat="' + escapeSelector(key) + '"]');
            if (!target) return;
            const next = formatInt(values[key]);
            if (target.textContent !== next) target.textContent = next;
        });
    }

    function emptyRow(body, columns, message) {
        body.append(el('tr', null, [
            el('td', { colSpan: columns, class: 'admin-empty', text: message }),
        ]));
    }

    /* --- Plateforme : joueur n°1, répartition des rangs ---------------- */

    function renderTopPlayer() {
        const card = $('#top-player-card');
        if (!card) return;
        const player = state.topPlayer;
        if (!player) {
            if (!card.querySelector('#top-player-empty')) {
                card.replaceChildren(el('p', { class: 'admin-empty', id: 'top-player-empty', text: 'Aucun match classé joué pour le moment : le classement est encore ouvert.' }));
            }
            return;
        }

        const games = Number(player.ranked_games) || 0;
        const wins = Number(player.ranked_wins) || 0;
        const winRate = games > 0 ? Math.round((wins / games) * 100) : 0;
        card.dataset.playerId = String(player.id);

        const setText = (selector, value) => {
            const node = $(selector);
            if (node && node.textContent !== String(value)) node.textContent = String(value);
        };
        const setNode = (selector, node) => {
            const target = $(selector);
            if (target) target.replaceWith(node);
        };

        if (card.querySelector('#top-player-empty')) {
            card.replaceChildren();
            card.append(
                el('div', { class: 'admin-top-avatar', id: 'top-player-avatar', 'aria-hidden': 'true' }),
                el('div', null, [
                    el('h3', { class: 'admin-top-name', id: 'top-player-name' }),
                    el('p', { class: 'admin-top-meta' }, [
                        el('span', { class: 'admin-rank-cell', id: 'top-player-rank' }),
                        ' · Saison ',
                        el('span', { id: 'top-player-season', text: '—' }),
                    ]),
                    el('span', { class: 'admin-progress', role: 'presentation' }, [
                        el('span', { class: 'admin-progress-bar', id: 'top-player-rankbar' }),
                    ]),
                    el('span', { class: 'admin-cell-sub', text: 'Progression dans le palier' }),
                ]),
                el('dl', { class: 'admin-top-values' }, [
                    el('div', { class: 'admin-top-value' }, [el('dt', { text: 'Points classés' }), el('dd', { id: 'top-player-points' })]),
                    el('div', { class: 'admin-top-value' }, [el('dt', { text: 'Victoires' }), el('dd', { id: 'top-player-wins' })]),
                    el('div', { class: 'admin-top-value' }, [el('dt', { text: 'Taux de victoire' }), el('dd', { id: 'top-player-winrate' })]),
                    el('div', { class: 'admin-top-value' }, [el('dt', { text: 'Parties classées' }), el('dd', { id: 'top-player-games' })]),
                    el('div', { class: 'admin-top-value' }, [el('dt', { text: 'Niveau' }), el('dd', { id: 'top-player-level' })]),
                    el('div', { class: 'admin-top-value' }, [el('dt', { text: 'XP' }), el('dd', { id: 'top-player-xp' })]),
                ])
            );
        }

        const avatar = card.querySelector('#top-player-avatar');
        if (avatar) avatar.textContent = player.avatar || '🚀';
        setText('#top-player-name', player.display_name);
        setText('#top-player-rank', rankBadge(player));
        setText('#top-player-season', state.platform.season === undefined ? '—' : formatInt(state.platform.season));
        setText('#top-player-points', formatInt(player.ranked_points));
        setText('#top-player-wins', formatInt(wins));
        setText('#top-player-winrate', formatPercent(winRate));
        setText('#top-player-games', formatInt(games));
        setText('#top-player-level', formatInt(player.level));
        setText('#top-player-xp', formatInt(player.xp));
        const bar = card.querySelector('#top-player-rankbar');
        if (bar) {
            const progress = Math.max(0, Math.min(100, Number(player.rank_progress) || 0));
            bar.style.width = progress + '%';
        }
        void setNode;
    }

    function renderRankDistribution() {
        const body = $('#rank-distribution-rows');
        if (!body) return;
        const tiers = state.rankTiers.length ? state.rankTiers : RANK_TIERS;
        const counts = new Map();
        (state.rankDistribution || []).forEach((row) => counts.set(String(row.key), Number(row.players) || 0));
        if (!state.rankDistribution || !state.rankDistribution.length) {
            state.players.forEach((player) => {
                const key = String(player.rank || (player.rank_label || '').toLowerCase() || 'bronze');
                counts.set(key, (counts.get(key) || 0) + 1);
            });
        }
        const total = Array.from(counts.values()).reduce((sum, value) => sum + value, 0);

        withFocusPreserved(() => {
            renderRows(body, tiers, (tier) => tier.key, (tier) => {
                const players = counts.get(String(tier.key)) || 0;
                const share = total > 0 ? Math.round((players / total) * 100) : 0;
                return el('tr', { dataset: { rankKey: String(tier.key) } }, [
                    el('td', null, [el('span', { class: 'admin-rank-cell', text: tier.icon + ' ' + tier.name })]),
                    el('td', { text: formatInt(players) }),
                    el('td', null, [el('span', { class: 'admin-tag', text: formatPercent(share) })]),
                    el('td', null, [
                        el('span', { class: 'admin-progress', role: 'presentation' }, [
                            el('span', { class: 'admin-progress-bar', style: { width: share + '%' } }),
                        ]),
                    ]),
                ]);
            }, (row, tier) => {
                const players = counts.get(String(tier.key)) || 0;
                const share = total > 0 ? Math.round((players / total) * 100) : 0;
                const cells = row.cells;
                if (!cells.length) return;
                const nameNode = cells[0].querySelector('.admin-rank-cell');
                if (nameNode) nameNode.textContent = tier.icon + ' ' + tier.name;
                cells[1].textContent = formatInt(players);
                const tag = cells[2].querySelector('.admin-tag');
                if (tag) tag.textContent = formatPercent(share);
                const bar = cells[3].querySelector('.admin-progress-bar');
                if (bar) bar.style.width = share + '%';
            }, 'Aucune donnée de rang disponible.', 4);
        });
    }

    /* --- Présence temps réel ------------------------------------------ */

    function contextLabel(context) {
        const key = String(context || '');
        if (state.contextLabels[key]) return state.contextLabels[key];
        if (key === 'morpion') return 'Morpion';
        if (key === 'space') return 'Space Core';
        if (key === 'hub') return 'Hub Jeux';
        if (key === 'admin') return 'Administration';
        return key !== '' ? key : 'Inconnu';
    }

    function livePlayerItem(player) {
        return {
            id: Number(player.id),
            display_name: String(player.display_name || ''),
            avatar: String(player.avatar || '🚀'),
            context: String(player.last_context || ''),
            contextAt: Number(player.last_context_at) || 0,
            lastSeenAt: Number(player.last_seen_at) || 0,
            points: Number(player.ranked_points) || 0,
            level: Number(player.level) || 1,
            rankIcon: player.rank_icon || rankFromPoints(player.ranked_points).icon,
            rankLabel: player.rank_label || rankBadge(player),
        };
    }

    function buildLiveRow(item) {
        return el('tr', { dataset: { liveId: String(item.id) } }, [
            el('td', null, [
                el('span', { class: 'admin-live-name' }, [
                    el('span', { class: 'admin-live-avatar', 'aria-hidden': 'true', text: item.avatar }),
                    item.display_name,
                ]),
            ]),
            el('td', { text: contextLabel(item.context) }),
            el('td', null, [
                el('span', { class: 'admin-cell-main', text: liveActivity(item) }),
                el('span', { class: 'admin-cell-sub', text: 'vu ' + relTime(item.lastSeenAt) }),
            ]),
            el('td', null, [
                el('span', { class: 'admin-cell-main', text: 'Niv. ' + formatInt(item.level) }),
                el('span', { class: 'admin-cell-sub', text: formatInt(item.points) + ' pts · ' + item.rankIcon }),
            ]),
        ]);
    }

    function liveActivity(item) {
        if (!item.contextAt) return 'activité inconnue';
        return relTime(item.contextAt);
    }

    function updateLiveRow(row, item) {
        const cells = row.cells;
        if (cells.length < 4) return;
        const name = cells[0].querySelector('.admin-live-name');
        if (name) {
            const avatar = name.querySelector('.admin-live-avatar');
            if (avatar) avatar.textContent = item.avatar;
            name.lastChild.textContent = item.display_name;
        }
        cells[1].textContent = contextLabel(item.context);
        const main = cells[2].querySelector('.admin-cell-main');
        if (main) main.textContent = liveActivity(item);
        const sub = cells[2].querySelector('.admin-cell-sub');
        if (sub) sub.textContent = 'vu ' + relTime(item.lastSeenAt);
        const level = cells[3].querySelector('.admin-cell-main');
        if (level) level.textContent = 'Niv. ' + formatInt(item.level);
        const points = cells[3].querySelector('.admin-cell-sub');
        if (points) points.textContent = formatInt(item.points) + ' pts · ' + item.rankIcon;
    }

    function renderLivePlayers() {
        const body = $('#live-player-rows');
        if (!body) return;
        const items = state.livePlayers.map(livePlayerItem)
            .sort((left, right) => right.lastSeenAt - left.lastSeenAt);
        withFocusPreserved(() => {
            renderRows(body, items, (item) => item.id, buildLiveRow, updateLiveRow,
                'Aucun joueur actif sur les 90 dernières secondes.', 4);
        });
    }

    /* --- Événements ---------------------------------------------------- */

    function eventItem(event) {
        return {
            id: Number(event.id),
            createdAt: Number(event.created_at) || 0,
            category: String(event.category || 'système'),
            severity: String(event.severity || 'info'),
            actorName: String(event.actor_name || '').trim(),
            actorId: event.actor_id === null || event.actor_id === undefined ? null : Number(event.actor_id),
            message: String(event.message || ''),
        };
    }

    function severityMeta(severity) {
        return SEVERITY_META[severity] || SEVERITY_META.info;
    }

    function buildEventRow(item) {
        const meta = severityMeta(item.severity);
        return el('tr', { dataset: { eventId: String(item.id) } }, [
            el('td', null, [
                el('span', { class: 'admin-cell-main', text: formatDateTime(item.createdAt) }),
                el('span', { class: 'admin-event-time', text: relTime(item.createdAt) }),
            ]),
            el('td', null, [el('span', { class: 'admin-tag', text: item.category })]),
            el('td', null, [
                el('span', {
                    class: 'admin-severity',
                    dataset: { severity: item.severity },
                    title: meta.label,
                    'aria-label': meta.label,
                    text: meta.icon,
                }),
                ' ',
                meta.label,
            ]),
            el('td', null, [
                el('span', { class: 'admin-event-actor', text: item.actorName !== '' ? item.actorName : 'Système' }),
            ]),
            el('td', null, [el('span', { class: 'admin-cell-wrap', text: item.message })]),
        ]);
    }

    function updateEventRow(row, item) {
        const cells = row.cells;
        if (cells.length < 5) return;
        const main = cells[0].querySelector('.admin-cell-main');
        if (main) main.textContent = formatDateTime(item.createdAt);
        const time = cells[0].querySelector('.admin-event-time');
        if (time) time.textContent = relTime(item.createdAt);
        const tag = cells[1].querySelector('.admin-tag');
        if (tag) tag.textContent = item.category;
        const meta = severityMeta(item.severity);
        const severity = cells[2].querySelector('.admin-severity');
        if (severity) {
            severity.dataset.severity = item.severity;
            severity.title = meta.label;
            severity.setAttribute('aria-label', meta.label);
            severity.textContent = meta.icon;
        }
        const actor = cells[3].querySelector('.admin-event-actor');
        if (actor) actor.textContent = item.actorName !== '' ? item.actorName : 'Système';
        const message = cells[4].querySelector('.admin-cell-wrap');
        if (message) message.textContent = item.message;
    }

    function renderEvents() {
        const body = $('#event-rows');
        if (!body) return;
        const items = state.events.map(eventItem);
        withFocusPreserved(() => {
            renderRows(body, items, (item) => item.id, buildEventRow, updateEventRow,
                'Aucun événement enregistré pour le moment.', 5);
        });
        const countNode = $('#event-count');
        if (countNode) {
            countNode.textContent = items.length
                ? formatInt(items.length) + ' événement(s) affiché(s)'
                : 'Journal vide';
        }
    }

    function renderEventCategories() {
        const select = $('#event-category');
        if (!select) return;
        const current = select.value || 'all';
        withFocusPreserved(() => {
            select.replaceChildren(el('option', { value: 'all', text: 'Toutes les catégories' }));
            state.eventCategories.forEach((row) => {
                const category = String(row.category || '');
                if (category === '') return;
                select.append(el('option', {
                    value: category,
                    text: category + ' (' + formatInt(row.events) + ')',
                }));
            });
            select.value = Array.prototype.some.call(select.options, (option) => option.value === current) ? current : 'all';
        });
    }

    /* --- Bot, modes, grilles, classement, salons ----------------------- */

    function renderBotLevels() {
        const body = $('#bot-level-rows');
        if (!body) return;
        const levels = state.botLevels;
        withFocusPreserved(() => {
            body.replaceChildren();
            if (!levels.length) {
                emptyRow(body, 6, 'Aucune partie contre le bot pour le moment.');
                return;
            }
            levels.forEach((level) => {
                const games = Number(level.games) || 0;
                const rate = games > 0 ? Math.round((Number(level.bot_wins) / games) * 100) : 0;
                body.append(el('tr', null, [
                    el('td', { text: level.bot_level || 'non précisé' }),
                    el('td', { text: formatInt(games) }),
                    el('td', { text: formatInt(level.bot_wins) }),
                    el('td', { text: formatInt(level.player_wins) }),
                    el('td', { text: formatInt(level.ties) }),
                    el('td', null, [el('span', { class: 'admin-tag', text: formatPercent(rate) })]),
                ]));
            });
        });
    }

    function renderPopularModes() {
        const body = $('#popular-mode-rows');
        if (!body) return;
        const modes = state.popularModes;
        const total = modes.reduce((sum, mode) => sum + (Number(mode.games) || 0), 0);
        withFocusPreserved(() => {
            body.replaceChildren();
            if (!modes.length) {
                emptyRow(body, 3, 'Aucune partie enregistrée.');
                return;
            }
            modes.forEach((mode) => {
                const games = Number(mode.games) || 0;
                const share = total > 0 ? Math.round((games / total) * 100) : 0;
                body.append(el('tr', null, [
                    el('td', { text: MODE_LABELS[mode.mode] || mode.mode }),
                    el('td', { text: formatInt(games) }),
                    el('td', null, [el('span', { class: 'admin-tag', text: formatPercent(share) })]),
                ]));
            });
        });
    }

    function renderGridUsage() {
        const body = $('#grid-usage-rows');
        if (!body) return;
        const usage = state.gridUsage.slice().sort((left, right) => (Number(right.games) || 0) - (Number(left.games) || 0));
        const total = usage.reduce((sum, row) => sum + (Number(row.games) || 0), 0);
        withFocusPreserved(() => {
            body.replaceChildren();
            if (!usage.length) {
                emptyRow(body, 3, 'Aucune donnée de grille pour le moment.');
                return;
            }
            usage.forEach((row) => {
                const size = Number(row.board_size) || 0;
                const games = Number(row.games) || 0;
                const share = total > 0 ? Math.round((games / total) * 100) : 0;
                body.append(el('tr', null, [
                    el('td', { text: size + ' × ' + size }),
                    el('td', { text: formatInt(games) }),
                    el('td', null, [el('span', { class: 'admin-tag', text: formatPercent(share) })]),
                ]));
            });
        });
    }

    function leaderboardItem(entry, index) {
        const games = Number(entry.ranked_games) || 0;
        const wins = Number(entry.ranked_wins) || 0;
        return {
            rank: Number(entry.rank) || index + 1,
            id: Number(entry.id),
            display_name: String(entry.display_name || ''),
            avatar: String(entry.avatar || '🚀'),
            ranked_points: Number(entry.ranked_points) || 0,
            rank_icon: entry.rank_icon || rankFromPoints(entry.ranked_points).icon,
            rank_label: entry.rank_label || rankBadge(entry),
            ranked_wins: wins,
            ranked_losses: Number(entry.ranked_losses) || 0,
            ranked_ties: Number(entry.ranked_ties) || 0,
            ranked_games: games,
            win_rate: Number(entry.ranked_win_rate) || (games > 0 ? Math.round((wins / games) * 100) : 0),
            level: Number(entry.level) || 1,
        };
    }

    function buildLeaderboardRow(item) {
        const rankCell = el('td', null, [el('strong', { text: '#' + formatInt(item.rank) })]);
        if (item.rank === 1) rankCell.append(' 🥇');
        else if (item.rank === 2) rankCell.append(' 🥈');
        else if (item.rank === 3) rankCell.append(' 🥉');
        return el('tr', { dataset: { leaderId: String(item.id) } }, [
            rankCell,
            el('td', null, [
                el('span', { class: 'admin-live-name' }, [
                    el('span', { class: 'admin-live-avatar', 'aria-hidden': 'true', text: item.avatar }),
                    item.display_name,
                ]),
            ]),
            el('td', null, [el('span', { class: 'admin-rank-cell', text: item.rank_icon + ' ' + item.rank_label })]),
            el('td', { text: formatInt(item.ranked_points) }),
            el('td', { text: formatInt(item.ranked_wins) + ' / ' + formatInt(item.ranked_losses) + ' / ' + formatInt(item.ranked_ties) }),
            el('td', { text: formatPercent(item.win_rate) }),
            el('td', null, [
                el('span', { class: 'admin-cell-main', text: 'Niv. ' + formatInt(item.level) }),
                el('span', { class: 'admin-cell-sub', text: formatInt(item.ranked_games) + ' classée(s)' }),
            ]),
        ]);
    }

    function updateLeaderboardRow(row, item) {
        const cells = row.cells;
        if (cells.length < 7) return;
        cells[0].replaceChildren(el('strong', { text: '#' + formatInt(item.rank) }));
        if (item.rank === 1) cells[0].append(' 🥇');
        else if (item.rank === 2) cells[0].append(' 🥈');
        else if (item.rank === 3) cells[0].append(' 🥉');
        const name = cells[1].querySelector('.admin-live-name');
        if (name) {
            const avatar = name.querySelector('.admin-live-avatar');
            if (avatar) avatar.textContent = item.avatar;
            name.lastChild.textContent = item.display_name;
        }
        const rank = cells[2].querySelector('.admin-rank-cell');
        if (rank) rank.textContent = item.rank_icon + ' ' + item.rank_label;
        cells[3].textContent = formatInt(item.ranked_points);
        cells[4].textContent = formatInt(item.ranked_wins) + ' / ' + formatInt(item.ranked_losses) + ' / ' + formatInt(item.ranked_ties);
        cells[5].textContent = formatPercent(item.win_rate);
        const level = cells[6].querySelector('.admin-cell-main');
        if (level) level.textContent = 'Niv. ' + formatInt(item.level);
        const games = cells[6].querySelector('.admin-cell-sub');
        if (games) games.textContent = formatInt(item.ranked_games) + ' classée(s)';
    }

    function renderLeaderboard() {
        const body = $('#morpion-leaderboard-rows');
        if (!body) return;
        const items = state.leaderboard.map(leaderboardItem);
        withFocusPreserved(() => {
            renderRows(body, items, (item) => item.id, buildLeaderboardRow, updateLeaderboardRow,
                'Aucun joueur classé pour le moment.', 7);
        });
    }

    function renderMorpionLive() {
        const body = $('#morpion-live-rows');
        if (!body) return;
        const source = (state.morpionLive && state.morpionLive.length) ? state.morpionLive : state.livePlayers;
        const items = source.map(livePlayerItem).sort((left, right) => right.lastSeenAt - left.lastSeenAt);
        withFocusPreserved(() => {
            renderRows(body, items, (item) => item.id, buildLiveRow, updateLiveRow,
                'Aucun joueur connecté dans le Morpion pour le moment.', 4);
        });
    }

    function renderMorpionCards() {
        const morpion = state.platform.morpion || {};
        const stats = state.morpion || {};
        const games = Number(morpion.games || stats.games) || 0;
        const completed = Number(morpion.completed || stats.completed) || 0;
        const ranked = Number(morpion.ranked || stats.ranked) || 0;
        const playing = state.morpionLive.length || Number(morpion.playing_now) || 0;

        const source = state.morpionStats || {};
        const setValue = (selector, value) => {
            const node = $(selector);
            if (node && node.textContent !== String(value)) node.textContent = String(value);
        };
        setValue('#morpion-games', formatInt(games));
        setValue('#morpion-completed', formatInt(completed));
        setValue('#morpion-ranked', source.ranked_sessions === undefined ? '—' : formatInt(source.ranked_sessions));
        setValue('#morpion-playing', state.morpionLive.length ? formatInt(playing) : (morpion.playing_now === undefined ? '—' : formatInt(morpion.playing_now)));
        setValue('#morpion-sessions-hour', source.sessions_last_hour === undefined ? '—' : formatInt(source.sessions_last_hour));
        setValue('#morpion-sessions-day', source.sessions_last_day === undefined ? '—' : formatInt(source.sessions_last_day));
        setValue('#morpion-points', source.points_distributed === undefined ? '—' : formatInt(source.points_distributed));

        const note = $('#morpion-completed-note');
        if (note) note.hidden = source.sessions_last_hour !== undefined;
    }

    /* --- Thèmes, symboles, salons ------------------------------------- */

    function renderThemes() {
        const body = $('#theme-rows');
        if (!body) return;
        withFocusPreserved(() => {
            body.replaceChildren();
            if (!state.themes.length) {
                emptyRow(body, 5, 'Aucun thème disponible.');
                return;
            }
            state.themes.forEach((theme) => {
                const primary = /^#[0-9a-fA-F]{6}$/.test(String(theme.primary_color)) ? theme.primary_color : '#dc2626';
                const background = /^#[0-9a-fA-F]{6}$/.test(String(theme.background_color)) ? theme.background_color : '#09090b';
                const actions = el('td');
                if (Number(theme.custom)) {
                    actions.append(actionButton('Supprimer', 'delete-theme', theme.id, true));
                } else {
                    actions.textContent = '—';
                }
                body.append(el('tr', { dataset: { themeId: String(theme.id) } }, [
                    el('td', { text: theme.name }),
                    el('td', { text: theme.key }),
                    el('td', null, [
                        el('span', { class: 'admin-swatch', style: { background: primary } }),
                        primary,
                        el('span', { class: 'admin-swatch', style: { background: background } }),
                        background,
                    ]),
                    el('td', { text: Number(theme.custom) ? 'Personnalisé' : 'Intégré' }),
                    actions,
                ]));
            });
        });
    }

    function renderSymbols() {
        const body = $('#symbol-rows');
        if (!body) return;
        withFocusPreserved(() => {
            body.replaceChildren();
            if (!state.symbols.length) {
                emptyRow(body, 4, 'Aucun symbole disponible.');
                return;
            }
            state.symbols.forEach((symbol) => {
                const actions = el('td');
                if (Number(symbol.custom)) {
                    actions.append(actionButton('Supprimer', 'delete-symbol', symbol.id, true));
                } else {
                    actions.textContent = '—';
                }
                body.append(el('tr', { dataset: { symbolId: String(symbol.id) } }, [
                    el('td', null, [el('span', { class: 'admin-symbol', text: symbol.symbol })]),
                    el('td', { text: symbol.label }),
                    el('td', { text: Number(symbol.custom) ? 'Personnalisé' : 'Intégré' }),
                    actions,
                ]));
            });
        });
    }

    function renderRooms() {
        const body = $('#room-rows');
        if (!body) return;
        withFocusPreserved(() => {
            body.replaceChildren();
            if (!state.rooms.length) {
                emptyRow(body, 5, 'Aucun salon actif.');
                return;
            }
            state.rooms.forEach((room) => {
                body.append(el('tr', { dataset: { roomId: String(room.id) } }, [
                    el('td', null, [el('strong', { text: room.id })]),
                    el('td', { text: room.status }),
                    el('td', { text: formatInt(room.players) + '/2' }),
                    el('td', { text: relTime(room.last_seen) }),
                    el('td', null, [actionButton('Supprimer', 'delete-room', room.id, true)]),
                ]));
            });
        });
    }

    /* --- Réglages : IA globale, maintenance, saison -------------------- */

    function renderSettings() {
        const settings = state.settings || {};
        const aiEnabled = String(settings.ai_enabled === undefined ? '1' : settings.ai_enabled) === '1';
        const maintenance = String(settings.maintenance || '0') === '1';
        const platform = state.platform || {};

        const aiToggle = $('#admin-ai-toggle');
        if (aiToggle) aiToggle.checked = aiEnabled;
        const aiStatus = $('#admin-ai-status');
        if (aiStatus) {
            aiStatus.dataset.state = aiEnabled ? 'on' : 'off';
            aiStatus.textContent = aiEnabled
                ? 'Space Core est actuellement activé pour tous les joueurs.'
                : 'Space Core est actuellement désactivé : aucune réponse IA n’est disponible.';
        }

        const maintenanceToggle = $('#admin-maintenance-toggle');
        if (maintenanceToggle) maintenanceToggle.checked = maintenance;
        const maintenanceStatus = $('#admin-maintenance-status');
        if (maintenanceStatus) {
            maintenanceStatus.dataset.state = maintenance ? 'off' : 'on';
            maintenanceStatus.textContent = maintenance
                ? 'Le site public est en maintenance : seuls les administrateurs accèdent aux pages.'
                : 'Le site est ouvert à tous les joueurs.';
        }
        const messageNode = $('#admin-maintenance-message-value');
        if (messageNode) {
            const message = String(settings.maintenance_message || '').trim();
            messageNode.textContent = message !== '' ? '« ' + message + ' »' : 'aucun message personnalisé';
        }

        const seasonValue = $('#admin-season-value');
        if (seasonValue) {
            seasonValue.textContent = platform.season === undefined
                ? (Number(settings.ranked_season) || '—')
                : formatInt(platform.season);
        }
        const seasonStart = $('#admin-season-start');
        if (seasonStart) {
            const started = Number(platform.season_started_at) || 0;
            seasonStart.textContent = started > 0 ? relTime(started) + ' (' + formatDateTime(started) + ')' : '—';
        }
    }

    /* --- Joueurs ------------------------------------------------------ */

    function filteredPlayers() {
        const search = state.filters.search.trim().toLowerCase();
        const status = state.filters.status;
        const rank = state.filters.rank;

        const list = state.players.filter((player) => {
            if (status === 'online' && !isOnline(player)) return false;
            if (status === 'offline' && (isOnline(player) || Number(player.is_banned))) return false;
            if (status === 'banned' && !Number(player.is_banned)) return false;
            if (status === 'trained' && !(Number(player.games_learned) > 0)) return false;
            if (status === 'ai_blocked' && !Number(player.ai_blocked)) return false;
            if (rank !== 'all' && String(player.rank || 'bronze') !== rank) return false;
            if (!search) return true;
            return (String(player.display_name) + ' ' + String(player.username || '') + ' ' + String(player.email) + ' ' + player.id).toLowerCase().indexOf(search) !== -1;
        });

        const direction = state.filters.sortDir === 'asc' ? 1 : -1;
        const key = state.filters.sortKey;
        return list.slice().sort((left, right) => {
            const a = left[key];
            const b = right[key];
            if (typeof a === 'number' && typeof b === 'number') return (a - b) * direction;
            return String(a === undefined || a === null ? '' : a)
                .localeCompare(String(b === undefined || b === null ? '' : b), 'fr', { sensitivity: 'base' }) * direction;
        });
    }

    function pageSlice(list) {
        if (!state.pageSize) return { rows: list, totalPages: 1, page: 1 };
        const totalPages = Math.max(1, Math.ceil(list.length / state.pageSize));
        const page = Math.min(Math.max(1, state.page), totalPages);
        const start = (page - 1) * state.pageSize;
        return { rows: list.slice(start, start + state.pageSize), totalPages, page };
    }

    const currentPageIds = () => pageSlice(filteredPlayers()).rows.map((player) => Number(player.id));

    function actionButton(label, operation, id, danger) {
        const button = el('button', {
            type: 'button',
            class: 'admin-action-button' + (danger ? ' is-danger' : ''),
            text: label,
        });
        button.dataset.operation = operation;
        button.dataset.id = String(id);
        button.dataset.focusKey = String(operation).indexOf('delete-') === 0
            ? 'row-' + id + '-' + operation
            : 'player-' + id + '-' + operation;
        return button;
    }

    function playerActions(player) {
        const id = Number(player.id);
        const banned = !!Number(player.is_banned);
        const aiBlocked = !!Number(player.ai_blocked);
        return el('div', { class: 'admin-actions' }, [
            actionButton('Modifier', 'edit', id, false),
            actionButton('Points', 'set_points', id, false),
            actionButton('RAZ points', 'reset_ranked', id, false),
            actionButton('XP', 'set_xp', id, false),
            actionButton(aiBlocked ? 'Débloquer SC' : 'Bloquer SC', aiBlocked ? 'ai_unblock' : 'ai_block', id, !aiBlocked),
            actionButton('Réinit. IA', 'reset_bot', id, false),
            actionButton(banned ? 'Débannir' : 'Bannir', banned ? 'unban' : 'ban', id, !banned),
            actionButton('Supprimer', 'delete', id, true),
        ]);
    }

    function playerRow(player) {
        const id = Number(player.id);
        const banned = !!Number(player.is_banned);
        const aiBlocked = !!Number(player.ai_blocked);
        const online = isOnline(player);
        const selected = state.selection.has(id);
        const games = Number(player.ranked_games) || 0;

        const checkbox = el('input', {
            type: 'checkbox',
            class: 'player-select',
            value: String(id),
            'aria-label': 'Sélectionner ' + player.display_name,
        });
        checkbox.checked = selected;

        return el('tr', {
            class: [banned ? 'is-banned' : '', selected ? 'is-selected' : ''].filter(Boolean).join(' ') || null,
            dataset: { playerId: String(id) },
        }, [
            el('td', { class: 'admin-col-select' }, [checkbox]),
            el('td', null, [
                el('span', { class: 'presence-dot' + (online ? ' is-online' : ''), dataset: { presence: '1' } }),
                banned ? 'Banni' : (online ? 'En ligne' : 'Hors ligne'),
                el('span', { class: 'admin-cell-sub', text: relTime(player.last_seen_at) }),
                aiBlocked ? el('span', { class: 'admin-cell-sub', text: 'Space Core bloqué' }) : null,
            ]),
            el('td', null, [
                el('span', { class: 'admin-live-name' }, [
                    el('span', { class: 'admin-live-avatar', 'aria-hidden': 'true', text: player.avatar || '🚀' }),
                    player.display_name,
                ]),
            ]),
            el('td', { text: player.email }),
            el('td', null, [
                el('span', { class: 'admin-cell-main', text: playerCellPoints(player) }),
                el('span', { class: 'admin-rank-cell admin-cell-sub', text: rankBadge(player) }),
            ]),
            el('td', null, [
                el('span', { class: 'admin-cell-main', text: 'Niv. ' + formatInt(player.level) }),
                el('span', { class: 'admin-cell-sub', text: formatInt(player.xp) + ' XP' }),
            ]),
            el('td', { text: formatInt(player.games) }),
            el('td', { text: formatInt(player.learning_level) }),
            el('td', { text: formatInt(player.games_learned) }),
            el('td', { text: formatDate(player.created_at) }),
            el('td', { text: '@' + (player.username || '') }),
            el('td', { text: player.profile_visibility || 'public' }),
            el('td', null, [playerActions(player)]),
        ]);
    }

    function playerCellPoints(player) {
        const points = formatInt(player.ranked_points) + ' pts';
        const games = Number(player.ranked_games) || 0;
        return games > 0 ? points + ' · ' + formatInt(games) + ' classée(s)' : points;
    }

    function updatePlayerRow(row, player) {
        const cells = row.cells;
        if (cells.length < 13) return;
        const banned = !!Number(player.is_banned);
        const aiBlocked = !!Number(player.ai_blocked);
        const online = isOnline(player);
        const selected = state.selection.has(Number(player.id));

        row.classList.toggle('is-banned', banned);
        row.classList.toggle('is-selected', selected);
        const checkbox = cells[0].querySelector('input.player-select');
        if (checkbox && checkbox.checked !== selected) checkbox.checked = selected;

        const statusCell = cells[1];
        const dot = statusCell.querySelector('.presence-dot');
        if (dot) dot.classList.toggle('is-online', online);
        const statusText = banned ? 'Banni' : (online ? 'En ligne' : 'Hors ligne');
        const currentStatus = statusCell.childNodes[1];
        if (currentStatus && currentStatus.nodeType === Node.TEXT_NODE && currentStatus.textContent !== statusText) {
            currentStatus.textContent = statusText;
        }
        let sub = statusCell.querySelector('.admin-cell-sub');
        if (sub) sub.textContent = relTime(player.last_seen_at);
        let aiSub = statusCell.querySelectorAll('.admin-cell-sub')[1] || null;
        if (aiBlocked && !aiSub) {
            statusCell.append(el('span', { class: 'admin-cell-sub', text: 'Space Core bloqué' }));
        } else if (!aiBlocked && aiSub) {
            aiSub.remove();
        }

        const name = cells[2].querySelector('.admin-live-name');
        if (name) {
            const avatar = name.querySelector('.admin-live-avatar');
            if (avatar) avatar.textContent = player.avatar || '🚀';
            name.lastChild.textContent = player.display_name;
        }
        cells[3].textContent = player.email;
        const points = cells[4].querySelector('.admin-cell-main');
        if (points) points.textContent = playerCellPoints(player);
        const rank = cells[4].querySelector('.admin-rank-cell');
        if (rank) rank.textContent = rankBadge(player);
        const level = cells[5].querySelector('.admin-cell-main');
        if (level) level.textContent = 'Niv. ' + formatInt(player.level);
        const xp = cells[5].querySelector('.admin-cell-sub');
        if (xp) xp.textContent = formatInt(player.xp) + ' XP';
        cells[6].textContent = formatInt(player.games);
        cells[7].textContent = formatInt(player.learning_level);
        cells[8].textContent = formatInt(player.games_learned);
        cells[9].textContent = formatDate(player.created_at);
        cells[10].textContent = '@' + (player.username || '');
        cells[11].textContent = player.profile_visibility || 'public';

        const actions = cells[12].querySelector('.admin-actions');
        if (actions) {
            withFocusPreserved(() => {
                const fresh = playerActions(player);
                actions.replaceChildren();
                // Instantané statique : itérer directement sur childNodes pendant
                // qu'on le vide ferait perdre une action sur deux. childNodes est
                // une NodeList, dépourvue de slice() : on passe par Array.from.
                Array.from(fresh.childNodes).forEach((child) => actions.append(child));
            });
        }
        void sub;
    }

    function renderPlayers() {
        const body = $('#player-rows');
        if (!body) return;

        const list = filteredPlayers();
        const slice = pageSlice(list);
        state.page = slice.page;

        withFocusPreserved(() => {
            renderRows(body, slice.rows, (player) => player.id, playerRow, updatePlayerRow,
                state.players.length ? 'Aucun joueur ne correspond à ces filtres.' : 'Aucun joueur inscrit.', 13);
        });

        const countNode = $('#player-count');
        if (countNode) {
            countNode.textContent = list.length === state.players.length
                ? formatInt(list.length) + ' compte(s) joueur'
                : formatInt(list.length) + ' compte(s) affiché(s) sur ' + formatInt(state.players.length);
        }

        const pager = $('#player-pager');
        if (pager) pager.hidden = !state.pageSize || slice.totalPages <= 1;
        const pagerInfo = $('#player-pager-info');
        if (pagerInfo) pagerInfo.textContent = 'Page ' + slice.page + ' / ' + slice.totalPages;
        $$('#player-pager [data-page]').forEach((button) => {
            button.disabled = button.dataset.page === 'prev' ? slice.page <= 1 : slice.page >= slice.totalPages;
        });

        syncSortUi();
        syncSelectionUi();
    }

    function syncSortUi() {
        $$('.admin-sort').forEach((button) => {
            const active = button.dataset.sort === state.filters.sortKey;
            const indicator = button.querySelector('.admin-sort-ind');
            if (active) button.dataset.dir = state.filters.sortDir;
            else delete button.dataset.dir;
            if (indicator) indicator.textContent = active ? (state.filters.sortDir === 'asc' ? '↑' : '↓') : '↕';
            const header = button.closest('th');
            if (header) {
                header.setAttribute('aria-sort', active
                    ? (state.filters.sortDir === 'asc' ? 'ascending' : 'descending')
                    : 'none');
            }
        });
        const preset = $('#player-sort-preset');
        if (preset && preset.value !== state.filters.sortKey) {
            const hasOption = Array.prototype.some.call(preset.options, (option) => option.value === state.filters.sortKey);
            if (hasOption) preset.value = state.filters.sortKey;
        }
    }

    function syncSelectionUi() {
        const visibleIds = filteredPlayers().map((player) => Number(player.id));
        const visibleSet = new Set(visibleIds);
        let selectedVisible = 0;
        state.selection.forEach((id) => {
            if (visibleSet.has(id)) selectedVisible += 1;
        });

        const bar = $('#player-bulkbar');
        if (bar) bar.hidden = selectedVisible === 0;
        const label = $('#player-bulk-count');
        if (label) {
            label.textContent = formatInt(selectedVisible) + ' compte' + (selectedVisible > 1 ? 's' : '') +
                ' sélectionné' + (selectedVisible > 1 ? 's' : '');
        }

        const pageIds = currentPageIds();
        let selectedOnPage = 0;
        pageIds.forEach((id) => {
            if (state.selection.has(id)) selectedOnPage += 1;
        });

        const checkAll = $('#player-check-all');
        if (checkAll) {
            checkAll.disabled = pageIds.length === 0;
            checkAll.checked = pageIds.length > 0 && selectedOnPage === pageIds.length;
            checkAll.indeterminate = selectedOnPage > 0 && selectedOnPage < pageIds.length;
        }
    }

    function pruneSelection() {
        const existing = new Set(state.players.map((player) => Number(player.id)));
        let removed = false;
        Array.from(state.selection).forEach((id) => {
            if (!existing.has(id)) {
                state.selection.delete(id);
                removed = true;
            }
        });
        if (removed) syncSelectionUi();
    }

    /** Rafraîchit uniquement les libellés dépendants de l'heure (chaque seconde). */
    function refreshRelativeTimes() {
        const byId = new Map(state.players.map((player) => [String(player.id), player]));
        $$('#player-rows tr[data-player-id]').forEach((row) => {
            const player = byId.get(row.dataset.playerId);
            if (!player) return;
            const cell = row.cells[1];
            if (!cell) return;
            const sub = cell.querySelector('.admin-cell-sub');
            if (sub) sub.textContent = relTime(player.last_seen_at);
            const dot = cell.querySelector('.presence-dot');
            if (dot) dot.classList.toggle('is-online', isOnline(player));
        });

        const roomsById = new Map(state.rooms.map((room) => [String(room.id), room]));
        $$('#room-rows tr[data-room-id]').forEach((row) => {
            const room = roomsById.get(row.dataset.roomId);
            if (!room || !row.cells[3]) return;
            row.cells[3].textContent = relTime(room.last_seen);
        });

        const liveById = new Map(state.livePlayers.map((player) => [String(player.id), player]));
        $$('#live-player-rows tr[data-live-id], #morpion-live-rows tr[data-live-id]').forEach((row) => {
            const player = liveById.get(row.dataset.liveId);
            if (!player || !row.cells[2]) return;
            const main = row.cells[2].querySelector('.admin-cell-main');
            if (main) main.textContent = relTime(player.last_context_at);
            const sub = row.cells[2].querySelector('.admin-cell-sub');
            if (sub) sub.textContent = 'vu ' + relTime(player.last_seen_at);
        });

        const eventsById = new Map(state.events.map((event) => [String(event.id), event]));
        $$('#event-rows tr[data-event-id]').forEach((row) => {
            const event = eventsById.get(row.dataset.eventId);
            if (!event || !row.cells[0]) return;
            const time = row.cells[0].querySelector('.admin-event-time');
            if (time) time.textContent = relTime(event.created_at);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Onglets : registre extensible                                       */
    /* ------------------------------------------------------------------ */

    const tabs = {
        order: [],
        active: null,
        /** Publie un onglet/un univers de jeu. */
        register(descriptor) {
            if (!descriptor || !descriptor.id) return;
            const entry = Object.assign({ available: true, onActivate: null }, descriptor);
            this.order = this.order.filter((item) => item.id !== entry.id);
            this.order.push(entry);
            return entry;
        },
        /** Active un onglet : état ARIA, panneau visible, tableau de bord. */
        activate(id) {
            const target = this.order.find((entry) => entry.id === id && entry.available !== false);
            if (!target) return false;
            this.active = id;
            $$('#admin-tabs [role="tab"]').forEach((tab) => {
                const selected = tab.dataset.tab === id;
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.tabIndex = selected ? 0 : -1;
                if (selected) tab.removeAttribute('aria-disabled');
            });
            $$('[data-tab-panel]').forEach((panel) => {
                panel.hidden = panel.dataset.tabPanel !== id;
                panel.setAttribute('aria-hidden', panel.dataset.tabPanel === id ? 'false' : 'true');
            });
            try {
                window.history.replaceState(null, '', '#' + id);
            } catch (error) {
                /* historique indisponible : on ignore */
            }
            if (typeof target.onActivate === 'function') target.onActivate();
            return true;
        },
    };

    /* Universalisation : décrire chaque futur univers ici suffit à le brancher. */
    tabs.register({ id: 'global', label: 'Global', available: true });
    tabs.register({
        id: 'morpion',
        label: 'Morpion',
        available: true,
        apiAction: 'admin_morpion_stats',
        onActivate: () => {
            renderMorpionCards();
            requestMorpionStats();
        },
    });
    tabs.register({
        id: 'space',
        label: 'Space IA',
        available: true,
        onActivate: requestSpaceKnowledge,
    });
    tabs.register({
        id: 'social',
        label: 'Social',
        available: true,
        onActivate: requestSocialData,
    });

    function renderSocialData() {
        if (!state.social) return;
        const stats = state.social.stats || {};
        ['friendships', 'pending_requests', 'blocked', 'without_friends'].forEach((key) => {
            const node = $('#social-' + ({
                friendships: 'friendships', pending_requests: 'pending', blocked: 'blocked', without_friends: 'lonely',
            })[key]);
            if (node) node.textContent = formatInt(stats[key] || 0);
        });
        const settings = state.social.settings || {};
        const settingsNode = $('#social-settings');
        if (settingsNode) {
            settingsNode.replaceChildren();
            const toggles = [
                ['social_friends_enabled', 'Système d’amis activé'],
                ['social_public_profiles_enabled', 'Profils publics activés'],
            ];
            toggles.forEach(([key, label]) => {
                const input = el('input', { type: 'checkbox', checked: settings[key] !== '0', dataset: { socialSetting: key } });
                settingsNode.append(el('label', { class: 'admin-switch' }, [input, el('span', { text: label })]));
            });
            const visibility = el('select', { dataset: { socialSetting: 'social_default_visibility' } }, [
                el('option', { value: 'public', text: 'Public' }),
                el('option', { value: 'friends', text: 'Amis seulement' }),
                el('option', { value: 'private', text: 'Privé' }),
            ]);
            visibility.value = settings.social_default_visibility || 'public';
            settingsNode.append(el('label', { class: 'admin-field' }, [el('span', { text: 'Visibilité par défaut' }), visibility]));
            [
                ['social_friend_limit', 'Limite d’amis', 1, 10000],
                ['social_daily_request_limit', 'Demandes par jour', 1, 500],
            ].forEach(([key, label, min, max]) => {
                const input = el('input', {
                    type: 'number', min: min, max: max, value: settings[key] || (key === 'social_friend_limit' ? 200 : 20),
                    dataset: { socialSetting: key },
                });
                settingsNode.append(el('label', { class: 'admin-field' }, [el('span', { text: label }), input]));
            });
        }

        const search = state.socialSearch.trim().toLowerCase();
        const players = (state.social.players || []).filter((player) =>
            !search || [player.display_name, player.username, player.bio].join(' ').toLowerCase().includes(search)
        );
        const playerRows = $('#social-player-rows');
        if (playerRows) {
            playerRows.replaceChildren();
            if (!players.length) {
                playerRows.append(el('tr', null, [el('td', { colSpan: 6, class: 'admin-empty', text: 'Aucun profil correspondant.' })]));
            }
            players.forEach((player) => {
                const buttons = [
                    ['Réinit. bio', 'reset_bio'],
                    ['Réinit. avatar', 'reset_avatar'],
                    ['Réinit. pseudo', 'reset_username'],
                    ['Forcer privé', 'force_private'],
                    [Number(player.social_suspended) ? 'Rétablir social' : 'Suspendre social', Number(player.social_suspended) ? 'unsuspend_social' : 'suspend_social'],
                ].map(([label, action]) => el('button', {
                    type: 'button', class: 'admin-action-button', text: label,
                    dataset: { socialProfileAction: action, userId: player.id },
                }));
                playerRows.append(el('tr', null, [
                    el('td', { text: player.display_name }),
                    el('td', { text: '@' + (player.username || '') }),
                    el('td', { text: player.bio || '—' }),
                    el('td', { text: player.profile_visibility || 'public' }),
                    el('td', { text: Number(player.social_suspended) ? 'Suspendu' : 'Actif' }),
                    el('td', { class: 'admin-actions' }, buttons),
                ]));
            });
        }

        const reports = $('#social-report-rows');
        if (reports) {
            reports.replaceChildren();
            const pending = state.social.reports || [];
            if (!pending.length) reports.append(el('tr', null, [el('td', { colSpan: 5, class: 'admin-empty', text: 'Aucun signalement en attente.' })]));
            pending.forEach((report) => reports.append(el('tr', null, [
                el('td', { text: report.reporter_name + ' @' + report.reporter_username }),
                el('td', { text: report.target_name + ' @' + report.target_username }),
                el('td', { text: report.reason }),
                el('td', { text: formatDateTime(report.created_at) }),
                el('td', null, [
                    el('button', { type: 'button', class: 'admin-action-button', text: 'Traité', dataset: { reportId: report.id, reportStatus: 'resolved' } }),
                    el('button', { type: 'button', class: 'admin-action-button is-danger', text: 'Sanctionner', dataset: { reportId: report.id, reportStatus: 'sanctioned' } }),
                ]),
            ])));
        }

        const achievements = $('#social-achievement-rows');
        if (achievements) {
            achievements.replaceChildren();
            (state.social.achievements || []).forEach((achievement) => {
                const toggle = el('input', {
                    type: 'checkbox', checked: !!achievement.enabled,
                    dataset: { achievementToggle: achievement.key },
                });
                const select = el('select', { dataset: { achievementTarget: achievement.key } }, [
                    el('option', { value: '', text: 'Choisir un joueur…' }),
                    ...(state.social.players || []).map((player) => el('option', {
                        value: player.id, text: player.display_name + ' (@' + (player.username || '') + ')',
                    })),
                ]);
                const award = el('button', {
                    type: 'button', class: 'admin-action-button', text: 'Attribuer',
                    dataset: { awardAchievement: achievement.key },
                });
                achievements.append(el('tr', null, [
                    el('td', { text: achievement.name }),
                    el('td', { text: achievement.description }),
                    el('td', null, [toggle, achievement.enabled ? ' Activé' : ' Désactivé']),
                    el('td', null, [select, award]),
                ]));
            });
        }
    }

    async function requestSocialData() {
        try {
            state.social = await apiGet('admin_social_data');
            renderSocialData();
        } catch (error) {
            showError(error);
            toast(error.message || String(error), 'error');
        }
    }

    async function updateSocial(payload, successMessage) {
        try {
            const response = await apiPost('admin_social_action', payload);
            state.social = response.social;
            renderSocialData();
            applySection('players', state.social.players || [], renderPlayers);
            if (successMessage) toast(successMessage, 'success');
        } catch (error) {
            showError(error);
            toast(error.message || String(error), 'error');
        }
    }

    async function refreshRankingScope() {
        const select = $('#admin-ranking-game');
        if (!select) return;
        const labels = { morpion: 'Morpion', connect4: 'Puissance 4', pong: 'Pong', naval: 'Bataille Navale' };
        const gameId = select.value;
        try {
            const result = await apiGet('admin_game_rank_stats', { game_id: gameId });
            state.topPlayer = result.top_player || null;
            state.rankDistribution = result.distribution || [];
            const topTitle = $('#admin-top-player-title');
            const distributionTitle = $('#admin-rank-distribution-title');
            if (topTitle) topTitle.textContent = 'Joueur n°1 — ' + (labels[gameId] || gameId);
            if (distributionTitle) distributionTitle.textContent = 'Répartition des joueurs par palier — ' + (labels[gameId] || gameId);
            applySection('topPlayer', state.topPlayer, renderTopPlayer);
            applySection('rankDistribution', state.rankDistribution, renderRankDistribution);
            updateSeasonDisplay(result);
        } catch (error) {
            showError(error);
            toast(error.message || String(error), 'error');
        }
    }

    function updateSeasonDisplay(seasonData) {
        const season = $('#admin-season-value');
        const started = $('#admin-season-start');
        if (season) season.textContent = formatInt(seasonData.season);
        if (started) started.textContent = formatDate(seasonData.season_started_at);
    }

    async function refreshSeasonScope() {
        const select = $('#admin-season-game');
        if (!select) return;
        try {
            updateSeasonDisplay(await apiGet('admin_game_rank_stats', { game_id: select.value }));
        } catch (error) {
            showError(error);
            toast(error.message || String(error), 'error');
        }
    }

    function renderSpaceKnowledge() {
        const body = $('#space-knowledge-rows');
        if (!body) return;
        if (!state.spaceKnowledge.length) {
            body.replaceChildren(el('tr', null, [
                el('td', { colSpan: 5, class: 'admin-empty', text: 'Aucune connaissance enregistrée.' }),
            ]));
            return;
        }
        body.replaceChildren(...state.spaceKnowledge.map((entry) => {
            const sourceLabels = {
                admin: 'Admin',
                groq_extracted: 'Groq · auto',
                groq: 'Groq',
                gemini: 'Gemini',
            };
            const source = sourceLabels[entry.source] || 'Autre';
            return el('tr', { dataset: { knowledgeId: entry.id } }, [
                el('td', { text: entry.keyword }),
                el('td', { class: 'space-knowledge-content', text: entry.content }),
                el('td', null, [el('span', { class: 'admin-tag space-source-tag', text: source })]),
                el('td', { text: formatDateTime(entry.updated_at) }),
                el('td', null, [
                    el('button', {
                        class: 'admin-action-button is-danger',
                        type: 'button',
                        dataset: { knowledgeDelete: entry.id },
                        text: 'Supprimer',
                    }),
                ]),
            ]);
        }));
    }

    function renderSpaceUsage() {
        const body = $('#space-usage-rows');
        if (!body) return;
        const labels = {
            account: 'Compte et profil',
            bot: 'Bot du Morpion',
            games: 'Jeux et parties',
            hub: 'Accueil et navigation',
            leaderboard: 'Classement et progression',
            morpion: 'Morpion et grilles',
            other: 'Autres sujets',
            space: 'Space IA',
            themes: 'Thèmes et apparence',
            wifi: 'Salons Wi-Fi',
        };
        if (!state.spaceUsage.length) {
            body.replaceChildren(el('tr', null, [
                el('td', { colSpan: 2, class: 'admin-empty', text: 'Aucune statistique d’usage pour le moment.' }),
            ]));
            return;
        }
        body.replaceChildren(...state.spaceUsage.map((item) => el('tr', null, [
            el('td', { text: labels[item.feature_key] || 'Autres sujets' }),
            el('td', { text: formatInt(item.usage_count) }),
        ])));
    }

    async function requestSpaceKnowledge() {
        try {
            const payload = await apiGet('admin_space_knowledge');
            state.spaceKnowledge = Array.isArray(payload.knowledge) ? payload.knowledge : [];
            state.spaceUsage = Array.isArray(payload.usage) ? payload.usage : [];
            renderSpaceKnowledge();
            renderSpaceUsage();
        } catch (error) {
            showError(error);
            toast(error.message || String(error), 'error');
        }
    }

    function bindTabs() {
        const strip = $('#admin-tabs');
        if (!strip) return;
        const buttons = $$('#admin-tabs [role="tab"]');

        strip.addEventListener('click', (event) => {
            const tab = event.target.closest('[role="tab"]');
            if (!tab || tab.disabled) return;
            tabs.activate(tab.dataset.tab);
        });

        strip.addEventListener('keydown', (event) => {
            if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) === -1) return;
            const enabled = buttons.filter((button) => !button.disabled);
            if (!enabled.length) return;
            event.preventDefault();
            const current = enabled.indexOf(event.target.closest('[role="tab"]'));
            let next = current;
            if (event.key === 'ArrowLeft') next = current <= 0 ? enabled.length - 1 : current - 1;
            else if (event.key === 'ArrowRight') next = current === enabled.length - 1 ? 0 : current + 1;
            else if (event.key === 'Home') next = 0;
            else next = enabled.length - 1;
            const target = enabled[next];
            tabs.activate(target.dataset.tab);
            target.focus();
        });

        const initial = (window.location.hash || '').replace('#', '');
        const requested = initial && tabs.order.some((entry) => entry.id === initial && entry.available !== false)
            ? initial
            : 'global';
        tabs.activate(requested);
        window.addEventListener('hashchange', () => {
            const wanted = (window.location.hash || '').replace('#', '');
            if (wanted && wanted !== tabs.active) tabs.activate(wanted);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Actualisation                                                       */
    /* ------------------------------------------------------------------ */

    async function requestMorpionStats() {
        if (isMorpionBusy) return;
        isMorpionBusy = true;
        try {
            const payload = await apiGet('admin_morpion_stats');
            if (typeof payload.server_time === 'number') {
                clockOffset = payload.server_time - Math.floor(Date.now() / 1000);
            }
            const morpion = payload.morpion || {};
            state.morpionStats = morpion;
            applySection('gridUsage', morpion.grid_usage || [], renderGridUsage);
            applySection('morpionLive', morpion.live_players || [], renderMorpionLive);
            if (Array.isArray(payload.leaderboard)) {
                applySection('leaderboard', payload.leaderboard, renderLeaderboard);
            }
            if (Array.isArray(payload.bot_levels) && payload.bot_levels.length) {
                applySection('botLevels', payload.bot_levels, renderBotLevels);
            }
            if (Array.isArray(payload.popular_modes) && payload.popular_modes.length) {
                applySection('popularModes', payload.popular_modes, renderPopularModes);
            }
            if (payload.top_player && !state.topPlayer) {
                applySection('topPlayer', payload.top_player, renderTopPlayer);
            }
            if (Array.isArray(payload.distribution) && payload.distribution.length && !state.platform.rank_distribution) {
                applySection('rankDistribution', payload.distribution, renderRankDistribution);
            }
            renderMorpionCards();
        } catch (error) {
            showError(error);
        } finally {
            isMorpionBusy = false;
        }
    }

    let isMorpionBusy = false;
    let isEventsBusy = false;

    async function refreshEvents(options) {
        const settings = Object.assign({ quiet: false }, options || {});
        if (isEventsBusy) return;
        isEventsBusy = true;
        const select = $('#event-category');
        const limitSelect = $('#event-limit');
        const category = select ? select.value || 'all' : 'all';
        const limit = limitSelect ? Number(limitSelect.value) || 50 : 50;
        try {
            const payload = await apiGet('admin_events', { category: category, limit: limit });
            if (typeof payload.server_time === 'number') {
                clockOffset = payload.server_time - Math.floor(Date.now() / 1000);
            }
            if (Array.isArray(payload.categories)) {
                applySection('eventCategories', payload.categories, renderEventCategories);
            }
            if (Array.isArray(payload.events)) {
                applySection('events', payload.events, renderEvents);
            }
            if (!settings.quiet) toast('Journal des événements actualisé.', 'success');
        } catch (error) {
            showError(error);
            if (!settings.quiet) toast(error.message || String(error), 'error');
        } finally {
            isEventsBusy = false;
        }
    }

    async function refreshDashboard(options) {
        const settings = Object.assign({ background: false }, options || {});
        if (state.inFlight) return;
        state.inFlight = true;
        setLive('loading');
        pushBusy();

        try {
            const results = await Promise.allSettled([
                apiGet('admin_platform_stats'),
                apiGet('admin_rooms'),
                apiGet('admin_events', { category: 'all', limit: 25 }),
            ]);
            const platform = results[0].status === 'fulfilled' ? results[0].value : null;
            const rooms = results[1].status === 'fulfilled' ? results[1].value : null;
            const events = results[2].status === 'fulfilled' ? results[2].value : null;
            const failures = results
                .filter((result) => result.status === 'rejected')
                .map((result) => result.reason)
                .filter(Boolean);

            if (platform) {
                if (typeof platform.server_time === 'number') {
                    clockOffset = platform.server_time - Math.floor(Date.now() / 1000);
                }
                if (platform.summary) applySection('summary', platform.summary, renderSummary);
                const platformData = platform.platform || {};
                if (Object.keys(platformData).length) {
                    applySection('platform', platformData, renderTopPlayer);
                }
                if (platformData.settings || platform.settings) {
                    applySection('settings', platformData.settings || platform.settings, renderSettings);
                }
                if (Array.isArray(platformData.rank_distribution) && platformData.rank_distribution.length) {
                    applySection('rankDistribution', platformData.rank_distribution, renderRankDistribution);
                }
                const topPlayer = platformData.top_player || null;
                if (topPlayer) {
                    // Certaines réponses n'exposent pas la progression dans le
                    // palier : on conserve alors celle déjà calculée côté serveur
                    // plutôt que d'afficher une barre vide.
                    const merged = Object.assign({}, state.topPlayer || {}, topPlayer);
                    if (topPlayer.rank_progress === undefined && state.topPlayer && state.topPlayer.rank_progress !== undefined) {
                        merged.rank_progress = state.topPlayer.rank_progress;
                    }
                    applySection('topPlayer', merged, renderTopPlayer);
                }
                if (Array.isArray(platform.leaderboard)) {
                    applySection('leaderboard', platform.leaderboard, renderLeaderboard);
                }
                if (Array.isArray(platform.live_players)) {
                    applySection('livePlayers', platform.live_players, renderLivePlayers);
                }
                if (Array.isArray(platform.events) && !state.events.length) {
                    applySection('events', platform.events, renderEvents);
                }
                if (Array.isArray(platform.event_categories)) {
                    applySection('eventCategories', platform.event_categories, renderEventCategories);
                }
                if (Array.isArray(platform.bot_levels) && platform.bot_levels.length) {
                    applySection('botLevels', platform.bot_levels, renderBotLevels);
                }
                if (Array.isArray(platform.popular_modes) && platform.popular_modes.length) {
                    applySection('popularModes', platform.popular_modes, renderPopularModes);
                }
                if (Array.isArray(platform.players) && platform.players.length) {
                    applySection('players', platform.players, renderPlayers);
                    pruneSelection();
                }
                if (Array.isArray(platform.themes) && platform.themes.length) {
                    applySection('themes', platform.themes, renderThemes);
                }
                if (Array.isArray(platform.symbols) && platform.symbols.length) {
                    applySection('symbols', platform.symbols, renderSymbols);
                }
                if (Array.isArray(platform.rooms)) {
                    applySection('rooms', platform.rooms, renderRooms);
                }
                renderSummary();
                if (tabs.active === 'morpion') {
                    renderMorpionCards();
                    refreshMorpionInBackground();
                }
            }
            if (rooms) {
                applySection('rooms', rooms.rooms || [], renderRooms);
            }
            if (events) {
                if (Array.isArray(events.categories)) {
                    applySection('eventCategories', events.categories, renderEventCategories);
                }
                const select = $('#event-category');
                const wanted = select ? select.value || 'all' : 'all';
                if (wanted === 'all') applySection('events', events.events || [], renderEvents);
            }

            if (failures.length) {
                setLive('error');
                showError(failures[0]);
                if (!settings.background) toast(failures[0].message, 'error');
            } else {
                setLive('ok');
                hideError();
                state.lastRefresh = Date.now();
            }
        } finally {
            state.inFlight = false;
            popBusy();
            updateLiveLabel();
        }
    }

    /** Complète l'univers Morpion sans bloquer l'actualisation principale. */
    function refreshMorpionInBackground() {
        if (tabs.active === 'morpion' && !state.hydrateStarted) {
            state.hydrateStarted = true;
        }
        if (isMorpionBusy) return;
        window.setTimeout(() => { requestMorpionStats(); }, 0);
    }

    /* ------------------------------------------------------------------ */
    /* Actions serveur                                                     */
    /* ------------------------------------------------------------------ */

    async function runAction(button, action, payload, options) {
        const settings = Object.assign({ success: '', reload: true }, options || {});
        if (button) {
            button.disabled = true;
            button.classList.add('is-busy');
        }
        pushBusy();
        try {
            const result = await apiPost(action, payload);
            if (settings.success) toast(settings.success, 'success');
            if (settings.reload) await refreshDashboard({ background: true });
            if (settings.after) await settings.after(result);
            return result;
        } catch (error) {
            showError(error);
            toast(error.message || String(error), 'error');
            return null;
        } finally {
            if (button && button.isConnected) {
                button.disabled = false;
                button.classList.remove('is-busy');
            }
            popBusy();
        }
    }

    function playerDisplay(player) {
        return String(player.display_name || ('#' + player.id));
    }

    async function handlePlayerOperation(button, player, operation) {
        const id = Number(player.id);
        const name = playerDisplay(player);

        if (operation === 'edit') {
            const values = await openDialog({
                title: 'Modifier ' + name,
                message: 'Le pseudo et l’adresse e-mail sont appliqués immédiatement au compte.',
                confirmLabel: 'Enregistrer',
                fields: [
                    { name: 'display_name', label: 'Pseudo', value: player.display_name, required: true, minLength: 2, maxLength: 30 },
                    { name: 'email', label: 'Adresse e-mail', type: 'email', value: player.email, required: true, maxLength: 190 },
                ],
            });
            if (!values) return;
            await runAction(button, 'admin_manage_user', {
                operation: 'edit',
                user_id: id,
                display_name: values.display_name,
                email: values.email,
            }, { success: 'Compte « ' + values.display_name + ' » mis à jour.' });
            return;
        }

        if (operation === 'set_points') {
            const gameId = ($('#admin-player-game') || {}).value || 'morpion';
            let progressResult;
            try {
                progressResult = await apiGet('admin_player_game_progress', { user_id: id, game_id: gameId });
            } catch (error) {
                showError(error);
                toast(error.message || String(error), 'error');
                return;
            }
            const current = Number(progressResult.progress.ranked_points) || 0;
            const values = await openDialog({
                title: 'Points classés de ' + name,
                message: 'Valeur actuelle : ' + formatInt(current) + ' points (' + rankBadge(player) + '). '
                    + 'Les points sont bornés entre 0 et 1 000 000.',
                confirmLabel: 'Enregistrer les points',
                fields: [{
                    name: 'points',
                    label: 'Nouveau total de points classés',
                    type: 'number',
                    value: current,
                    required: true,
                    min: 0,
                    max: 1000000,
                }],
            });
            if (!values) return;
            await runAction(button, 'admin_set_points', { user_id: id, game_id: gameId, points: Number(values.points) }, {
                success: 'Points de ' + name + ' fixés à ' + formatInt(values.points) + '.',
            });
            return;
        }

        if (operation === 'set_xp') {
            const gameId = ($('#admin-player-game') || {}).value || 'morpion';
            let progressResult;
            try {
                progressResult = await apiGet('admin_player_game_progress', { user_id: id, game_id: gameId });
            } catch (error) {
                showError(error);
                toast(error.message || String(error), 'error');
                return;
            }
            const current = Number(progressResult.progress.xp) || 0;
            const values = await openDialog({
                title: 'XP de ' + name,
                message: 'Valeur actuelle : ' + formatInt(current) + ' XP (niveau ' + formatInt(progressResult.progress.level) + '). '
                    + 'L’XP est bornée entre 0 et 10 000 000.',
                confirmLabel: 'Enregistrer l’XP',
                fields: [{
                    name: 'xp',
                    label: 'Nouveau total d’XP',
                    type: 'number',
                    value: current,
                    required: true,
                    min: 0,
                    max: 10000000,
                }],
            });
            if (!values) return;
            await runAction(button, 'admin_set_xp', { user_id: id, game_id: gameId, xp: Number(values.xp) }, {
                success: 'XP de ' + name + ' fixée à ' + formatInt(values.xp) + '.',
            });
            return;
        }

        if (operation === 'reset_ranked') {
            const gameId = ($('#admin-player-game') || {}).value || 'morpion';
            const confirmed = await openDialog({
                title: 'Remettre à zéro les points de ' + name + ' ?',
                message: 'Les points classés du jeu sélectionné repassent à 0 et le pic de points est réinitialisé. '
                    + 'Le nombre de parties classées est conservé.',
                confirmLabel: 'Remettre à zéro',
                danger: true,
                fields: [{ name: 'confirm', label: 'Saisissez RAZ pour confirmer', placeholder: 'RAZ', required: true }],
                validate: (values) => values.confirm === 'RAZ' ? '' : 'Saisissez exactement RAZ.',
            });
            if (!confirmed) return;
            await runAction(button, 'admin_manage_user', { operation: 'reset_ranked', user_id: id, game_id: gameId }, {
                success: 'Points classés de ' + name + ' remis à zéro.',
            });
            return;
        }

        if (operation === 'ai_block' || operation === 'ai_unblock') {
            const blocking = operation === 'ai_block';
            const confirmed = await openDialog({
                title: (blocking ? 'Bloquer' : 'Débloquer') + ' Space Core pour ' + name + ' ?',
                message: blocking
                    ? 'Ce joueur ne pourra plus interroger l’assistant Space Core.'
                    : 'Ce joueur retrouvera l’accès complet à l’assistant Space Core.',
                confirmLabel: blocking ? 'Bloquer l’accès' : 'Rétablir l’accès',
                danger: blocking,
            });
            if (!confirmed) return;
            await runAction(button, 'admin_manage_user', { operation: operation, user_id: id }, {
                success: 'Accès à Space Core ' + (blocking ? 'bloqué' : 'rétabli') + ' pour ' + name + '.',
            });
            return;
        }

        if (operation === 'ban' || operation === 'unban') {
            const banning = operation === 'ban';
            const confirmed = await openDialog({
                title: (banning ? 'Bannir ' : 'Débannir ') + name + ' ?',
                message: banning
                    ? 'Le joueur ne pourra plus se connecter et sera déconnecté dès sa prochaine action.'
                    : 'Le joueur pourra de nouveau se connecter et jouer.',
                confirmLabel: banning ? 'Bannir' : 'Débannir',
                danger: banning,
            });
            if (!confirmed) return;
            await runAction(button, 'admin_manage_user', { operation: operation, user_id: id }, {
                success: name + (banning ? ' a été banni.' : ' a été débanni.'),
            });
            return;
        }

        if (operation === 'reset_bot') {
            const confirmed = await openDialog({
                title: 'Réinitialiser l’IA de ' + name + ' ?',
                message: 'Le niveau appris, les erreurs mémorisées et le compteur de parties apprises repartent de zéro.',
                confirmLabel: 'Réinitialiser',
            });
            if (!confirmed) return;
            await runAction(button, 'admin_manage_user', { operation: 'reset_bot', user_id: id }, {
                success: 'Profil de bot de ' + name + ' réinitialisé.',
            });
            return;
        }

        if (operation === 'delete') {
            const confirmed = await openDialog({
                title: 'Supprimer ' + name + ' ?',
                message: 'Action définitive : le compte ' + player.email + ' et ses données associées seront supprimés.',
                confirmLabel: 'Supprimer définitivement',
                danger: true,
                fields: [{ name: 'confirm', label: 'Saisissez l’adresse du compte pour confirmer', placeholder: player.email, required: true }],
                validate: (values) => values.confirm === player.email
                    ? ''
                    : 'L’adresse saisie ne correspond pas à « ' + player.email + ' ».',
            });
            if (!confirmed) return;
            await runAction(button, 'admin_manage_user', { operation: 'delete', user_id: id }, {
                success: 'Compte ' + name + ' supprimé.',
            });
        }
    }

    /* ------------------------------------------------------------------ */
    /* Écouteurs                                                           */
    /* ------------------------------------------------------------------ */

    function bindEvents() {
        $('#admin-refresh')?.addEventListener('click', () => {
            refreshDashboard({ background: false });
        });
        $('#admin-ranking-game')?.addEventListener('change', refreshRankingScope);
        $('#admin-season-game')?.addEventListener('change', refreshSeasonScope);
        $('#social-player-search')?.addEventListener('input', (event) => {
            state.socialSearch = event.currentTarget.value || '';
            renderSocialData();
        });
        $('#social-settings')?.addEventListener('change', (event) => {
            const input = event.target.closest('[data-social-setting]');
            if (!input) return;
            const key = input.dataset.socialSetting;
            const value = input.type === 'checkbox' ? (input.checked ? 1 : 0) : input.value;
            updateSocial({ operation: 'setting', key: key, value: value }, 'Réglage social enregistré.');
        });
        $('#social-player-rows')?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-social-profile-action]');
            if (!button) return;
            const operation = button.dataset.socialProfileAction;
            const payload = { operation: 'profile', user_id: Number(button.dataset.userId) };
            if (operation === 'reset_bio') payload.reset_bio = true;
            else if (operation === 'reset_avatar') payload.reset_avatar = true;
            else if (operation === 'reset_username') payload.reset_username = true;
            else if (operation === 'force_private') payload.force_private = true;
            else payload.social_suspended = operation === 'suspend_social';
            updateSocial(payload, 'Profil social modéré.');
        });
        $('#social-report-rows')?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-report-id]');
            if (!button) return;
            updateSocial({
                operation: 'report',
                report_id: Number(button.dataset.reportId),
                status: button.dataset.reportStatus,
            }, button.dataset.reportStatus === 'sanctioned' ? 'Fonctions sociales suspendues et signalement traité.' : 'Signalement marqué comme traité.');
        });
        $('#social-achievement-rows')?.addEventListener('change', (event) => {
            const toggle = event.target.closest('[data-achievement-toggle]');
            if (!toggle) return;
            updateSocial({
                operation: 'achievement', key: toggle.dataset.achievementToggle, enabled: toggle.checked ? 1 : 0,
            }, 'Réglage du succès enregistré.');
        });
        $('#social-achievement-rows')?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-award-achievement]');
            if (!button) return;
            const key = button.dataset.awardAchievement;
            const select = $('[data-achievement-target="' + escapeSelector(key) + '"]');
            if (!select || !select.value) {
                toast('Choisissez un joueur avant d’attribuer ce succès.', 'error');
                return;
            }
            updateSocial({ operation: 'achievement', key: key, user_id: Number(select.value) }, 'Succès attribué.');
        });

        $('#space-knowledge-form')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const data = new FormData(form);
            const button = form.querySelector('button[type="submit"]');
            await runAction(button, 'admin_space_knowledge', {
                operation: 'save',
                keyword: String(data.get('keyword') || ''),
                content: String(data.get('content') || ''),
            }, {
                reload: false,
                success: 'La connaissance a été enregistrée pour Space.',
                after: (result) => {
                    state.spaceKnowledge = Array.isArray(result.knowledge) ? result.knowledge : [];
                    state.spaceUsage = Array.isArray(result.usage) ? result.usage : [];
                    renderSpaceKnowledge();
                    renderSpaceUsage();
                    form.reset();
                },
            });
        });

        $('#space-knowledge-rows')?.addEventListener('click', async (event) => {
            const button = event.target.closest('button[data-knowledge-delete]');
            if (!button) return;
            const confirmed = await openDialog({
                title: 'Supprimer cette connaissance ?',
                message: 'Space ne pourra plus utiliser cette référence ni cet exemple mémorisé.',
                confirmLabel: 'Supprimer',
                danger: true,
            });
            if (!confirmed) return;
            await runAction(button, 'admin_space_knowledge', {
                operation: 'delete',
                id: Number(button.dataset.knowledgeDelete),
            }, {
                reload: false,
                success: 'La connaissance a été supprimée.',
                after: (result) => {
                    state.spaceKnowledge = Array.isArray(result.knowledge) ? result.knowledge : [];
                    state.spaceUsage = Array.isArray(result.usage) ? result.usage : [];
                    renderSpaceKnowledge();
                    renderSpaceUsage();
                },
            });
        });

        const autoRefresh = $('#admin-autorefresh');
        if (autoRefresh) {
            try {
                const stored = window.localStorage.getItem('mg-admin-refresh');
                if (stored !== null && ['0', '10', '15', '30', '60'].indexOf(stored) !== -1) {
                    autoRefresh.value = stored;
                }
            } catch (error) {
                /* stockage local indisponible : on garde la valeur par défaut */
            }
            // La liste déroulante exprime des secondes : on convertit en millisecondes.
            state.refreshMs = (Number(autoRefresh.value) || 0) * 1000;
            autoRefresh.addEventListener('change', (event) => {
                state.refreshMs = (Number(event.currentTarget.value) || 0) * 1000;
                try {
                    window.localStorage.setItem('mg-admin-refresh', event.currentTarget.value);
                } catch (error) {
                    /* ignoré */
                }
                scheduleRefresh();
                toast(state.refreshMs
                    ? 'Actualisation automatique toutes les ' + (state.refreshMs / 1000) + ' s.'
                    : 'Actualisation automatique désactivée.', 'success');
            });
        }

        /* Filtres joueurs */
        const search = $('#player-search');
        if (search) {
            let debounce = null;
            search.addEventListener('input', (event) => {
                const value = event.currentTarget.value;
                window.clearTimeout(debounce);
                debounce = window.setTimeout(() => {
                    state.filters.search = value;
                    state.page = 1;
                    renderPlayers();
                }, 150);
            });
            search.addEventListener('search', (event) => {
                state.filters.search = event.currentTarget.value;
                state.page = 1;
                renderPlayers();
            });
        }

        $('#player-status')?.addEventListener('change', (event) => {
            state.filters.status = event.currentTarget.value;
            state.page = 1;
            renderPlayers();
        });

        $('#player-rank')?.addEventListener('change', (event) => {
            state.filters.rank = event.currentTarget.value;
            state.page = 1;
            renderPlayers();
        });

        $('#player-sort-preset')?.addEventListener('change', (event) => {
            const key = event.currentTarget.value;
            state.filters.sortKey = key;
            state.filters.sortDir = (key === 'display_name' || key === 'created_at') ? 'desc' : 'desc';
            state.page = 1;
            renderPlayers();
        });

        const pageSizeSelect = $('#player-pagesize');
        if (pageSizeSelect) {
            // On lit la valeur réellement sélectionnée dans le HTML plutôt que de la supposer.
            state.pageSize = Number(pageSizeSelect.value) || 0;
            pageSizeSelect.addEventListener('change', (event) => {
                state.pageSize = Number(event.currentTarget.value) || 0;
                state.page = 1;
                renderPlayers();
            });
        }

        $$('.admin-sort').forEach((button) => {
            button.addEventListener('click', () => {
                const key = button.dataset.sort;
                if (!key) return;
                if (state.filters.sortKey === key) {
                    state.filters.sortDir = state.filters.sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    state.filters.sortKey = key;
                    state.filters.sortDir = (key === 'display_name' || key === 'email') ? 'asc' : 'desc';
                }
                state.page = 1;
                renderPlayers();
            });
        });

        /* Sélection */
        $('#player-rows')?.addEventListener('change', (event) => {
            const checkbox = event.target.closest('input.player-select');
            if (!checkbox) return;
            const id = Number(checkbox.value);
            if (checkbox.checked) state.selection.add(id);
            else state.selection.delete(id);
            const row = checkbox.closest('tr');
            if (row) row.classList.toggle('is-selected', checkbox.checked);
            syncSelectionUi();
        });

        $('#player-check-all')?.addEventListener('change', (event) => {
            const ids = currentPageIds();
            ids.forEach((id) => {
                if (event.currentTarget.checked) state.selection.add(id);
                else state.selection.delete(id);
            });
            renderPlayers();
        });

        $('#player-select-all')?.addEventListener('click', () => {
            filteredPlayers().forEach((player) => state.selection.add(Number(player.id)));
            renderPlayers();
            toast(formatInt(state.selection.size) + ' compte(s) sélectionné(s).', 'success');
        });

        $('#player-bulk-clear')?.addEventListener('click', () => {
            state.selection.clear();
            renderPlayers();
        });

        $('#player-pager')?.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-page]');
            if (!button || button.disabled) return;
            state.page += button.dataset.page === 'next' ? 1 : -1;
            renderPlayers();
            const section = $('#admin-users-panel');
            if (section && typeof section.scrollIntoView === 'function') {
                section.scrollIntoView({ block: 'start', behavior: 'smooth' });
            }
        });

        $('#player-export')?.addEventListener('click', exportCsv);

        /* Actions sur une ligne joueur */
        $('#player-rows')?.addEventListener('click', async (event) => {
            const button = event.target.closest('button[data-operation]');
            if (!button) return;
            const id = Number(button.dataset.id);
            const player = state.players.find((item) => Number(item.id) === id);
            if (!player) return;
            await handlePlayerOperation(button, player, button.dataset.operation);
        });

        /* Actions groupées */
        $('#player-bulkbar')?.addEventListener('click', async (event) => {
            const button = event.target.closest('button[data-bulk]');
            if (!button) return;
            const operation = button.dataset.bulk;
            const ids = Array.from(state.selection);
            if (!ids.length) return;

            const labels = {
                ban: 'Bannir',
                unban: 'Débannir',
                reset_bot: 'Réinitialiser l’IA de',
                ai_block: 'Bloquer Space Core pour',
                ai_unblock: 'Débloquer Space Core pour',
                delete: 'Supprimer',
            };
            const destructive = operation === 'delete';
            const confirmed = await openDialog({
                title: (labels[operation] || operation) + ' ' + formatInt(ids.length) + ' compte(s) ?',
                message: destructive
                    ? 'Action définitive : les comptes sélectionnés et leurs données associées seront supprimés.'
                    : 'L’opération sera appliquée à tous les comptes sélectionnés.',
                confirmLabel: 'Confirmer',
                danger: destructive || operation === 'ban' || operation === 'ai_block',
                fields: destructive
                    ? [{ name: 'confirm', label: 'Saisissez SUPPRIMER pour confirmer', placeholder: 'SUPPRIMER', required: true }]
                    : [],
                validate: destructive
                    ? (values) => values.confirm === 'SUPPRIMER' ? '' : 'Saisissez exactement SUPPRIMER.'
                    : null,
            });
            if (!confirmed) return;

            const result = await runAction(button, 'admin_manage_user', {
                operation: 'bulk',
                bulk_operation: operation,
                ids: ids,
            }, {});
            if (!result) return;
            const skipped = Number(result.skipped) || 0;
            toast(formatInt(result.affected) + ' compte(s) traité(s)' +
                (skipped ? ', ' + formatInt(skipped) + ' ignoré(s)' : '') + '.', 'success');
            state.selection.clear();
            renderPlayers();
        });

        /* Thèmes */
        $('#theme-rows')?.addEventListener('click', async (event) => {
            const button = event.target.closest('button[data-operation="delete-theme"]');
            if (!button) return;
            const theme = state.themes.find((item) => Number(item.id) === Number(button.dataset.id));
            const confirmed = await openDialog({
                title: 'Supprimer le thème « ' + (theme ? theme.name : button.dataset.id) + ' » ?',
                message: 'Les joueurs qui utilisent ce thème personnalisé reviendront au thème par défaut.',
                confirmLabel: 'Supprimer',
                danger: true,
            });
            if (!confirmed) return;
            await runAction(button, 'admin_manage_theme', { operation: 'delete', id: Number(button.dataset.id) }, {
                success: 'Thème supprimé.',
            });
        });

        /* Symboles */
        $('#symbol-rows')?.addEventListener('click', async (event) => {
            const button = event.target.closest('button[data-operation="delete-symbol"]');
            if (!button) return;
            const symbol = state.symbols.find((item) => Number(item.id) === Number(button.dataset.id));
            const confirmed = await openDialog({
                title: 'Supprimer le symbole « ' + (symbol ? symbol.symbol : button.dataset.id) + ' » ?',
                message: 'Ce symbole ne sera plus proposé dans les options de partie.',
                confirmLabel: 'Supprimer',
                danger: true,
            });
            if (!confirmed) return;
            await runAction(button, 'admin_manage_symbol', { operation: 'delete', id: Number(button.dataset.id) }, {
                success: 'Symbole supprimé.',
            });
        });

        /* Salons */
        $('#room-rows')?.addEventListener('click', async (event) => {
            const button = event.target.closest('button[data-operation="delete-room"]');
            if (!button) return;
            const code = String(button.dataset.id);
            const confirmed = await openDialog({
                title: 'Supprimer le salon ' + code + ' ?',
                message: 'Les joueurs présents dans ce salon Wi-Fi perdront la partie en cours.',
                confirmLabel: 'Supprimer le salon',
                danger: true,
            });
            if (!confirmed) return;
            await runAction(button, 'admin_delete_room', { room_id: code }, {
                success: 'Salon ' + code + ' supprimé.',
            });
        });

        /* Journal des événements */
        $('#event-category')?.addEventListener('change', () => { refreshEvents({ quiet: true }); });
        $('#event-limit')?.addEventListener('change', () => { refreshEvents({ quiet: true }); });
        $('#event-refresh')?.addEventListener('click', (event) => { refreshEvents({ button: event.currentTarget }); });

        /* Interrupteur global de l'IA */
        $('#admin-ai-toggle')?.addEventListener('change', (event) => {
            const status = $('#admin-ai-status');
            if (!status) return;
            const enabled = event.currentTarget.checked;
            status.dataset.state = enabled ? 'on' : 'off';
            status.textContent = enabled
                ? 'Prêt à activer Space Core (confirmation demandée).'
                : 'Prêt à désactiver Space Core (confirmation demandée).';
        });

        $('#admin-ai-form')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const toggle = $('#admin-ai-toggle');
            const submit = $('#admin-ai-submit');
            if (!toggle) return;
            const enabled = !!toggle.checked;
            const confirmed = await openDialog({
                title: (enabled ? 'Activer' : 'Désactiver') + ' Space Core ?',
                message: enabled
                    ? 'L’assistant IA redevient disponible pour tous les joueurs.'
                    : 'L’assistant IA ne répondra plus à personne tant qu’il reste désactivé.',
                confirmLabel: enabled ? 'Activer' : 'Désactiver',
                danger: !enabled,
            });
            if (!confirmed) return;
            await runAction(submit, 'admin_update_setting', { key: 'ai_enabled', value: enabled ? 1 : 0 }, {
                success: 'Space Core ' + (enabled ? 'activé' : 'désactivé') + '.',
            });
        });

        /* Cataclysmes : saison classée */
        $('#admin-season-form')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const field = $('#admin-season-confirm');
            const submit = $('#admin-season-submit');
            if (!field || String(field.value || '').trim() !== 'SAISON') {
                toast('Saisissez exactement SAISON pour confirmer la réinitialisation.', 'error');
                if (field) field.focus();
                return;
            }
            const confirmed = await openDialog({
                title: 'Réinitialiser la saison classée de ' + String((($('#admin-season-game') || {}).selectedOptions || [])[0]?.textContent || 'ce jeu') + ' ?',
                message: 'Les points classés, pics et compteurs du jeu sélectionné repartent de zéro. '
                    + 'Les saisons des autres jeux ne sont pas modifiées.',
                confirmLabel: 'Lancer une nouvelle saison',
                danger: true,
            });
            if (!confirmed) return;
            const gameId = ($('#admin-season-game') || {}).value || 'morpion';
            const result = await runAction(submit, 'admin_reset_season', { game_id: gameId }, { reload: false });
            if (result) {
                field.value = '';
                toast('Saison ' + formatInt(result.season) + ' lancée (' + formatInt(result.affected) + ' compte(s) réinitialisé(s)).', 'success');
                await refreshDashboard({ background: false });
                await refreshSeasonScope();
            }
        });

        /* Cataclysmes : maintenance */
        $('#admin-maintenance-form')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const toggle = $('#admin-maintenance-toggle');
            const submit = $('#admin-maintenance-submit');
            if (!toggle) return;
            const enabled = !!toggle.checked;
            const confirmed = await openDialog({
                title: (enabled ? 'Activer' : 'Désactiver') + ' le mode maintenance ?',
                message: enabled
                    ? 'Le site public sera fermé aux joueurs : seuls les administrateurs gardent l’accès.'
                    : 'Le site redeviendra immédiatement accessible à tous les joueurs.',
                confirmLabel: enabled ? 'Activer la maintenance' : 'Rouvrir le site',
                danger: enabled,
            });
            if (!confirmed) return;
            await runAction(submit, 'admin_update_setting', { key: 'maintenance', value: enabled ? 1 : 0 }, {
                success: enabled ? 'Mode maintenance activé.' : 'Site rouvert à tous.',
            });
        });

        $('#admin-maintenance-toggle')?.addEventListener('change', (event) => {
            const status = $('#admin-maintenance-status');
            if (!status) return;
            status.dataset.state = event.currentTarget.checked ? 'off' : 'on';
            status.textContent = event.currentTarget.checked
                ? 'Prêt à fermer le site (confirmation demandée).'
                : 'Prêt à rouvrir le site (confirmation demandée).';
        });

        $('#admin-maintenance-message')?.addEventListener('click', async (event) => {
            const current = String((state.settings || {}).maintenance_message || '');
            const values = await openDialog({
                title: 'Message de maintenance',
                message: 'Ce texte est affiché aux joueurs lorsque le site est en maintenance (240 caractères maximum).',
                confirmLabel: 'Enregistrer le message',
                fields: [{
                    name: 'message',
                    label: 'Message affiché',
                    type: 'textarea',
                    value: current,
                    maxLength: 240,
                    placeholder: 'Multi-Games revient très vite…',
                }],
            });
            if (!values) return;
            await runAction(event.currentTarget, 'admin_update_setting', {
                key: 'maintenance_message',
                value: values.message,
            }, { success: 'Message de maintenance enregistré.' });
        });

        /* Cataclysmes : purge du journal */
        $('#admin-purge-form')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const field = $('#admin-purge-confirm');
            const submit = $('#admin-purge-submit');
            if (!field || String(field.value || '').trim() !== 'PURGER') {
                toast('Saisissez exactement PURGER pour confirmer la purge.', 'error');
                if (field) field.focus();
                return;
            }
            const confirmed = await openDialog({
                title: 'Purger le journal des événements ?',
                message: 'Toutes les entrées du journal seront supprimées définitivement. La purge elle-même sera journalisée.',
                confirmLabel: 'Purger le journal',
                danger: true,
            });
            if (!confirmed) return;
            const result = await runAction(submit, 'admin_clear_events', {}, { reload: false });
            if (result) {
                field.value = '';
                toast(formatInt(result.removed) + ' entrée(s) supprimée(s).', 'success');
                state.signatures.events = '';
                await refreshEvents({ quiet: true });
                await refreshDashboard({ background: true });
            }
        });

        /* Formulaire thème */
        $('#admin-theme-form')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const submit = form.querySelector('button[type="submit"]');
            const values = Object.fromEntries(new FormData(form));
            const name = String(values.name || '').trim();
            const key = String(values.key || '').trim().toLowerCase();

            if (name.length < 2 || name.length > 30) {
                toast('Le nom du thème doit contenir entre 2 et 30 caractères.', 'error');
                return;
            }
            if (!/^[a-z][a-z0-9-]{1,24}$/.test(key)) {
                toast('Identifiant invalide : minuscules, chiffres et tirets, en commençant par une lettre.', 'error');
                return;
            }
            const result = await runAction(submit, 'admin_manage_theme', Object.assign({}, values, {
                operation: 'add',
                name: name,
                key: key,
            }), {});
            if (result) {
                form.reset();
                toast('Thème « ' + name + ' » ajouté.', 'success');
            }
        });

        /* Formulaire symbole */
        $('#admin-symbol-form')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const submit = form.querySelector('button[type="submit"]');
            const values = Object.fromEntries(new FormData(form));
            const symbol = String(values.symbol || '').trim();
            const label = String(values.label || '').trim();

            if (symbol === '' || label === '') {
                toast('Le symbole et son nom sont obligatoires.', 'error');
                return;
            }
            const result = await runAction(submit, 'admin_manage_symbol', Object.assign({}, values, {
                operation: 'add',
                symbol: symbol,
                label: label,
            }), {});
            if (result) {
                form.reset();
                toast('Symbole « ' + symbol + ' » ajouté.', 'success');
            }
        });

        /* Raccourcis clavier */
        document.addEventListener('keydown', (event) => {
            if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) return;
            if (isDialogOpen()) return;
            const target = event.target;
            const typing = !!target && (
                target.tagName === 'INPUT' ||
                target.tagName === 'TEXTAREA' ||
                target.tagName === 'SELECT' ||
                target.isContentEditable
            );

            if (event.key === '/' && !typing) {
                event.preventDefault();
                tabs.activate('global');
                const field = $('#player-search');
                if (field) {
                    field.focus();
                    if (typeof field.select === 'function') field.select();
                }
                return;
            }
            if ((event.key === 'r' || event.key === 'R') && !typing) {
                event.preventDefault();
                refreshDashboard({ background: false });
                return;
            }
            if (event.key === 'Escape' && !typing && state.selection.size) {
                state.selection.clear();
                renderPlayers();
            }
        });

        /* Onglet masqué : on suspend le sondage réseau. */
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                window.clearTimeout(pollTimer);
                updateLiveLabel();
                return;
            }
            refreshDashboard({ background: true });
            scheduleRefresh();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Export CSV (respecte les filtres actifs)                            */
    /* ------------------------------------------------------------------ */

    function csvCell(value) {
        const text = value === null || value === undefined ? '' : String(value);
        return /[";\r\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
    }

    function exportCsv() {
        const list = filteredPlayers();
        if (!list.length) {
            toast('Aucun joueur à exporter avec ces filtres.', 'error');
            return;
        }
        const lines = [[
            'id', 'statut', 'pseudo', 'email', 'points_classes', 'rang', 'niveau', 'xp',
            'parties', 'niveau_appris', 'parties_apprises', 'space_core', 'inscription', 'derniere_activite',
        ].join(';')];
        list.forEach((player) => {
            lines.push([
                player.id,
                Number(player.is_banned) ? 'Banni' : (isOnline(player) ? 'En ligne' : 'Hors ligne'),
                player.display_name,
                player.email,
                player.ranked_points,
                rankBadge(player),
                player.level,
                player.xp,
                player.games,
                player.learning_level,
                player.games_learned,
                Number(player.ai_blocked) ? 'Bloqué' : 'Autorisé',
                new Date(Number(player.created_at) * 1000).toISOString().slice(0, 10),
                new Date(Number(player.last_seen_at) * 1000).toISOString().slice(0, 19).replace('T', ' '),
            ].map(csvCell).join(';'));
        });

        const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = el('a', {
            href: url,
            download: 'joueurs-multi-games-' + new Date().toISOString().slice(0, 10) + '.csv',
        });
        document.body.append(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
        toast(formatInt(list.length) + ' joueur(s) exporté(s) au format CSV.', 'success');
    }

    /* ------------------------------------------------------------------ */
    /* Planification de l'actualisation                                    */
    /* ------------------------------------------------------------------ */

    let pollTimer = null;
    let tickTimer = null;

    function scheduleRefresh() {
        window.clearTimeout(pollTimer);
        if (!state.refreshMs || document.hidden) {
            state.nextRefreshAt = 0;
            updateLiveLabel();
            return;
        }
        state.nextRefreshAt = Date.now() + state.refreshMs;
        pollTimer = window.setTimeout(async () => {
            if (isDialogOpen()) {
                // Une saisie est en cours : on reporte sans toucher à l'écran.
                scheduleRefresh();
                return;
            }
            await refreshDashboard({ background: true });
            if (tabs.active === 'morpion') requestMorpionStats();
            const select = $('#event-category');
            if (select && (select.value || 'all') !== 'all') refreshEvents({ quiet: true });
            if (state.lastRefresh) refreshRelativeTimes();
            scheduleRefresh();
        }, state.refreshMs);
        updateLiveLabel();
    }

    function startTicker() {
        window.clearInterval(tickTimer);
        tickTimer = window.setInterval(() => {
            if (document.hidden) return;
            updateLiveLabel();
            refreshRelativeTimes();
        }, 1000);
    }

    /* ------------------------------------------------------------------ */
    /* Démarrage                                                           */
    /* ------------------------------------------------------------------ */

    function init() {
        // Premier rendu à partir des données déjà injectées par le serveur :
        // l'écran est complet avant même la première requête réseau.
        applySection('summary', state.summary, renderSummary);
        applySection('botLevels', state.botLevels, renderBotLevels);
        applySection('popularModes', state.popularModes, renderPopularModes);
        applySection('players', state.players, renderPlayers);
        applySection('themes', state.themes, renderThemes);
        applySection('symbols', state.symbols, renderSymbols);
        applySection('rooms', state.rooms, renderRooms);
        applySection('topPlayer', state.topPlayer, renderTopPlayer);
        applySection('rankDistribution', state.rankDistribution, renderRankDistribution);
        applySection('livePlayers', state.livePlayers, renderLivePlayers);
        renderMorpionCards();

        bindTabs();
        bindEvents();
        startTicker();
        setLive('idle');
        refreshDashboard({ background: true });
        scheduleRefresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
