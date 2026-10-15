/**
 * Multi-Games - Live Reload Client pour XAMPP
 * Détecte les modifications de fichiers PHP, CSS et JS et recharge la page automatiquement.
 */
(function() {
    // Ne s'exécute que sur localhost ou 127.0.0.1
    const isLocalhost = ['localhost', '127.0.0.1'].includes(window.location.hostname) || window.location.hostname.startsWith('192.168.') || window.location.hostname.startsWith('172.');
    if (!isLocalhost) return;

    let initialTimestamp = null;
    let isPaused = false;
    let pollInterval = 1000;
    let badgeEl = null;

    // Déterminer le chemin relatif vers dev/livereload.php
    function getEndpointUrl() {
        const path = window.location.pathname;
        if (path.includes('/games/')) {
            return '../../dev/livereload.php';
        }
        return 'dev/livereload.php';
    }

    function createBadge() {
        if (document.getElementById('livereload-badge')) return;
        badgeEl = document.createElement('div');
        badgeEl.id = 'livereload-badge';
        badgeEl.innerHTML = `
            <span class="lr-dot"></span>
            <span class="lr-text">Auto-Refresh XAMPP</span>
            <button class="lr-toggle" title="Pause / Reprendre le rechargement">⏸</button>
        `;
        badgeEl.style.cssText = `
            position: fixed;
            bottom: 12px;
            right: 12px;
            z-index: 99999;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #94a3b8;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 11px;
            font-weight: 500;
            padding: 5px 10px;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.4);
            user-select: none;
            cursor: pointer;
            transition: all 0.2s ease;
        `;

        const dot = badgeEl.querySelector('.lr-dot');
        dot.style.cssText = `
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #10b981;
            display: inline-block;
        `;

        const btn = badgeEl.querySelector('.lr-toggle');
        btn.style.cssText = `
            background: none;
            border: none;
            color: #cbd5e1;
            cursor: pointer;
            font-size: 10px;
            padding: 0 2px;
        `;

        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            isPaused = !isPaused;
            dot.style.background = isPaused ? '#f59e0b' : '#10b981';
            badgeEl.querySelector('.lr-text').textContent = isPaused ? 'Auto-Refresh (En pause)' : 'Auto-Refresh XAMPP';
            btn.textContent = isPaused ? '▶' : '⏸';
        });

        document.body.appendChild(badgeEl);
    }

    async function checkChanges() {
        if (isPaused) {
            setTimeout(checkChanges, pollInterval);
            return;
        }

        try {
            const res = await fetch(getEndpointUrl() + '?t=' + Date.now(), { cache: 'no-store' });
            if (res.ok) {
                const data = await res.json();
                if (initialTimestamp === null) {
                    initialTimestamp = data.timestamp;
                } else if (data.timestamp > initialTimestamp) {
                    console.log('⚡ Modification détectée sur :', data.latestFile, '→ Rechargement...');
                    if (badgeEl) {
                        badgeEl.querySelector('.lr-text').textContent = 'Rechargement...';
                    }
                    window.location.reload();
                    return;
                }
            }
        } catch (e) {
            // Silencieux si serveur momentanément occupé
        }

        setTimeout(checkChanges, pollInterval);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            createBadge();
            checkChanges();
        });
    } else {
        createBadge();
        checkChanges();
    }
})();
