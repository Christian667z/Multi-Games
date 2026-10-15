(function() {
    const refreshButton = document.getElementById('admin-refresh');
    const errorMessage = document.createElement('p');
    errorMessage.className = 'account-alert';
    errorMessage.setAttribute('role', 'alert');
    errorMessage.hidden = true;
    refreshButton?.insertAdjacentElement('afterend', errorMessage);

    function addCell(row, value) {
        const cell = document.createElement('td');
        cell.textContent = String(value ?? '');
        row.appendChild(cell);
        return cell;
    }

    function addButton(cell, label, operation, id) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'admin-action-button';
        button.textContent = label;
        button.dataset.operation = operation;
        button.dataset.id = String(id);
        cell.appendChild(button);
    }

    async function adminPost(action, values) {
        const response = await fetch(window.MG_ADMIN_API, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.MG_CSRF || ''
            },
            body: JSON.stringify({ action, ...values })
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || `Opération impossible (${response.status}).`);
        }
        return data;
    }

    function showError(error) {
        errorMessage.textContent = error.message || String(error);
        errorMessage.hidden = false;
    }

    function renderBotLevels(levels) {
        const body = document.getElementById('bot-level-rows');
        body.replaceChildren();
        if (!levels.length) {
            const row = body.insertRow();
            const cell = addCell(row, 'Aucune partie contre le bot pour le moment.');
            cell.colSpan = 5;
            cell.className = 'admin-empty';
            return;
        }

        function renderPopularModes(modes) {
            const body = document.getElementById('popular-mode-rows');
            body.replaceChildren();
            if (!modes.length) {
                const row = body.insertRow();
                const cell = addCell(row, 'Aucune partie enregistrée.');
                cell.colSpan = 2;
                cell.className = 'admin-empty';
                return;
            }
            const names = { local: 'Hors ligne', bot: 'Contre Space', wifi: 'Wi-Fi' };
            modes.forEach(mode => {
                const row = body.insertRow();
                addCell(row, names[mode.mode] || mode.mode);
                addCell(row, mode.games);
            });
        }

        levels.forEach(level => {
            const row = body.insertRow();
            addCell(row, level.bot_level || 'non précisé');
            addCell(row, level.games);
            addCell(row, level.bot_wins);
            addCell(row, level.player_wins);
            addCell(row, level.ties);
        });
    }

    function renderPlayers(players) {
        const body = document.getElementById('player-rows');
        body.replaceChildren();
        if (!players.length) {
            const row = body.insertRow();
            const cell = addCell(row, 'Aucun joueur inscrit.');
            cell.colSpan = 8;
            cell.className = 'admin-empty';
            return;
        }

        players.forEach(player => {
            const row = body.insertRow();
            const online = Number(player.last_seen_at) >= Math.floor(Date.now() / 1000) - 90;
            const status = addCell(row, Number(player.is_banned) ? 'Banni' : (online ? 'En ligne' : 'Hors ligne'));
            if (!Number(player.is_banned)) {
                const dot = document.createElement('span');
                dot.className = `presence-dot${online ? ' is-online' : ''}`;
                status.prepend(dot);
            }
            addCell(row, player.display_name);
            addCell(row, player.email);
            addCell(row, player.games);
            addCell(row, player.learning_level);
            addCell(row, player.games_learned);
            addCell(row, new Date(Number(player.created_at) * 1000).toLocaleDateString('fr-FR'));
            const actions = row.insertCell();
            addButton(actions, 'Modifier', 'edit', player.id);
            addButton(actions, Number(player.is_banned) ? 'Débannir' : 'Bannir', Number(player.is_banned) ? 'unban' : 'ban', player.id);
            addButton(actions, 'Supprimer', 'delete', player.id);
        });
    }

    function renderThemes(themes) {
        const body = document.getElementById('theme-rows');
        body.replaceChildren();
        themes.forEach(theme => {
            const row = body.insertRow();
            addCell(row, theme.name);
            addCell(row, theme.key);
            addCell(row, `${theme.primary_color} / ${theme.background_color}`);
            addCell(row, Number(theme.custom) ? 'Personnalisé' : 'Intégré');
            const actions = row.insertCell();
            if (Number(theme.custom)) addButton(actions, 'Supprimer', 'delete-theme', theme.id);
            else actions.textContent = '—';
        });
    }

    function renderSymbols(symbols) {
        const body = document.getElementById('symbol-rows');
        body.replaceChildren();
        symbols.forEach(symbol => {
            const row = body.insertRow();
            addCell(row, symbol.symbol);
            addCell(row, symbol.label);
            addCell(row, Number(symbol.custom) ? 'Personnalisé' : 'Intégré');
            const actions = row.insertCell();
            if (Number(symbol.custom)) addButton(actions, 'Supprimer', 'delete-symbol', symbol.id);
            else actions.textContent = '—';
        });
    }

    function renderRooms(rooms) {
        const body = document.getElementById('room-rows');
        body.replaceChildren();
        if (!rooms.length) {
            const row = body.insertRow();
            const cell = addCell(row, 'Aucun salon actif.');
            cell.colSpan = 5;
            cell.className = 'admin-empty';
            return;
        }
        rooms.forEach(room => {
            const row = body.insertRow();
            addCell(row, room.id);
            addCell(row, room.status);
            addCell(row, room.players);
            addCell(row, room.last_seen ? new Date(Number(room.last_seen) * 1000).toLocaleString('fr-FR') : '—');
            addButton(row.insertCell(), 'Supprimer', 'delete-room', room.id);
        });
    }

    async function refreshDashboard() {
        if (refreshButton) refreshButton.disabled = true;
        errorMessage.hidden = true;
        try {
            const [statsResponse, roomsResponse] = await Promise.all([
                fetch(`${window.MG_ADMIN_API}?action=admin_stats`, { cache: 'no-store' }),
                fetch(`${window.MG_ADMIN_API}?action=admin_rooms`, { cache: 'no-store' })
            ]);
            const [data, roomData] = await Promise.all([statsResponse.json(), roomsResponse.json()]);
            if (!statsResponse.ok || !data.success) {
                throw new Error(data.error || `Actualisation impossible (${statsResponse.status}).`);
            }
            if (!roomsResponse.ok || !roomData.success) {
                throw new Error(roomData.error || `Chargement des salons impossible (${roomsResponse.status}).`);
            }
            Object.entries(data.summary).forEach(([key, value]) => {
                const target = document.querySelector(`[data-stat="${key}"]`);
                if (target) target.textContent = String(value);
            });
            renderBotLevels(data.bot_levels);
            renderPopularModes(data.popular_modes);
            renderPlayers(data.players);
            renderThemes(data.themes);
            renderSymbols(data.symbols);
            renderRooms(roomData.rooms);
        } catch (error) {
            showError(error);
        } finally {
            if (refreshButton) refreshButton.disabled = false;
        }
    }

    document.getElementById('player-rows')?.addEventListener('click', async event => {
        const button = event.target.closest('button[data-operation]');
        if (!button) return;
        const player = button.closest('tr');
        try {
            const operation = button.dataset.operation;
            const values = { user_id: Number(button.dataset.id), operation };
            if (operation === 'edit') {
                const display_name = window.prompt('Nouveau pseudo :', player.cells[1].textContent);
                if (display_name === null) return;
                const email = window.prompt('Nouvel e-mail :', player.cells[2].textContent);
                if (email === null) return;
                values.display_name = display_name;
                values.email = email;
            } else if (operation === 'delete' && !window.confirm('Supprimer définitivement ce compte et ses données ?')) {
                return;
            }
            await adminPost('admin_manage_user', values);
            await refreshDashboard();
        } catch (error) {
            showError(error);
        }
    });

    document.getElementById('theme-rows')?.addEventListener('click', async event => {
        const button = event.target.closest('button[data-operation="delete-theme"]');
        if (!button || !window.confirm('Supprimer ce thème personnalisé ?')) return;
        try {
            await adminPost('admin_manage_theme', { operation: 'delete', id: Number(button.dataset.id) });
            await refreshDashboard();
        } catch (error) {
            showError(error);
        }
    });

    document.getElementById('symbol-rows')?.addEventListener('click', async event => {
        const button = event.target.closest('button[data-operation="delete-symbol"]');
        if (!button || !window.confirm('Supprimer ce symbole personnalisé ?')) return;
        try {
            await adminPost('admin_manage_symbol', { operation: 'delete', id: Number(button.dataset.id) });
            await refreshDashboard();
        } catch (error) {
            showError(error);
        }
    });

    document.getElementById('room-rows')?.addEventListener('click', async event => {
        const button = event.target.closest('button[data-operation="delete-room"]');
        if (!button || !window.confirm(`Supprimer le salon ${button.dataset.id} ?`)) return;
        try {
            await adminPost('admin_delete_room', { room_id: button.dataset.id });
            await refreshDashboard();
        } catch (error) {
            showError(error);
        }
    });

    document.getElementById('admin-theme-form')?.addEventListener('submit', async event => {
        event.preventDefault();
        const values = Object.fromEntries(new FormData(event.currentTarget));
        try {
            await adminPost('admin_manage_theme', { operation: 'add', ...values });
            event.currentTarget.reset();
            await refreshDashboard();
        } catch (error) {
            showError(error);
        }
    });

    document.getElementById('admin-symbol-form')?.addEventListener('submit', async event => {
        event.preventDefault();
        const values = Object.fromEntries(new FormData(event.currentTarget));
        try {
            await adminPost('admin_manage_symbol', { operation: 'add', ...values });
            event.currentTarget.reset();
            await refreshDashboard();
        } catch (error) {
            showError(error);
        }
    });

    refreshButton?.addEventListener('click', refreshDashboard);
    refreshDashboard();
    window.setInterval(refreshDashboard, 15000);
})();
