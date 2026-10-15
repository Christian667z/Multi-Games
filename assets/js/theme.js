/**
 * Multi-Games - Gestionnaire de Thèmes
 * Sauvegarde la préférence dans localStorage et dans les cookies,
 * et applique dynamiquement les variables CSS et attributs data-theme.
 */
(function() {
    const THEMES = ['red', 'amber', 'purple', 'cyan', 'emerald'];
    const DEFAULT_THEME = 'red';

    function getCookie(name) {
        try {
            const match = document.cookie.match(new RegExp('(^|;\\s*)' + name + '=([^;]*)'));
            return match ? decodeURIComponent(match[2]) : null;
        } catch (e) {
            return null;
        }
    }

    function getSavedTheme() {
        const rootTheme = document.documentElement.getAttribute('data-theme');
        const fromLocal = localStorage.getItem('mg_theme');
        const fromCookie = getCookie('mg_theme');
        const chosen = fromLocal || fromCookie || rootTheme || DEFAULT_THEME;
        return /^[a-z0-9_-]{1,30}$/.test(chosen) ? chosen : DEFAULT_THEME;
    }

    function availableThemes() {
        const options = Array.from(document.querySelectorAll('.theme-selector-select option, #themeSelect option'))
            .map(opt => opt.value)
            .filter((val, i, self) => /^[a-z0-9_-]{1,30}$/.test(val) && self.indexOf(val) === i);
        return options.length > 0 ? options : THEMES;
    }

    function applyTheme(theme) {
        if (!theme || !/^[a-z0-9_-]{1,30}$/.test(theme)) {
            theme = DEFAULT_THEME;
        }

        document.documentElement.setAttribute('data-theme', theme);

        try {
            localStorage.setItem('mg_theme', theme);
            document.cookie = `mg_theme=${encodeURIComponent(theme)};path=/;max-age=31536000;SameSite=Lax`;
        } catch (e) {}

        // Synchroniser tous les sélecteurs de thème sur la page
        document.querySelectorAll('.theme-selector-select, #themeSelect').forEach(sel => {
            if (sel && sel.value !== theme) {
                sel.value = theme;
            }
        });

        // Mettre à jour l'indicateur visuel de la palette
        const paletteBtn = document.getElementById('theme-palette-btn');
        if (paletteBtn) {
            paletteBtn.setAttribute('data-active-theme', theme);
        }
    }

    // Appliquer immédiatement dès l'exécution du script (dans <head>)
    const initialTheme = getSavedTheme();
    applyTheme(initialTheme);

    window.getSavedTheme = getSavedTheme;
    window.setSpecificTheme = function(theme) {
        applyTheme(theme);
    };

    window.cycleTheme = function() {
        const themes = [...new Set([...THEMES, ...availableThemes()])];
        const current = getSavedTheme();
        const nextIndex = (themes.indexOf(current) + 1) % themes.length;
        const nextTheme = themes[nextIndex];
        applyTheme(nextTheme);
        return nextTheme;
    };

    document.addEventListener('DOMContentLoaded', () => {
        const activeTheme = getSavedTheme();
        applyTheme(activeTheme);

        document.querySelectorAll('.theme-selector-select, #themeSelect').forEach(select => {
            select.value = activeTheme;
            select.addEventListener('change', (e) => {
                applyTheme(e.target.value);
            });
        });

        const paletteBtn = document.getElementById('theme-palette-btn');
        if (paletteBtn) {
            paletteBtn.addEventListener('click', (e) => {
                e.preventDefault();
                window.cycleTheme();
            });
        }
    });
})();
